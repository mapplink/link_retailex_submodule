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
use Magento2\Gateway\OrderGateway as Magento2OrderGateway;
use Zend\Stdlib\ArrayObject;


class OrderGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'order';
    const GATEWAY_ENTITY_CODE = 'o';

    /** @var array $this->billingAttributeMapping */
    protected $attributeMapping = array(
//        'ExternalOrderId'=>'UNIQUE_ID',
        'DateCreated'=>'last_name',
        'BillCompany'=>'company',
        'BillPhone'=>'telephone',
        'BillPostCode'=>'postcode',
        'BillState'=>'region',
        'BillCountry'=>'country_code'
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
            $call = 'Order CreateByChannel';
            $data = array('OrderXML'=>array('Customers'=>array('Customer'=>$data)));

            try{
                $response = $this->soap->call($call, $data);
                $logData['response'] = $response;

                $orderResponse = current($response->xpath('//Order'));
                $success = $orderResponse['Result'] == 'Success';
            }catch(\Exception $exception){
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                $success = FALSE;
            }

            if ($success) {
                $message = 'Successfully created order on node '.$nodeId;
                if (isset($orderResponse['OrderId'])) {
                    $localId = $orderResponse['OrderId'];
                    $logCode .= 'suc';
                    $message .= ' with local id.';
                    $logData['local id'] = $localId;
                }else{
                    $localId = NULL;
                    $logCode .= 'nolcid';
                    $message .= ' but response did not contain local id.';
                }

                $this->_entityService->linkEntity($nodeId, $entity, $localId);

                $logLevel = LogService::LEVEL_INFO;
                $logData['order response'] = $orderResponse;
            }
        }else{
            $logLevel = LogService::LEVEL_ERROR;
            $logCode .= 'err';
            $message = 'Could not create order because it seems to exist already (local id is existing).';
            $logData['local id'] = $localId;
        }

        $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $message, $logData);

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

        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_INFO, $logCode.'_no', 'Order write action not implemented yet.', array());

        return FALSE;

        if (!$this->soap) {
            throw new NodeException('No valid API available for sync');
            $api = '-';
            $success = FALSE;

        }else{
            $api = $this->soap->getApiType();
            $localId = $this->_entityService->getLocalId($nodeId, $action->getEntity());

            switch ($action->getType()) {
                case 'cancel':
                    $logCode .= '_ccl';
                    if (isset($localId) && Magento2OrderGateway::hasOrderStateCanceled($action->get)) {
                        $call = 'OrderCancel';
                        $data = array('OrderId'=>$localId);

                        try{
                            $response = $this->soap->call($call, $data);
                            $logData['response'] = $response;

                            $result = current($response->xpath('//Result'));
                            $success = TRUE;
                        }catch(\Exception $exception){
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                            $success = FALSE;
                        }

                        $logLevel = LogService::LEVEL_INFO;
                        $message = '';

                    }else {
                        $success = FALSE;
                        $logLevel = LogService::LEVEL_ERROR;
                        $logCode = '_err';
                        $message = '';
                    }
                    break;

                case 'addPayment':
                    $logCode .= '_pay';

                    if (isset($localId)) {
                        $call = 'OrderAddPayment';
                        $data = array('PaymentXML'=>array('OrderPayments'=>array('OrderPayment'=>$data)));

                        try{
                            $response = $this->soap->call($call, $data);
                            $logData['response'] = $response;

                            $order = current($response->xpath('//Order'));
                        }catch(\Exception $exception){
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                        }

                        $success = TRUE;
                        $logLevel = LogService::LEVEL_INFO;
                        $message = '';

                    }else {
                        $success = FALSE;
                        $logLevel = LogService::LEVEL_ERROR;
                        $logCode = '_err';
                        $message = '';
                    }
                    break;

                default:
                    $success = FALSE;
                    $logLevel = LogService::LEVEL_ERROR;
                    $logCode = '_err';
                    $message = 'No valid action: '.$action->getType();
            }

            if (isset($logLevel)) {
                $this->getServiceLocator()->get('logService')
                    ->log($logLevel, $logCode, $message, array('action type'=>$action->getType()), array('action'=>$action)
                    );
            }
        }
        return $success;
    }

}
