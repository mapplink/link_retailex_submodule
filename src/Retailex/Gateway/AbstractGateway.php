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

    const GATEWAY_NODE_CODE = 'rex';
    const GATEWAY_ENTITY_CODE = 'gey';
    const GATEWAY_ENTITY = 'generic';

    /** @var \Entity\Service\EntityConfigService $this->entityConfigService */
    protected $entityConfigService = NULL;
    /** @var \Retailex\Api\Soap $this->soap */
    protected $soap = NULL;


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
     * @param int $timestamp
     * @return string $date
     */
    protected function convertTimestampToExternalDateFormat($timestamp)
    {
        $date = date('Y-m-d', $timestamp).'T'.date('H:i:s', $timestamp).'Z';

        return $date;
    }

}
