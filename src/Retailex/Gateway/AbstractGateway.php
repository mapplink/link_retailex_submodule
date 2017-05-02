<?php
/**
 * @package Retailex\Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause - Please view LICENSE.md for more information
 */

namespace Retailex\Gateway;

use Entity\Entity;
use Entity\Wrapper\Address;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\GatewayException;
use Node\AbstractGateway as BaseAbstractGateway;


abstract class AbstractGateway extends BaseAbstractGateway
{

    const GATEWAY_NODE_CODE = 'rex';
    const GATEWAY_ENTITY_CODE = 'gey';
    const GATEWAY_ENTITY = 'generic';
    const ATTRIBUTE_NOT_DEFINED = '-';

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
     * @param Address $address
     * @return string $deliveryAddress
     */
    protected function getAddress(Address $entity)
    {
        $deliveryAddress = $entity->getStreet();
        return $deliveryAddress;
    }

    /**
     * @param Address $address
     * @return string|NULL $deliveryAddress
     */
    protected function getAddress2(Address $entity)
    {
        $deliveryAddress2 = $entity->getSuburb();
        $deliverySuburb = $this->getSuburb($entity);

        if ($deliverySuburb == $deliveryAddress2 || $deliverySuburb == self::ATTRIBUTE_NOT_DEFINED) {
            $deliveryAddress2 = NULL;
        }

        return $deliveryAddress2;
    }

    /**
     * @param Address $entity
     * @return string $deliverySuburb
     */
    protected function getSuburb(Address $entity)
    {
        $city = $entity->getData('city', '');

        if (strlen($city) == 0) {
            $suburb = $entity->getSuburb();
            if (strlen($suburb) == 0) {
                $suburb = self::ATTRIBUTE_NOT_DEFINED;
            }
        }else{
            $suburb = $city;
        }

        return $suburb;
    }

    /**
     * @param Entity $entity
     * @return string $deliveryState
     */
    protected function getState(Entity $entity)
    {
        $state = $entity->getData('region', '');

        if (strlen($state) == 0) {
            $state = $entity->getData('city', self::ATTRIBUTE_NOT_DEFINED);
        }

        return $state;
    }

}
