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

use Entity\Wrapper\Order;
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

    /** @var array $this->billingAttributeMapping */
    protected $createOrderAttributeMap = array(
//        'ExternalOrderId'=>'UNIQUE_ID',
        'DateCreated'=>'placed_at',
        'OrderTotal'=>array('{order}'=>'getOrderTotal'),
        'OrderStatus'=>array('status'=>'getRetailExpressStatus'),
        'CustomerId'=>array('customer'=>'getLocalCustomer'),
        'ExternalCustomerId'=>'customer',
        'BillEmail'=>'customer_email',
        'ReceiverNews'=>0
    );
    /** @var array $this->billingAttributeMapping */
    protected $createOrderBillingAttributeMapping = array(
        'BillFirstName'=>'first_name',
        'BillLastName'=>'last_name',
        //'BillAddress'=>'street',
        'BillCompany'=>'company',
        'BillPhone'=>'telephone',
        'BillPostCode'=>'postcode',
        'BillState'=>'region',
        'BillCountry'=>'country_code'
    );
    /** @var array $this->shippingAttributeMapping */
    protected $createOrderShippingAttributeMapping = array(
        'DelCompany'=>'company',
        //'DelAddress'=>'street',
        //'DelSuburb'=>'street',
        'DelPhone'=>'telephone',
        'DelPostCode'=>'postcode',
        'DelState'=>'region',
        'DelCountry'=>'country_code'
    );
    /** @var array $this->paymentAttributeMap */
    protected $paymentAttributeMap = array(
        'OrderId'=>array('getLocalOrderId'),
        'MethodId'=>array('payment_method'=>'getMethodId'),
        'Amount'=>'grand_total',
        'DateCreated'=>'placed_at'
    );
    /** @var array $this->paymentedMethodMapping */
    protected $methodById = array(
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
            $date = $this->convertTimestampToRetailexDateFormat($retrieveTimestamp);
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
        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_INFO, 'rex_o_re_no', 'Order retrieval not implemented yet.', array());

        return FALSE;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        $nodeId = $this->_node->getNodeId();
        $localId = $this->_entityService->getLocalId($nodeId, $entity);
        $orderStatus = $entity->getData('status', NULL);

        $logCode = $this->getLogCode().'_wr_';
        $logData = array('order'=>$entity->getUniqueId());

        if ($entity->getTypeStr() !== 'order') {
            throw new GatewayException('Wrong entity type '.$entity->getTypeStr().' on Retail Express order gateway.');
            $success = FALSE;

        }elseif (!isset($localId)) {
            $call = 'OrderCreateByChannel';
            $data = array('OrderXML'=>array('Orders'=>array('Order'=>$this->getOrderCreateData($entity))));

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
            }

            if ($success) {
                $message = 'Successfully created order on node '.$nodeId;
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

                $this->_entityService->linkEntity($nodeId, $entity, $localId);
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
                        $data = array('PaymentXML'=>array('OrderPayments'=>array(
                            'OrderPayment'=>$this->getOrderAddPaymentData($order)
                        )));

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
                        $logCode = '_err';
                        $message = 'Payment cannot be added due to missing local id';
                    }
                    break;

                default:
                    $success = FALSE;
                    $logLevel = LogService::LEVEL_ERROR;
                    $logCode = '_err';
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
     * @param int $methodString
     * @return int|NULL $methodId
     */
    public static function getMethodId($methodString)
    {
        return self::getMappedId('method', $methodString);
    }

    /**
     * @param Order $order
     * @return float $orderTotal
     */
    protected function getOrderTotal(Order $order)
    {
        return $order->getOrderTotalInclShipping();
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
     * @param int $customerId
     * @return int $localCustomerId
     */
    protected function getLocalCustomer($customerId)
    {
        return $this->_entityService->getLocalId($this->_node->getNodeId(), $customerId);
    }

    /**
     * @param Order $order
     * @param array $data
     * @param string $localCode
     * @param mixed $code
     * @return array $value
     */
    protected function assignData(Order $order, &$data, $localCode, $code)
    {
        $error = FALSE;

        if (is_string($code) || is_array($code) && count($code) > 1) {
            $data[$localCode] = $order->getData($code, NULL);;
        }elseif (is_array($code) && count($code) == 1) {
            $method = current($code);
            $code = key($code);
            $value = NULL;

            try{
                if (method_exists('Retailex\Gateway\OrderGateway', $method)) {
                    if (is_numeric($code)) {
                        $value = self::$method();
                    }elseif  ($code == '{order}') {
                        $value = self::$method($order);
                    }else{
                        $value = self::$method($order->getData($code, NULL));
                    }
                }else{
                    $error = '. Mapping method '.$method.' is not existing.';
                }
            }catch (\Exception $exception) {
                $error = ': '.$exception->getMessage();
            }
        }else{
            $error = ' with code '.$code.'.';
        }

        if (is_string($code) && strlen($code) > 0 && isset($value) && !isset($error)) {
            $data[$code] = $value;
        }elseif (is_array($code) && count($code) > 1 && isset($value) && !isset($error)) {
            foreach ($code as $subcode) {
                $data[$subcode] = $value;
            }
        }else{
            $message = 'Error on order data mapping'.(isset($error) ? $error : '.');
            $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_ERROR, 'rex_o_wr_map_err', $message,
                array('local code'=>$localCode, 'code'=>$code, 'value'=>$value, 'order'=>$order->getUniqueId()));
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
            foreach ($this->createOrderBillingAttributeMapping as $localCode => $code) {
                $createData[$localCode] = $billingAddress->getData($code, null);
            }
        }
        if (is_null($shippingAddress = $order->getShippingAddressEntity())) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR, $logCode.'_sad_err', 'Shipping address missing.', $logData);
        }else{
            foreach ($this->createOrderShippingAttributeMapping as $localCode=>$code) {
                $createData[$localCode] = $shippingAddress->getData($code, NULL);
            }
        }

        return $createData;
    }

    /**
     * @param Order $order
     * @return array
     */
    protected function getOrderAddPaymentData(Order $order)
    {
        $paymentData = array();

        foreach ($this->createOrderBillingAttributeMapping as $localCode=>$code) {
            $this->assignData($order, $paymentData, $localCode, $code);
        }

        return $paymentData;
    }
}
