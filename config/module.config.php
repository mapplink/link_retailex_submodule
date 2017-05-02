<?php
/**
 * @package Retailex\config
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause - Please view LICENSE.md for more information
 */

$moduleConfig = array(
    'service_manager'=>array(
        'invokables'=>array(
            'retailexConfigService'=>'Retailex\Service\RetailexConfigService',
            //'retailex_soap'=>'Retailex\Api\Soap',
            'retailex_soap'=>'Retailex\Api\SoapCurl',
        ),
        'shared'=>array(
            'retailexConfigService'=>TRUE,
            'retailex_soap'=>TRUE,
        ),
    ),
    'node_types'=>array(
        'retailex'=>array(
            'module'=>'Retailex',
            'name'=>'Retailex',
            'entity_type_support'=>array(
                'customer',
                'product',
                'order'
            ),
            'soapheader_config_map'=>array(
                'ClientID'=>'retailex-client',
                'UserName'=>'retailex-username',
                'Password'=>'retailex-password'
            ),
            'config'=>array( // Config options to be displayed to the administrator
                'retailex-url'=>array(
                    'label'=>'Retail Express store url',
                    'type'=>'Text',
                    'required'=>TRUE
                ),
                'retailex-wsdl'=>array(
                    'label'=>'Retail Express wsdl prefix',
                    'type'=>'Text',
                    'required'=>TRUE
                ),
                'retailex-client'=>array(
                    'label'=>'Client Id',
                    'type'=>'Text',
                    'required'=>TRUE
                ),
                'retailex-username'=>array(
                    'label'=>'Username',
                    'type'=>'Text',
                    'required'=>TRUE
                ),
                'retailex-password'=>array(
                    'label'=>'Password',
                    'type'=>'Text',
                    'required'=>TRUE
                ),
                'retailex-channel'=>array(
                    'label'=>'Sales Channel Id',
                    'type'=>'Text',
                    'required'=>TRUE
                )
            )
        )
    )
);

return $moduleConfig;
