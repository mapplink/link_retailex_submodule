<?php
/**
 * Order gateway for Retail Express
 * @category Retailex
 * @package Retailex\Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Retailex\Gateway;

use Entity\Comment;
use Entity\Service\EntityService;
use Entity\Wrapper\Order;
use Entity\Wrapper\Orderitem;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\NodeException;
use Magelink\Exception\GatewayException;
use Node\AbstractNode;
use Zend\Stdlib\ArrayObject;


class OrderGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'order';
    const GATEWAY_ENTITY_CODE = 'o';


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws MagelinkException
     * @throws GatewayException
     */
    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'order') {
            throw new GatewayException('Invalid entity type for this gateway');
            $success = FALSE;
        }else{
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUG, 'rex_o_init', 'Initialised Retailex order gateway.', array());
        }

        return $success;
    }

    /**
     * Get last retrieve date from the database
     * @return bool|string
     */
    protected function getRetrieveDateForForcedSynchronisation()
    {
        if ($this->newRetrieveTimestamp !== NULL) {
            $retrieveInterval = $this->newRetrieveTimestamp - $this->getLastRetrieveTimestamp();
            $intervalsBefore = 2.4 - min(1.2, max(0, $retrieveInterval / 3600));
            $retrieveTimestamp = intval($this->getLastRetrieveTimestamp()
                - min($retrieveInterval * $intervalsBefore, $retrieveInterval + 3600));
            $date = $this->convertTimestampToRetailexDateFormat($retrieveTimestamp);
        }else{
            $date = FALSE;
        }

        return $date;
    }

    /**
     * TECHNICAL DEBT // ToDo: Implement this method
     */
    public function retrieve()
    {
        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_INFO, 'rex_o_re_no', 'Order retrieval not implemented yet.', array());
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        // ToDo (unlikely): Create method. (We don't perform any direct updates to orders in this manner).
        return;
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @return bool $success
     * @throws GatewayException
     * @throws MagelinkException
     * @throws NodeException
     */
    public function writeAction(\Entity\Action $action)
    {
        /** @var \Entity\Wrapper\Order $order */
        $order = $action->getEntity();
        // Reload order because entity might have changed in the meantime
        $order = $this->_entityService->reloadEntity($order);
        $orderStatus = $order->getData('status');

        $success = TRUE;
        switch ($action->getType()) {
            case 'comment':
                $status = ($action->hasData('status') ? $action->getData('status') : $orderStatus);
                $comment = $action->getData('comment');
                if ($comment == NULL && $action->getData('body')) {
                    if ($action->getData('title') != NULL) {
                        $comment = $action->getData('title').' - ';
                    }else{
                        $comment = '';
                    }
                    if($action->hasData('comment_id')){
                        $comment .= '{'.$action->getData('comment_id').'} ';
                    }
                    $comment .= $action->getData('body');
                }

                if ($action->hasData('customer_visible')) {
                    $notify = $action->getData('customer_visible') ? 'true' : 'false';
                }else{
                    $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : NULL);
                }

                try {
                    $this->soap->call('salesOrderAddComment', array(
                            $order->getOriginalOrder()->getUniqueId(),
                            $status,
                            $comment,
                            $notify
                        ));
                }catch (\Exception $exception) {
                    // store as sync issue
                    throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                }
                break;
            case 'cancel':
                $isCancelable = self::hasOrderStatePending($orderStatus);
                if ($orderStatus !== self::MAGENTO_STATUS_CANCELED) {
                    if (!$isCancelable){
                        $message = 'Attempted to cancel non-pending order '.$order->getUniqueId().' ('.$orderStatus.')';
                        // store as a sync issue
                        throw new GatewayException($message);
                        $success = FALSE;
                    }elseif ($order->isSegregated()) {
                        // store as a sync issue
                        throw new GatewayException('Attempted to cancel child order '.$order->getUniqueId().' !');
                        $success = FALSE;
                    }else{
                        try {
                            $this->soap->call('salesOrderCancel', $order->getUniqueId());

                            // Update status straight away
                            $changedOrder = $this->soap->call('salesOrderInfo', array($order->getUniqueId()));
                            if (isset($changedOrder['result'])) {
                                $changedOrder = $changedOrder['result'];
                            }

                            $newStatus = $changedOrder['status'];
                            $changedOrderData = array('status'=>$newStatus);
                            $this->_entityService->updateEntity(
                                $this->_node->getNodeId(),
                                $order,
                                $changedOrderData,
                                FALSE
                            );
                            $changedOrderData['status_history'] = array(array(
                                'comment'=>'HOPS updated status from Retailex after abandoning order to '.$newStatus.'.',
                                'created_at'=>date('Y/m/d H:i:s')
                            ));
                            $this->updateStatusHistory($changedOrderData, $order);
                        }catch (\Exception $exception) {
                            // store as sync issue
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                        }
                    }
                }
                break;
            case 'hold':
                if ($order->isSegregated()) {
                    // Is that really necessary to throw an exception?
                    throw new GatewayException('Attempted to hold child order!');
                    $success = FALSE;
                }else{
                    try {
                        $this->soap->call('salesOrderHold', $order->getUniqueId());
                    }catch (\Exception $exception) {
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }
                }
                break;
            case 'unhold':
                if ($order->isSegregated()) {
                    // Is that really necessary to throw an exception?
                    throw new GatewayException('Attempted to unhold child order!');
                    $success = FALSE;
                }else{
                    try {
                       $this->soap->call('salesOrderUnhold', $order->getUniqueId());
                    }catch (\Exception $exception) {
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }
                }
                break;
            case 'ship':
                if (self::hasOrderStateProcessing($orderStatus)) {
                    $comment = ($action->hasData('comment') ? $action->getData('comment') : NULL);
                    $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : NULL);
                    $sendComment = ($action->hasData('send_comment') ?
                        ($action->getData('send_comment') ? 'true' : 'false' ) : NULL);
                    $itemsShipped = ($action->hasData('items') ? $action->getData('items') : NULL);
                    $trackingCode = ($action->hasData('tracking_code') ? $action->getData('tracking_code') : NULL);

                    $this->actionShip($order, $comment, $notify, $sendComment, $itemsShipped, $trackingCode);
                }else{
                    $message = 'Invalid order status for shipment: '
                        .$order->getUniqueId().' has '.$order->getData('status');
                    // Is that really necessary to throw an exception?
                    throw new GatewayException($message);
                    $success = FALSE;
                }
                break;
            case 'creditmemo':
                if (self::hasOrderStateProcessing($orderStatus) || $orderStatus == self::MAGENTO_STATUS_COMPLETE) {
                    $comment = ($action->hasData('comment') ? $action->getData('comment') : NULL);
                    $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : NULL);
                    $sendComment = ($action->hasData('send_comment') ?
                        ($action->getData('send_comment') ? 'true' : 'false' ) : NULL);
                    $itemsRefunded = ($action->hasData('items') ? $action->getData('items') : NULL);
                    $shippingRefund = ($action->hasData('shipping_refund') ? $action->getData('shipping_refund') : 0);
                    $creditRefund = ($action->hasData('credit_refund') ? $action->getData('credit_refund') : 0);
                    $adjustmentPositive =
                        ($action->hasData('adjustment_positive') ? $action->getData('adjustment_positive') : 0);
                    $adjustmentNegative =
                        ($action->hasData('adjustment_negative') ? $action->getData('adjustment_negative') : 0);

                    $message = 'Retailex, create creditmemo: Passing values orderIncrementId '.$order->getUniqueId()
                        .'creditmemoData: [qtys=>'.var_export($itemsRefunded, TRUE).', shipping_amount=>'.$shippingRefund
                        .', adjustment_positive=>'.$adjustmentPositive.', adjustment_negative=>'.$adjustmentNegative
                        .'], comment '.$comment.', notifyCustomer '.$notify.', includeComment '.$sendComment
                        .', refundToStoreCreditAmount '.$creditRefund.'.';
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_DEBUGEXTRA,
                            'rex_o_wr_cr_cm',
                            $message,
                            array(
                                'entity (order)'=>$order,
                                'action'=>$action,
                                'action data'=>$action->getData(),
                                'orderIncrementId'=>$order->getUniqueId(),
                                'creditmemoData'=>array(
                                    'qtys'=>$itemsRefunded,
                                    'shipping_amount'=>$shippingRefund,
                                    'adjustment_positive'=>$adjustmentPositive,
                                    'adjustment_negative'=>$adjustmentNegative
                                ),
                                'comment'=>$comment,
                                'notifyCustomer'=>$notify,
                                'includeComment'=>$sendComment,
                                'refundToStoreCreditAmount'=>$creditRefund
                            )
                        );
                    $this->actionCreditmemo($order, $comment, $notify, $sendComment,
                        $itemsRefunded, $shippingRefund, $creditRefund, $adjustmentPositive, $adjustmentNegative);
                }else{
                    $message = 'Invalid order status for creditmemo: '.$order->getUniqueId().' has '.$orderStatus;
                    // store as a sync issue
                    throw new GatewayException($message);
                    $success = FALSE;
            }
                break;
            default:
                // store as a sync issue
                throw new GatewayException('Unsupported action type '.$action->getType().' for Retailex Orders.');
                $success = FALSE;
        }

        return $success;
    }

    /**
     * Preprocesses order items array (key=orderitem entity id, value=quantity) into an array suitable for Retailex
     * (local item ID=>quantity), while also auto-populating if not specified.
     * @param Order $order
     * @param array|NULL $rawItems
     * @return array
     * @throws GatewayException
     */
    protected function preprocessRequestItems(Order $order, $rawItems = NULL)
    {
        $items = array();
        if($rawItems == null){
            $orderItems = $this->_entityService->locateEntity(
                $this->_node->getNodeId(),
                'orderitem',
                $order->getStoreId(),
                array(
                    'PARENT_ID'=>$order->getId(),
                ),
                array(
                    'PARENT_ID'=>'eq'
                ),
                array('linked_to_node'=>$this->_node->getNodeId()),
                array('quantity')
            );
            foreach($orderItems as $oi){
                $localid = $this->_entityService->getLocalId($this->_node->getNodeId(), $oi);
                $items[$localid] = $oi->getData('quantity');
            }
        }else{
            foreach ($rawItems as $entityId=>$quantity) {
                $item = $this->_entityService->loadEntityId($this->_node->getNodeId(), $entityId);
                if ($item->getTypeStr() != 'orderitem' || $item->getParentId() != $order->getId()
                    || $item->getStoreId() != $order->getStoreId()){

                    $message = 'Invalid item '.$entityId.' passed to preprocessRequestItems for order '.$order->getId();
                    throw new GatewayException($message);
                }

                if ($quantity == NULL) {
                    $quantity = $item->getData('quantity');
                }elseif ($quantity > $item->getData('quantity')) {
                    $message = 'Invalid item quantity '.$quantity.' for item '.$entityId.' in order '.$order->getId()
                        .' - max was '.$item->getData('quantity');
                    throw new GatewayExceptionn($message);
                }

                $localId = $this->_entityService->getLocalId($this->_node->getNodeId(), $item);
                $items[$localId] = $quantity;
            }
        }
        return $items;
    }

    /**
     * Handles refunding an order in Retailex
     *
     * @param Order $order
     * @param string $comment Optional comment to append to order
     * @param string $notify String boolean, whether to notify customer
     * @param string $sendComment String boolean, whether to include order comment in notify
     * @param array $itemsRefunded Array of item entity id->qty to refund, or null if automatic (all)
     * @param int $shippingRefund
     * @param int $creditRefund
     * @param int $adjustmentPositive
     * @param int $adjustmentNegative
     * @throws GatewayException
     */
    protected function actionCreditmemo(Order $order, $comment = '', $notify = 'false', $sendComment = 'false',
        $itemsRefunded = NULL, $shippingRefund = 0, $creditRefund = 0, $adjustmentPositive = 0, $adjustmentNegative = 0)
    {
        $items = array();

        if (count($itemsRefunded)) {
            $processItems = $itemsRefunded;
        }else{
            $processItems = array();
            foreach ($order->getOrderitems() as $orderItem) {
                $processItems[$orderItem->getId()] = 0;
            }
        }

        foreach ($this->preprocessRequestItems($order, $processItems) as $local=>$qty) {
            $items[] = array('order_item_id'=>$local, 'qty'=>$qty);
        }

        $creditmemoData = array(
            'qtys'=>$items,
            'shipping_amount'=>$shippingRefund,
            'adjustment_positive'=>$adjustmentPositive,
            'adjustment_negative'=>$adjustmentNegative,
        );

        $originalOrder = $order->getOriginalOrder();
        try {
            $soapResult = $this->soap->call('salesOrderCreditmemoCreate', array(
                $originalOrder->getUniqueId(),
                $creditmemoData,
                $comment,
                $notify,
                $sendComment,
                $creditRefund
            ));
        }catch (\Exception $exception) {
            // store as sync issue
            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }

        if (is_object($soapResult)) {
            $soapResult = $soapResult->result;
        }elseif (is_array($soapResult)) {
            if (isset($soapResult['result'])) {
                $soapResult = $soapResult['result'];
            }else{
                $soapResult = array_shift($soapResult);
            }
        }

        if (!$soapResult) {
            // store as a sync issue
            throw new GatewayException('Failed to get creditmemo ID from Retailex for order '.$order->getUniqueId());
        }

        try {
            $this->soap->call('salesOrderCreditmemoAddComment',
                array($soapResult, 'FOR ORDER: '.$order->getUniqueId(), FALSE, FALSE));
        }catch (\Exception $exception) {
            // store as a sync issue
            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Handles shipping an order in Retailex
     *
     * @param Order $order
     * @param string $comment Optional comment to append to order
     * @param string $notify String boolean, whether to notify customer
     * @param string $sendComment String boolean, whether to include order comment in notify
     * @param array|null $itemsShipped Array of item entity id->qty to ship, or null if automatic (all)
     * @throws GatewayException
     */
    protected function actionShip(Order $order, $comment = '', $notify = 'false', $sendComment = 'false',
        $itemsShipped = NULL, $trackingCode = NULL)
    {
        $items = array();
        foreach ($this->preprocessRequestItems($order, $itemsShipped) as $local=>$qty) {
            $items[] = array('order_item_id'=>$local, 'qty'=>$qty);
        }
        if (count($items) == 0) {
            $items = NULL;
        }

        $orderId = ($order->getData('original_order') != NULL ?
            $order->resolve('original_order', 'order')->getUniqueId() : $order->getUniqueId());
        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_DEBUGEXTRA,
                'rex_o_act_ship',
                'Sending shipment for '.$orderId,
                array(
                    'ord'=>$order->getId(),
                    'items'=>$items,
                    'comment'=>$comment,
                    'notify'=>$notify,
                    'sendComment'=>$sendComment
                ),
                array('node'=>$this->_node, 'entity'=>$order)
            );

        try {
            $soapResult = $this->soap->call('salesOrderShipmentCreate', array(
                'orderIncrementId'=>$orderId,
                'itemsQty'=>$items,
                'comment'=>$comment,
                'email'=>$notify,
                'includeComment'=>$sendComment
            ));
        }catch (\Exception $exception) {
            // store as sync issue
            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }

        if (is_object($soapResult)) {
            $soapResult = $soapResult->shipmentIncrementId;
        }elseif (is_array($soapResult)) {
            if (isset($soapResult['shipmentIncrementId'])) {
                $soapResult = $soapResult['shipmentIncrementId'];
            }else{
                $soapResult = array_shift($soapResult);
            }
        }

        if (!$soapResult) {
            // store as sync issue
            throw new GatewayException('Failed to get shipment ID from Retailex for order '.$order->getUniqueId());
        }

        if ($trackingCode != NULL) {
            try {
                $this->soap->call('salesOrderShipmentAddTrack',
                    array(
                        'shipmentIncrementId'=>$soapResult,
                        'carrier'=>'custom',
                        'title'=>$order->getData('shipping_method', 'Shipping'),
                        'trackNumber'=>$trackingCode)
                );
            }catch (\Exception $exception) {
                // store as sync issue
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
            }
        }
    }

}
