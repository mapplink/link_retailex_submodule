<?php
/**
 * Customer gateway for Retail Express
 * @category Retailex
 * @package Retailex\Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Retailex\Gateway;

use Entity\Service\EntityService;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\NodeException;
use Magelink\Exception\GatewayException;


class CustomerGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'customer';
    const GATEWAY_ENTITY_CODE = 'cu';

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws GatewayException
     */
    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'customer') {
            throw new GatewayException('Invalid entity type for this gateway');
            $success = FALSE;
        }else{
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUG, 'rex_cu_init', 'Initialised Retailex customer gateway.', array());
        }

        return $success;
    }

    /**
     * TECHNICAL DEBT // ToDo: Implement this method
     */
    public function retrieve()
    {
        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_INFO, 'rex_cu_re_no', 'Customer retrieval not implemented yet.', array());
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param \Entity\Attribute[] $attributes
     * @param int $type
     * @return bool
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        return FALSE;

        // ToDo: Implement writeUpdates() method.

        /** @var \Entity\Service\EntityService $entityService */
/*        $entityService = $this->getServiceLocator()->get('entityService');

        $additionalAttributes = $this->_node->getConfig('customer_attributes');
        if(is_string($additionalAttributes)){
            $additionalAttributes = explode(',', $additionalAttributes);
        }
        if(!$additionalAttributes || !is_array($additionalAttributes)){
            $additionalAttributes = array();
        }

        $data = array(
            'additional_attributes'=>array(
                'single_data'=>array(),
                'multi_data'=>array(),
            ),
        );

        foreach($attributes as $att){
            $v = $entity->getData($att);
            if(in_array($att, $additionalAttributes)){
                // Custom attribute
                if(is_array($v)){
                    // ToDo: implement additional
                    throw new MagelinkException('This gateway does not yet support multi_data additional attributes');
                }else{
                    $data['additional_attributes']['single_data'][] = array(
                        'key'=>$att,
                        'value'=>$v,
                    );
                }
                continue;
            }
            // Normal attribute
            switch($att){
                case 'name':
                case 'description':
                case 'short_description':
                case 'price':
                case 'special_price':
                    // Same name in both systems
                    $data[$att] = $v;
                    break;
                case 'special_from':
                    $data['special_from_date'] = $v;
                    break;
                case 'special_to':
                    $data['special_to_date'] = $v;
                    break;
                case 'customer_class':
                    if($type != \Entity\Update::TYPE_CREATE){
                        // ToDo: log error (but no exception)
                    }
                    break;
                case 'type':
                    if($type != \Entity\Update::TYPE_CREATE){
                        // ToDo: log error (but no exception)
                    }
                    break;
                case 'enabled':
                    $data['status'] = ($v == 1 ? 2 : 1);
                    break;
                case 'visible':
                    $data['status'] = ($v == 1 ? 4 : 1);
                    break;
                case 'taxable':
                    $data['status'] = ($v == 1 ? 2 : 1);
                    break;
                default:
                    // Warn unsupported attribute
                    break;
            }
        }

        if($type == \Entity\Update::TYPE_UPDATE){
            $req = array(
                $entity->getUniqueId(),
                $data,
                $entity->getStoreId(),
                'sku'
            );
            $this->soap->call('catalogCustomerUpdate', $req);
        }else if($type == \Entity\Update::TYPE_CREATE){

            $attSet = NULL;
            foreach($this->_attSets as $setId=>$set){
                if($set['name'] == $entity->getData('customer_class', 'default')){
                    $attSet = $setId;
                    break;
                }
            }
            $req = array(
                $entity->getData('type'),
                $attSet,
                $entity->getUniqueId(),
                $data,
                $entity->getStoreId()
            );
            $res = $this->soap->call('catalogCustomerCreate', $req);
            if(!$res){
                throw new MagelinkException('Error creating customer in Retailex (' . $entity->getUniqueId() . '!');
            }
            $entityService->linkEntity($this->_node->getNodeId(), $entity, $res);
        }
*/
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @return bool
     */
    public function writeAction(\Entity\Action $action)
    {
        return FALSE;

        /** @var \Entity\Service\EntityService $entityService */
/*        $entityService = $this->getServiceLocator()->get('entityService');

        $entity = $action->getEntity();

        switch($action->getType()){
            case 'delete':
                $this->soap->call('catalogCustomerDelete', array($entity->getUniqueId(), 'sku'));
                break;
            default:
                throw new MagelinkException('Unsupported action type ' . $action->getType() . ' for Retailex Orders.');
        }
*/
    }

}
