<?php
/**
 * Retailex\Node
 * @category Retailex
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Retailex;

use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Node\AbstractNode;
use Node\AbstractGateway;
use Node\Entity;


class Node extends AbstractNode
{

    /**
     * Set up any initial data structures, connections, and open any required files that the node needs to operate.
     * @param Entity\Node $nodeEntity
     */
    protected function _init() {}

    /**
     * The opposite of _init. Will always be the last call to the Node to close off any open connections, files, etc.
     * NOTE: This will be called even if the Node has thrown a NodeException
     */
    protected function _deinit()
    {
        foreach ($this->_gateway as $entity=>&$gateway) {
            if ($gateway && method_exists($gateway, 'deinit')) {
                $gateway->deinit();
            }
        }
    }

    /**
     * @return string $nodeLogPrefix
     */
    protected function getNodeLogPrefix()
    {
        return 'rex_';
    }

    /**
     * Retailex node handles update slightly different:
     *   writeUpdates() in parent accumulates data and
     *   accumulated data is written to the file by writeUpdatesToFile()
     */
    public function update()
    {
    }

    /**
     * Returns an api instance set up for this node. Will return false if that type of API is unavailable.
     * @param string $type The type of API to establish - must be available as a service with the name "magento_{type}"
     * @return object|false
     */
    public function getApi($type)
    {
        if (!isset($this->_api[$type])) {

            $this->_api[$type] = $this->getServiceLocator()->get('retailex_'.$type);
            $message = 'Creating API instance '.$type;
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_INFO,
                    $this->getNodeLogPrefix().'init_api',
                    $message,
                    array('type'=>$type),
                    array('node'=>$this)
                );

            $result = $this->_api[$type]->init($this);
            if (!$result) {
                $this->_api[$type] = FALSE;
            }
        }

        return $this->_api[$type];
    }

    /**
     * Returns an instance of a subclass of AbstractGateway that can handle the provided entity type.
     * @throws MagelinkException
     * @param string $entity_type
     * @return AbstractGateway|NULL $gateway
     */
    protected function _createGateway($entityType)
    {
        switch($entityType) {
            case 'customer':
            case 'address':
                $gateway = new Gateway\CustomerGateway;
                break;
            case 'product':
                $gateway = new Gateway\ProductGateway;
                break;
            case 'order':
            case 'orderitem':
                $gateway = new Gateway\OrderGateway;
                break;
            default:
                throw new SyncException('Unknown/invalid entity type '.$entityType);
                $gateway = NULL;
        }

        return $gateway;
    }

    /**
     * Returns the value of a config setting for this node, or if no key specified, all keys
     * @param string|null $key
     * @return null
     */
    public function getConfig($key = NULL)
    {
        // @todo: Check if === would be more appropriate
        if ($key == NULL) {
            return $this->_config;
        }elseif (isset($this->_config[$key])) {
            return $this->_config[$key];
        }else{
            return NULL;
        }
    }

}
