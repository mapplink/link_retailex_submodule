<?php
/**
 * Retailex Abstract Gateway
 * @category Retailex
 * @package Retailex\Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Retailex\Gateway;

use Magelink\Exception\MagelinkException;
use Magelink\Exception\GatewayException;
use Node\AbstractGateway as BaseAbstractGateway;


abstract class AbstractGateway extends BaseAbstractGateway
{

    const GATEWAY_ENTITY = 'generic';
    const GATEWAY_ENTITY_CODE = 'gty';


    /** @var \Entity\Service\EntityConfigService $this->entityConfigService */
    protected $entityConfigService = NULL;
    /** @var \Retailex\Api\Soap $this->soap */
    protected $soap = NULL;
    /** @var int $lastRetrieveTimestamp */
    protected $lastRetrieveTimestamp = NULL;
    /** @var int $newRetrieveTimestamp */
    protected $newRetrieveTimestamp = NULL;


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @throws MagelinkException
     * @return bool $success
     */
    protected function _init($entityType)
    {
        $this->soap = $this->_node->getApi('soap');
        $this->entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        if (!$this->soap) {
            throw new GatewayException('SOAP is required for Retailex '.ucfirst($entityType));
            $success = FALSE;
        }else{
            $success = TRUE;
        }

        return $success;
    }

    /**
     * TECHNICAL DEBT // ToDo: Implement this instead of the retrieve functionality on all gateways
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     * @return array $retrieveResults
     */
    protected function retrieveEntities() {}

    /**
     * @return int $this->newRetrieveTimestamp
     */
    protected function getNewRetrieveTimestamp()
    {
        if ($this->newRetrieveTimestamp === NULL) {
            $this->newRetrieveTimestamp = $this->getRetrieveTimestamp();
        }

        return $this->newRetrieveTimestamp;
    }

    /** @param int $timestamp
     * @return bool|string $date */
    protected function convertTimestampToRetailexDateFormat($timestamp)
    {
        $date = date('Y-m-d', $timestamp).'T'.date('H:i:s', $timestamp).'Z';

        return $date;
    }

    /** @return bool|string $lastRetrieve */
    protected function getLastRetrieveDate()
    {
        $lastRetrieve = $this->convertTimestampToRetailexDateFormat($this->getLastRetrieveTimestamp());
        return $lastRetrieve;
    }

    /** @return bool|int $this->lastRetrieveTimestamp */
    protected function getLastRetrieveTimestamp()
    {
        if ($this->lastRetrieveTimestamp === NULL) {
            $this->lastRetrieveTimestamp =
                $this->_nodeService->getTimestamp($this->_nodeEntity->getNodeId(), static::GATEWAY_ENTITY, 'retrieve');
        }

        return $this->lastRetrieveTimestamp;
    }

}
