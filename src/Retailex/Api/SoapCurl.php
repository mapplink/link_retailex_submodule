<?php
/**
 * Implements SOAP access to Retail Express
 * @category Retailex
 * @package Retailex\Api
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Retailex\Api;

use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Retailex\Node;
use Zend\Http\Request;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;


class SoapCurl implements ServiceLocatorAwareInterface
{

    const SOAP_NAMESPACE = 'http://retailexpress.com.au/';
    const SOAP_NAME = 'ClientHeader';

    /** @var Node|NULL $this->node */
    protected $node = NULL;
    /** @var ServiceLocatorInterface $this->serviceLocator */
    protected $serviceLocator;

    /** @var resource|FALSE|NULL $this->curlHandle */
    protected $curlHandle = NULL;
    /** @var string|NULL $this->authorisation */
    protected $authorisation = NULL;
    /** @var string|NULL $this->requestType */
    protected $requestType;
    /** @var  Request $this->request */
    protected $request;
    /** @var array $this->curlOptions */
    protected $curlOptions = array();
    /** @var array $this->baseCurlOptions */
    protected $baseCurlOptions = array(
        CURLOPT_RETURNTRANSFER=>TRUE,
        CURLOPT_ENCODING=>'',
        CURLOPT_MAXREDIRS=>10,
        CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_HTTPHEADER => array('cache-control: no-cache', 'content-type: text/xml')
    );
    /** @var array $this->clientOptions */
    protected $clientOptions = array(
        'adapter'=>'Zend\Http\Client\Adapter\Curl',
        'curloptions'=>array(CURLOPT_FOLLOWLOCATION=>TRUE),
        'maxredirects'=>0,
        'timeout'=>30
    );


    /**
     * Get service locator
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }

    /**
     * Set service locator
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    /**
     * @param Node $retailexNode The Magento node we are representing communications for
     * @return bool Whether we successfully connected
     * @throws MagelinkException If this API has already been initialized
     */
    public function init(Node $retailexNode)
    {
        $this->node = $retailexNode;
        return $this->_init();
    }

    /**
     * @return string $apiType
     */
    public function getApiType()
    {
        return 'soapCurl';
    }

    /**
     * @return FALSE|NULL|resource $this->curlHandle
     */
    protected function initCurl(array $headers)
    {
        if (is_null($this->curlHandle)) {
            $this->curlHandle = curl_init();
        }

        $url = trim(trim($this->node->getConfig('retailex-url')), '/').'/'
            .ltrim(trim($this->node->getConfig('retailex-wsdl')), '/');
        /** @var RetailexConfigService $retailexConfigService */
        $retailexConfigService = $this->getServiceLocator()->get('retailexConfigService');
        $headerConfigMap = $retailexConfigService->getSoapheaderConfigMap();

        $curlHeaders = array();
        $allHeaderFieldsSet = TRUE;

        foreach ($headerConfigMap as $headerKey=>$configKey) {
            $curlHeaders[] = $headerKey.': '.$this->node->getConfig($configKey);
            $allHeaderFieldsSet = $allHeaderFieldsSet && (strlen($headers[$headerKey]) > 0);
        }

        $this->curlOptions = array_replace_recursive(
            $this->baseCurlOptions,
            array(
                CURLOPT_URL=>$url,
                CURLOPT_HTTPHEADER=>array($curlHeaders)
            )
        );

        if (!$allHeaderFieldsSet) {
            $this->curlHandle = FALSE;
        }

        return $this->curlHandle;
    }

    /**
     * @return bool Whether we successfully connected
     * @throws MagelinkException If this API has already been initialized
     */
    protected function _init()
    {
        $success = FALSE;

        if (is_null($this->node)) {
            throw new MagelinkException('Retail Express node is not available on the SOAP API!');
        }elseif (!is_null($this->curlHandle)) {
            throw new MagelinkException('Tried to initialize SoapCurl API twice!');
        }else{
            $success = $this->initCurl() !== FALSE;

            $logCode = 'rex_isocu';
            $logData = array('base curl options'=>$this->curlOptions);

            if ($success) {
                $logLevel = LogService::LEVEL_INFO;
                $logMessage = 'SoapCurl was sucessfully initialised.';
            }else{
                $logLevel = LogService::LEVEL_ERROR;
                $logCode .= '_fail';
                $logMessage = 'SoapCurl initialisation failed.';
            }

            $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $logMessage, $logData);
        }

        return $success;
    }

    /**
     * @param string $call
     * @param array $data
     * @throws \SoapFault
     * @return array|mixed $response
     */
    public function call($call, $data)
    {
        $retry = FALSE;
        do {
            try{
                $response = $this->_call($call, $data);
                $success = TRUE;
            }catch(MagelinkException $exception) {
                $success = FALSE;
                $error = curl_error($this->curlHandle);
                $retry = !$retry;
            }
        }while ($retry === TRUE && $success === FALSE);

        if ($success !== TRUE) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR,
                    'rex_socu_fault',
                    $exception->getMessage(),
                    array(
                        'data'=>$data,
                        'curl error'=>$error,
                        'curl options'=>$this->curlOptions(),
                        'curl response'=>$response
                ));
            // ToDo: Check if this additional logging is necessary
            $this->forceStdoutDebug();
            throw $exception;
            $result = NULL;
        }else{
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUG, 'rex_socu_success', 'Successful soap curl call: '.$call,
                    array('call'=>$call, 'data'=>$data, 'response'=>$response));
        }

        return $response;
    }

    /**
     * @param string $call
     * @param array $data
     * @throws \SoapFault
     * @return array|mixed $response
     */
    protected function _call($call, $data)
    {
        if (!is_array($data)) {
            if (is_object($data)) {
                $data = get_object_vars($data);
            }else{
                $data = array($data);
            }
        }

        try{
            $result = $this->soapClient->call($call, $data);
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUGEXTRA,
                    'rex_socu_call',
                    'Successful SOAP call '.$call.'.',
                    array('data'=>$data, 'result'=>$result)
                );
        }catch (\SoapFault $soapFault) {
            throw new MagelinkException('SOAP Fault with call '.$call.': '.$soapFault->getMessage(), 0, $soapFault);
        }

        return $result;
    }

}
