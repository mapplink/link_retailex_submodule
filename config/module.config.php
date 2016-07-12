<?php
$moduleConfig = array(
    'service_manager'=>array(
        'invokables'=>array(
            'retailex_soap'=>'Retailex\Api\Soap',
        ),
        'shared'=>array(
            'retailex_soap'=>FALSE,
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
                'retailex-sales-channel'=>array(
                    'label'=>'Sales Channel Id',
                    'type'=>'Text',
                    'required'=>TRUE
                )
            )
        )
    )
);

return $moduleConfig;
