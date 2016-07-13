<?php
/**
 * @category Retailex
 * @package Retailex\Service
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Retailex\Service;

use Application\Service\ApplicationConfigService;


class RetailexConfigService extends ApplicationConfigService
{

    /**
     * @return string $cronLockDirectory
     */
    public function getSoapheaderConfigMap()
    {
        $nodeTypesConfigData = $this->getConfigData('node_types');
        $retailexConfigData = $this->getArrayKeyData($nodeTypesConfigData, 'retailex', array());
        $soapheaderConfigMap = $this->getArrayKeyData($retailexConfigData, 'soapheader_config_map', array());

        return $soapheaderConfigMap;
    }

}
