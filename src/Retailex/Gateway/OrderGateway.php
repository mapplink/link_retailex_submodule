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

use Entity\Entity;
use Entity\Wrapper\Order;
use Entity\Wrapper\Orderitem;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\NodeException;
use Magelink\Exception\GatewayException;
use Magelink\Exception\SyncException;
use Magento2\Gateway\OrderGateway as Magento2OrderGateway;


class OrderGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'order';
    const GATEWAY_ENTITY_CODE = 'o';

    /** @var array $this->createOrderAttributeMap */
    protected $createOrderAttributeMap = array(
//        'ExternalOrderId'=>array('UNIQUE_ID'=>NULL), // string key: method
        'DateCreated'=>array('placed_at'=>'getDateCreated'),
        'OrderTotal'=>array('{entity}'=>'getOrderTotal'),
        'OrderStatus'=>array('status'=>'getRetailExpressStatus'),
        'CustomerId'=>array('customer'=>'getLocalId'),
        'ExternalCustomerId'=>array('customer'),
        'BillEmail'=>array('customer_email'), // int key: attribute
        'ReceiverNews'=>0
    );
    /** @var array $this->createOrderOrderitemsAttributeMap */
    protected $createOrderOrderitemsAttributeMap = array(
        'ProductId'=>array('product'=>'getLocalId'),
        'QtyOrdered'=>array('{entity}'=>'getDeliveryQuantity'),
        'QtyFulfilled'=>0, // scalar value: default value
        'UnitPrice'=>array('{entity}'=>'getDiscountedPrice'),
        'DeliveryDueDate'=>'',
        'DeliveryMethod'=>array('{entity}'=>'getShipmentMethod'),
        'DeliveryDriverName'=>'',
        'TaxRateApplied'=>0.15,
    );
    /** @var array $this->paymentAttributeMap */
    protected $paymentAttributeMap = array(
        'OrderId'=>array('{entity}'=>'getLocalId'),
        'MethodId'=>array('{entity}'=>'getPaymentMethodId'),
        'Amount'=>array('grand_total'),
        'DateCreated'=>array('placed_at')
    );

    /** @var array $this->createOrderBillingAttributeMap */
    protected $createOrderBillingAttributeMap = array(
        'BillFirstName'=>'first_name',
        'BillLastName'=>'last_name',
        //'BillAddress'=>'street',
        'BillCompany'=>'company',
        'BillPhone'=>'telephone',
        'BillPostCode'=>'postcode',
        'BillState'=>'region',
        'BillCountry'=>'country_code'
    );
    /** @var array $this->createOrderShippingAttributeMap */
    protected $createOrderShippingAttributeMap = array(
        'DelCompany'=>'company',
        'DelAddress'=>array('street'=>'getDeliveryAddress'),
        'DelSuburb'=>array('{entity}'=>'getDeliverySuburb'),
        'DelPhone'=>'telephone',
        'DelPostCode'=>'postcode',
        'DelState'=>'region',
        'DelCountry'=>'country_code'
    );

    /** @var array $this->methodById */
    protected static $methodById = array(
        11=>'paypal_express',
        13=>'paymentexpress_pxpay2'
    );


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
            $date = $this->convertTimestampToExternalDateFormat($retrieveTimestamp);
        }else{
            $date = FALSE;
        }

        return $date;
    }

    /**
     * TECHNICAL DEBT // ToDo: Implement this method
     */
    protected function retrieveEntities()
    {
$this->getServiceLocator()->get('logService')->log(LogService::LEVEL_INFO, 'rex_o_re_no', 'Order retrieval not implemented yet.', array());return 0;$retailExpressData = 0;

        return count($retailExpressData);
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        $localId = $this->getLocalId($entity);
        $orderStatus = $entity->getData('status', NULL);

        $logCode = $this->getLogCode().'_wr_';
        $logData = array('order'=>$entity->getUniqueId());

        if ($entity->getTypeStr() !== 'order') {
            throw new GatewayException('Wrong entity type '.$entity->getTypeStr().' on Retail Express order gateway.');
            $success = FALSE;

        }elseif (!isset($localId)) {
            $call = 'OrderCreateByChannel';
            $data = array(
                'OrderXML'=>array('Orders'=>array('Order'=>$this->getOrderCreateData($entity))),
                'ChannelId'=>$this->_node->getConfig('retailex-channel')
            );

            $logData['soap data'] = $data;

            try{
                $response = $this->soap->call($call, $data);
                $logData['response'] = $response;

                if (is_null($response)) {
                    throw new SyncException($call.' returned NULL.');
                }else{
                    $orderResponse = current($response->xpath('//Order'));
                    $success = ($orderResponse['Result'] == 'Success');
                    $message = '';
                }
            }catch(\Exception $exception){
                $message = ': '.$exception->getMessage();
                $success = FALSE;
                $orderResponse = NULL;
            }

            if ($success) {
                $message = 'Successfully created order on node '.$this->_node->getNodeId();
                if (isset($orderResponse['OrderId'])) {
                    $localId = $orderResponse['OrderId'];
                    $logLevel = LogService::LEVEL_INFO;
                    $logCode .= 'suc';
                    $message .= ' with local id.';
                    $logData['local id'] = $localId;
                }else{
                    $localId = NULL;
                    $logLevel = LogService::LEVEL_WARN;
                    $logCode .= 'nolcid';
                    $message .= ' but response did not contain local id.';
                }

                $this->_entityService->linkEntity($this->_node->getNodeId(), $entity, $localId);
            }else{
                $logLevel = LogService::LEVEL_ERROR;
                $logCode .= 'fail';
                $message = trim('Failed to create order '.$entity->getUniqueId().$message);
            }
            $logData['order response'] = $orderResponse;
        }else{
            $logLevel = LogService::LEVEL_ERROR;
            $logCode .= 'err';
            $logData['local id'] = $localId;
            $message = 'Could not create order because it seems to exist already. Local id is existing';
            $success = FALSE;
        }

        $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $message, $logData);

        if (!$success) {
            throw new GatewayException($message);
        }

        return $success;
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
        $logCode = 'rex_o_wa';
        $success = FALSE;

        if (!$this->soap) {
            throw new NodeException('No valid API available for sync');
            $api = '-';
        }else{
            $api = $this->soap->getApiType();

            /** @var Order $order */
            $order = $action->getEntity();
            $orderStatus = $order->getData('status');
            $localId = $this->_entityService->getLocalId($this->_node->getNodeId(), $order);

            $logLevel = LogService::LEVEL_INFO;
            $logData = array('action type'=>$action->getType(), 'order unique'=>$order->getUniqueId());
            $logEntities = array('action'=>$action, 'order'=>$order);

            switch ($action->getType()) {
                case 'cancel':
                    $logCode .= '_cl';
                    $message = 'Order cannot be cancelled';

                    if (isset($localId) && Magento2OrderGateway::hasOrderStateCanceled($orderStatus)) {
                        $call = 'OrderCancel';
                        $data = array('OrderId'=>$localId);

                        try{
                            $response = $this->soap->call($call, $data);
                            $logData['response'] = $response;

                            $resultResponse = current($response->xpath('//Result'));
                            $logData['response result'] = $resultResponse;
                            $success = ($resultResponse == 'Success');
                        }catch(\Exception $exception) {
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                        }

                        if ($success) {
                            $message = 'Cancelled order successfully.';
                        }else{
                            $logLevel = LogService::LEVEL_ERROR;
                            $logCode .= '_fail';
                            $message = 'Cancel order failed';
                        }
                    }elseif (!Magento2OrderGateway::hasOrderStateCanceled($orderStatus)) {
                        $logLevel = LogService::LEVEL_ERROR;
                        $logCode .= '_wsts';
                        $message .= '. It has the wrong status: '.$orderStatus;
                    }elseif (!isset($localId)) {
                        $logLevel = LogService::LEVEL_ERROR;
                        $logCode .= '_err';
                        $message .= ' Retail Express. Local id is missing';
                    }else{
                        $logCode .= '_err?';
                    }
                    break;

                case 'addPayment':
                    $logCode .= '_pay';

                    if (isset($localId)) {
                        $call = 'OrderAddPayment';
                        $data = array('PaymentXML'=>$this->getOrderAddPaymentData($order));

                        try{
                            $response = $this->soap->call($call, $data);
                            $logData['response'] = $response;

                            $resultResponse = current($response->xpath('//Result'));
                            $logData['response result'] = $resultResponse;
                            $success = ($resultResponse == 'Success');
                        }catch(\Exception $exception){
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                        }

                        if ($success) {
                            $success = TRUE;
                            $logLevel = LogService::LEVEL_INFO;
                            $message = 'Payment was added successfully.';
                        }else{
                            $logLevel = LogService::LEVEL_ERROR;
                            $logCode .= 'fail';
                            $message = 'Add payment failed';
                        }
                    }else{
                        $logLevel = LogService::LEVEL_ERROR;
                        $logCode .= '_err';
                        $message = 'Payment cannot be added due to missing local id';
                    }
                    break;

                default:
                    $success = FALSE;
                    $logLevel = LogService::LEVEL_ERROR;
                    $logCode .= '_err';
                    $message = 'No valid action: '.$action->getType();
            }

            if (isset($logLevel)) {
                if (!$success) {
                    if (isset($result)) {
                        $message .= ' with result: '.var_export($result, TRUE);
                    }elseif (isset($exception)) {
                        $message .= ' with exception: '.$exception->getMessage();
                    }else{
                        $message .= '.';
                    }
                }

                $this->getServiceLocator()->get('logService')
                    ->log($logLevel, $logCode, $message, $logData, $logEntities);
            }
        }

        return $success;
    }

    /**
     * @param Order $order
     * @return int|NULL $methodId
     */
    public function getPaymentMethodId($order)
    {
        $methodString = $order->getPaymentMethodsString();
        return self::getMappedId('method', $methodString);
    }

    /**
     * @param string $placedAt
     * @return string $dateCreated
     */
    protected function getDateCreated($placedAt)
    {
        $timestamp = strtotime($placedAt);
        $dateCreated = $this->convertTimestampToExternalDateFormat($timestamp);

        return $dateCreated;
    }

    /**
     * @param mixed $entity
     * @return NULL|string
     */
    protected function getLocalId($entity)
    {
        if ((int) $entity == $entity || is_a($entity, '\Entity\Entity')) {
            $localId = $this->_entityService->getLocalId($this->_node->getNodeId(), $entity);
        }else{
            $localId = NULL;
        }
        return $localId;
    }

    /**
     * @param string $street
     * @return string $deliveryAddress
     */
    protected function getDeliveryAddress($street)
    {
        $address = strtok(strtok($street, "\r"), "\n");
        return $address;
    }

    /**
     * @param Entity $entity
     * @return string $deliverySuburb
     */
    protected function getDeliverySuburb(Entity $entity)
    {
        $streetArray = explode("\n", str_replace(array("\r", "\n"), "\n", $entity->getData('street', '')));

        if (count($streetArray) > 1 && strlen($streetArray[count($streetArray) - 1]) > 0) {
            $suburb = array_pop($streetArray);
        }else{
            $suburb = $entity->getData('city', '-');
        }

        return $suburb;
    }

    /**
     * @param Order $order
     * @return float $orderTotal
     */
    protected function getOrderTotal(Order $order)
    {
        return number_format(round($order->getOrderTotalInclShipping(), 2), 2, '.', '');
    }

    /**
     * @param Orderitem $orderitem
     * @return string $shipmentMethod
     */
    protected function getShipmentMethod(Orderitem $orderitem)
    {
        $order = $orderitem->getOrder();

        if (!$orderitem->getData('is_physical', TRUE)) {
            $shipmentMethod = '';
        }elseif ($order) {
            $shipmentMethod = $order->getShipmentMethod();
        }
        if (!isset($shipmentMethod)) {
            $shipmentMethod = 'home';
        }

        return $shipmentMethod;
    }

    /**
     * @param string $status
     * @return string $retailExpressStatus
     */
    protected function getRetailExpressStatus($status)
    {
        if (Magento2OrderGateway::hasOrderStatePending($status)) {
            $retailExpressStatus = 'Quote'; // 'Awaiting Payment';
        }elseif (Magento2OrderGateway::hasOrderStateCanceled($status)) {
            $retailExpressStatus = 'Cancelled';
        }elseif (Magento2OrderGateway::hasOrderStateProcessing($status)) {
            $retailExpressStatus = 'Processed';
        }elseif (Magento2OrderGateway::hasFinalOrderState($status)) {
            $retailExpressStatus = 'Processed';
        }else{
            $retailExpressStatus = 'Incomplete';
        }

        return $retailExpressStatus;
    }

    /**
     * @param Entity $entity
     * @param array $data
     * @param string $localCode
     * @param mixed $value
     * @return array $data
     */
    protected function assignData(Entity $entity, &$data, $localCode, $value)
    {
        $error = '';
        $logData = array('node id'=>$this->_node->getNodeId(), 'entity type'=>$entity->getTypeStr(),
            'entity unique id'=>$entity->getUniqueId(), 'local code'=>$localCode, 'value (param)'=>$value);

        if (is_array($value) && is_int(key($value)) && is_string($code = current($value))) {
            $logData['code'] = $code;
            $value = $entity->getData($code, NULL);

        }elseif (is_array($value) && count($value) == 1) {
            $code = $logData['code'] = key($value);
            $method = $logData['method'] = current($value);
            $value = NULL;

            try{
                $isLocalMethod = $logData['isLocalMethod'] = method_exists('Retailex\Gateway\OrderGateway', $method);

                if ($code == '{parent}' && is_null($parent = $entity->getParent())) {
                    $error = '. Parent is not defined.';

                }elseif ($code == '{parent}' && $isLocalMethod){
                    $logData['parameter type:id'] = $parent->getTypeStr().':'.$parent->getId();
                    $value = $this->$method($parent);

                }elseif ($code == '{parent}' && method_exists($parent, $method)) {
                    $value = $parent->$method();

                }elseif ($code == '{entity}' && $isLocalMethod) {
                    $logData['parameter type:id'] = $entity->getTypeStr().':'.$entity->getId();
                    $value = $this->$method($entity);

                }elseif ($code == '{entity}' && method_exists($entity, $method)) {
                    $value = $entity->$method();

                }elseif (strlen($code) == 0 && $isLocalMethod) {
                    $value = $this->$method();

                }elseif (!preg_match('#^\{.*\}$#ism', $code, $match) && $isLocalMethod) {
                    $parameter = $logData['parameter'] = $entity->getData($code, NULL);
                    $value = $this->$method($parameter);

                }else{
                    $error = '. Mapping method '.$method.' is not existing.';
                }
            }catch (\Exception $exception) {
                $error = ': '.$exception->getMessage();
            }

        }elseif (!is_scalar($value)) {
            $error = ' with code/value '.var_export($value, TRUE).'.';
            $code = $value = NULL;
        }

        $logData['value (return)'] = $value;

        if (is_string($localCode) && strlen($localCode) > 0 && isset($value) && strlen($error) == 0) {
            $data[$localCode] = $value;
        }else{
            $message = 'Error on entity data mapping'.(strlen($error) > 0 ? $error : '.');
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR, 'rex_o_wr_map_err', $message, $logData);
        }

        return $data;
    }

    /**
     * @param Order $order
     * @return array $createData
     */
    protected function getOrderCreateData(Order $order)
    {
        $logCode = $this->getLogCode();
        $logData = array('order'=>$order->getUniqueId());

        $createData = array();
        foreach ($this->createOrderAttributeMap as $localCode=>$code) {
            $this->assignData($order, $createData, $localCode, $code);
        }

        if (is_null($billingAddress = $order->getBillingAddressEntity())) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR, $logCode.'_bad_err', 'Billing address missing.', $logData);
        }else{
            foreach ($this->createOrderBillingAttributeMap as $localCode=>$code) {
                $createData[$localCode] = $billingAddress->getData($code, NULL);
            }
        }

        if (is_null($shippingAddress = $order->getShippingAddressEntity())) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR, $logCode.'_sad_err', 'Shipping address missing.', $logData);
        }else{
            foreach ($this->createOrderShippingAttributeMap as $localCode=>$code) {
                $createData[$localCode] = $shippingAddress->getData($code, NULL);
            }
        }

        $createData = array_replace_recursive(
            $createData,
            $this->getOrderitemData($order),
            $this->getOrderAddPaymentData($order, TRUE)
        );

        return $createData;
    }


    protected function getOrderitemData(Order $order)
    {
        $orderitemsData = array();
        $orderitemNo = 0;

        foreach ($order->getOrderitems() as $orderitem) {
            $orderitemData = array();
            foreach ($this->createOrderOrderitemsAttributeMap as $localCode=>$code) {
                $this->assignData($orderitem, $orderitemData, $localCode, $code);
            }
            $orderitemsData['Orderitem<'.++$orderitemNo.'>'] = $orderitemData;
        }
        $orderitemsData = array('Orderitems'=>$orderitemsData);

        return $orderitemsData;
    }

    /**
     * @param Order $order
     * @param bool|FALSE $skipOrderId
     * @return array $paymentData
     */
    protected function getOrderAddPaymentData(Order $order, $skipOrderId = FALSE)
    {
        $paymentData = array();

        foreach ($this->paymentAttributeMap as $localCode=>$code) {
            if ($localCode != 'OrderId' || !$skipOrderId) {
                $this->assignData($order, $paymentData, $localCode, $code);
            }
        }

        $paymentData = array('OrderPayments'=>array('OrderPayment'=>$paymentData));

        return $paymentData;
    }
}
