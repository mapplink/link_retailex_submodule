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
use Retailex\Api\Soap\Client;
use Retailex\Service\RetailexConfigService;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;


class Soap extends SoapCurl
{

    /** const SOAP_NAMESPACE = 'http://retailexpress.com.au/'; */
    /** const SOAP_NAME = 'ClientHeader'; */

    /** @var Node|NULL $this->node */
    /** @var Client|NULL $this->soapClient */
    protected $soapClient = NULL;

    /** @var ServiceLocatorInterface $this->serviceLocator */

    /** @var resource|FALSE|NULL $this->curlHandle */
    /** @var string|NULL $this->authorisation */
    /** @var string|NULL $this->requestType */
    /** @var Request $this->request */
    /** @var array $this->curlOptions */
    /** @var array $this->baseCurlOptions */
    /** @var array $this->clientOptions */


    /**
     * @return string $apiType
     */
    public function getApiType()
    {
        return 'soap';
    }

    /**
     * @param array $header
     * @return NULL|Client $this->soapClient
     */
    protected function storeSoapClient(array $header)
    {
        $url = trim(trim($this->node->getConfig('retailex-url')), '/').'/'
            .ltrim(trim($this->node->getConfig('retailex-wsdl')), '/');
        $soapHeader = new \SoapHeader(self::SOAP_NAMESPACE, self::SOAP_NAME, $header);

        $this->soapClient = new Client($url, array('soap_version'=>SOAP_1_2));
        $this->soapClient->addSoapInputHeader($soapHeader);

        return (bool) $this->soapClient;
    }

    /**
     * Sets up the SOAP API, connects to Retail Express, and performs a login.
     * @return bool Whether we successfully connected
     * @throws MagelinkException If this API has already been initialized
     */
    protected function _init()
    {
        $success = FALSE;

        if (is_null($this->node)) {
            throw new MagelinkException('Retail Express node is not available on the SOAP API!');
        }elseif (!is_null($this->soapClient)) {
            throw new MagelinkException('Tried to initialize Soap API twice!');
        }else{
            /** @var RetailexConfigService $retailexConfigService */
            $retailexConfigService = $this->getServiceLocator()->get('retailexConfigService');
            $soapheaderConfigMap = $retailexConfigService->getSoapheaderConfigMap();

            $soapHeaders = array();
            $soapheaderFields = array();
            $allSoapheaderFieldsSet = TRUE;

            foreach ($soapheaderConfigMap as $soapheaderKey=>$configKey) {
                $soapHeaders[$soapheaderKey] = $this->node->getConfig($configKey);
                $soapheaderFields[] = $soapheaderKey;
                $allSoapheaderFieldsSet = $allSoapheaderFieldsSet && (strlen($soapHeaders[$soapheaderKey]) > 0);
            }

            $logLevel = LogService::LEVEL_ERROR;
            $logCode = 'rex_isoap';
            $logData = array('soap header'=>$soapHeaders);

            if (!$allSoapheaderFieldsSet) {
                $logCode .= '_fail';
                $logMessage = 'SOAP initialisation failed: Please check '.implode(', ', $soapheaderFields).'.';
            }else{
                $success = $this->storeSoapClient($soapHeaders);
                $logLevel = LogService::LEVEL_INFO;
                $logMessage = 'SOAP was sucessfully initialised.';
            }

            $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $logMessage, $logData);
        }

        return $success;
    }

    /**
     * Make a call to SOAP API, automatically adding required headers/sessions/etc and processing response
     * @param string $call The name of the call to make
     * @param array $data The data to be passed to the call (as associative/numerical arrays)
     * @throws \SoapFault
     * @return array|mixed Response data
     */
    public function call($call, $data)
    {
        $retry = FALSE;
        do {
            try{
                $result = $this->_call($call, $data);
                $success = TRUE;
            }catch(MagelinkException $exception) {
                $success = FALSE;
                $retry = !$retry;
                $soapFault = $exception->getPrevious();

                if ($retry === TRUE && (strpos(strtolower($soapFault->getMessage()), 'session expired') !== FALSE
                    || strpos(strtolower($soapFault->getMessage()), 'try to relogin') !== FALSE)) {

                    $this->soapClient = NULL;
                    $this->_init();
                }
            }
        }while ($retry === TRUE && $success === FALSE);

        if ($success !== TRUE) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR,
                    'rex_soap_fault',
                    $exception->getMessage(),
                    array(
                        'data'=>$data,
                        'code'=>$soapFault->getCode(),
                        'trace'=>$soapFault->getTraceAsString(),
//                        'request'=>$this->soapClient->getLastRequest(),
//                        'response'=>$this->soapClient->getLastResponse()
                ));

            throw $exception;
            $result = NULL;
        }else{
            $result = $this->_processResponse($result);
            /* ToDo: Investigate if that could be centralised
            if (isset($result['result'])) {
                $result = $result['result'];
            }*/

            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUG, 'rex_soap_success', 'Successfully soap call: '.$call,
                    array('call'=>$call, 'data'=>$data, 'result'=>$result));
        }

        return $result;
    }

    /**
     * Make a call to SOAP API, automatically adding required headers/sessions/etc and processing response
     * @param string $call The name of the call to make
     * @param array $data The data to be passed to the call (as associative/numerical arrays)
     * @throws \SoapFault
     * @return array|mixed Response data
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
                    'rex_soap_call',
                    'Successful SOAP call '.$call.'.',
                    array('data'=>$data, 'result'=>$result)
                );
        }catch (\SoapFault $soapFault) {
            throw new MagelinkException('SOAP Fault with call '.$call.': '.$soapFault->getMessage(), 0, $soapFault);
        }

        return $result;
    }

    /**
     * Processes response from SOAP api to convert all std_class object structures to associative/numerical arrays
     * @param mixed $array
     * @return array
     */
    protected function _processResponse($array)
    {
        if (is_object($array)) {
            $array = get_object_vars($array);
        }

        $result = $array;
        if (is_array($array)) {
            foreach ($result as $key=>$value) {
                if (is_object($value) || is_array($value)){
                    $result[$key] = $this->_processResponse($value);
                }
            }
        }

        if (is_object($result)) {
            $result = get_object_vars($result);
        }

        return $result;
    }

}
