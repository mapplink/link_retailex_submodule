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

use Entity\Entity;
use Entity\Service\EntityService;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\GatewayException;


class CustomerGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'customer';
    const GATEWAY_ENTITY_CODE = 'cu';
    const ATTRIBUTE_NOT_DEFINED = '> Information missing <';

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
     * @return string $randomPassword
     */
    private function getRandomPassword()
    {
        return "5.#'RXPb[-Q_c{Z@";
    }

    /**
     * Write out all the updates to the given entity.
     * @param Entity $entity
     * @param \Entity\Attribute[] $attributes
     * @param int $type
     * @return bool
     */
    public function writeUpdates(Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        return FALSE;

        // ToDo: Implement writeUpdates() method.

        /** @var EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');

        $data = array(
            'Password'=>$this->getRandomPassword(),
            'BillEmail'=>$entity->getUniqueId(),
            'DelAddress'=>self::ATTRIBUTE_NOT_DEFINED,
            'DelPostCode'=>self::ATTRIBUTE_NOT_DEFINED,
            'DelSuburb'=>self::ATTRIBUTE_NOT_DEFINED,
            'DelState'=>self::ATTRIBUTE_NOT_DEFINED,
            'ReceivesNews'=>0
        );

        $billingAddress = $entity->resolve('billing_address', 'address');
        $shippingAddress = $entity->resolve('shipping_address', 'address');

        if (is_null($billingAddress) && !is_null($shippingAddress)) {
            $billingAddress = $shippingAddress;
        }elseif (!is_null($billingAddress) && is_null($shippingAddress)) {
            $shippingAddress = $billingAddress;
        }

        if (!is_null($billingAddress)) {
            $data['BillFirstName'] = $billingAddress->getData('first_name', NULL);
            $data['BillLastName'] = $billingAddress->getData('last_name', NULL);
        }
        if (!is_null($shippingAddress)) {
            $addressArray = explode(chr(10), $shippingAddress->getData('street', self::ATTRIBUTE_NOT_DEFINED));
            if (count($addressArray) == 1) {
                $data['DelAddress'] = array_shift($addressArray);
            }elseif (count($addressArray) > 1) {
                $data['DelSuburb'] = array_pop($addressArray);
                $data['DelAddress'] = implode(chr(10), $addressArray);
            }

            $data['DelPostCode'] = $shippingAddress->getData('postcode', self::ATTRIBUTE_NOT_DEFINED);
            $data['DelState'] = $shippingAddress->getData('region', self::ATTRIBUTE_NOT_DEFINED);
        }

        foreach ($attributes as $attribute) {
            $value = $entity->getData($attribute);

            // Normal attribute
            switch ($attribute) {
                case 'enable_newsletter':
                    $data['ReceivesNews'] = ($value == 1 ? 1 : 0);
                    break;
                case 'first_name':
                    if (!isset($data['BillFirstName'])) {
                        $data['BillFirstName'] = $value;
                    }
                    break;
                case 'last_name':
                    if (!isset($data['BillLastName'])) {
                        $data['BillLastName'] = $value;
                    }
                    break;
                case 'middle_name':
                case 'date_of_birth':
                case 'newslettersubscription':
                    // Ignore these attributes
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

            $attributeSet = NULL;
            foreach($this->_attSets as $setId=>$set){
                if($set['name'] == $entity->getData('customer_class', 'default')){
                    $attributeSet = $setId;
                    break;
                }
            }
            $req = array(
                $entity->getData('type'),
                $attributeSet,
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
