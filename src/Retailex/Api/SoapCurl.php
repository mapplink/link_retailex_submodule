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
use Retailex\Service\RetailexConfigService;
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
    protected function initCurl()
    {
        if (is_null($this->curlHandle)) {
            $this->curlHandle = curl_init();
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

            $logCode = 'rex_socu_i';
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
     * @param string $key
     * @param mixed $value
     * @param bool $setPrefix
     * @return string
     */
    protected function getXmlElementString($data, $setPrefix = TRUE)
    {
        $elementString = '';

        if (is_bool($setPrefix) && $setPrefix) {
            $prefix = 'ret:';
        }else{
            $prefix = '';
        }

        foreach ($data as $key=>$value) {
            if (isset($value) && (is_scalar($value) && strlen((string) $value) > 0 || is_array($value))) {
                if (strpos($key, '<') !== FALSE) {
                    $key = strstr($key, '<', TRUE);
                }
                $elementString .= '<'.$prefix.$key.'>';

                if (isset($value) && is_array($value)) {
                    $xml = substr($key, -3) == 'XML';

                    if ($xml) {
                        $setPrefix = FALSE;
                        $elementString .= '<![CDATA[';
                        $postfix = ']]>';
                    }else {
                        $postfix = '';
                    }

                    $elementString .= $this->getXmlElementString($value, $setPrefix).$postfix;
                }else{
                    $elementString .= $value;
                }

                $elementString .= '</'.$prefix.$key.'>';
            }
        }

        return $elementString;
    }

    /**
     * @param string $call
     * @param array $data
     * @throws \SoapFault
     * @return bool $success
     */
    protected function prepareCall($call, array $data)
    {
        $url = trim(trim($this->node->getConfig('retailex-url')), '/').'/'
            .ltrim(trim($this->node->getConfig('retailex-wsdl')), '/');
        /** @var RetailexConfigService $retailexConfigService */
        $retailexConfigService = $this->getServiceLocator()->get('retailexConfigService');
        $headerConfigMap = $retailexConfigService->getSoapheaderConfigMap();

        $curlHeaders = $soapHeaderArray = array();
        $allHeaderFieldsSet = TRUE;
        if (isset($this->baseCurlOptions[CURLOPT_HTTPHEADER])) {
            $curlHeaders = $this->baseCurlOptions[CURLOPT_HTTPHEADER];
        }

        foreach ($headerConfigMap as $headerKey=>$configKey) {
            $value = $this->node->getConfig($configKey);
            $curlHeader = $headerKey.': '.$value;
            $soapHeaderArray[$headerKey] = $value;
            $curlHeaders[] = $curlHeader;
            $allHeaderFieldsSet = $allHeaderFieldsSet && (strlen($curlHeader) > 0);
        }

        $soapHeader = $this->getXmlElementString($soapHeaderArray);
        $soapBody = $this->getXmlElementString($data);
        $preparationSuccessful = $allHeaderFieldsSet && strlen($soapHeader) > 0 && strlen($soapBody) > 0;

        if ($preparationSuccessful) {
            $soapEnvelope = '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ret="'
                .self::SOAP_NAMESPACE.'">'
                    .'<soap:Header><ret:ClientHeader>'.$soapHeader.'</ret:ClientHeader></soap:Header>'
                    .'<soap:Body><ret:'.$call.'>'.$soapBody.'</ret:'.$call.'></soap:Body>'
                .'</soap:Envelope>';
            $this->curlOptions = array_replace_recursive(
                $this->baseCurlOptions,
                array(
                    CURLOPT_URL=>$url,
                    CURLOPT_HTTPHEADER=>$curlHeaders,
                    CURLOPT_POSTFIELDS=>$soapEnvelope
                )
            );
            curl_setopt_array($this->curlHandle, $this->curlOptions);
        }else{
            $preparationSuccessful = FALSE;
        }

        return $preparationSuccessful;
    }

    /**
     * @param string $call
     * @param array $data
     * @return \SimpleXMLElement|NULL $responseXml
     */
    public function call($call, array $data)
    {
        libxml_use_internal_errors(TRUE);
        $success = FALSE;
        $retry = FALSE;

        $logCode = 'rex_socu';
        $logData = $logData = array('call'=>$call, 'data'=>$data);

        do {
            try{
                if ($this->prepareCall($call, $data)) {
                    $response = curl_exec($this->curlHandle);
                    $error = curl_error($this->curlHandle);
                }else{
                    $error = 'Curl preparation failed.';
                }

                $logData = array_merge($logData, array(
                    'options'=>$this->curlOptions,
                    'info'=>curl_getinfo($this->curlHandle),
                    'response'=>mb_substr($response, 0, 1024)
                ));

                $logCode .= substr(strtolower($this->requestType), 0, 2);
                if ($error) {
                    $error = 'Call failed. ERROR: '.$error;
                    $logData['error'] = $error;
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_ERROR, $logCode.'_cerr', $error, $logData);
                    $responseXml = NULL;
                }else{
                    if (is_array($response) && isset($response[$call]['any'])) {
                        $response = $response[$call]['any'];
                    }

                    preg_match('#<soap:Fault>.*?</soap:Fault>#ism', $response, $soapFaultMatches);
                    preg_match('#<soap:Body>(.*?)</soap:Body>#ism', $response, $responseMatches);

                    $hasBody = isset($responseMatches[1]) && strlen($responseMatches[1]) > 0;
                    $hasFaults = isset($soapFaultMatches[1]) && strlen($soapFaultMatches[1]) > 0;

                    if (!$hasBody && !$hasFaults && strlen($response) > 0) {
                        if ($unGzipped = @gzdecode($response)) {
                            $soapFaultMatches = NULL;
                            $response = $unGzipped;
                            $responseMatches = array(1=>$response);
                        }
                    }

                    $logData['response'] = mb_substr($response, 0, 1024);

                    try{
                        if (isset($soapFaultMatches[0])) {
                            $responseXml = NULL;
                            $soapFaultObject = new \SimpleXMLElement(str_replace('soap:', '', $soapFaultMatches[0]));
                        }elseif (isset($responseMatches[1])) {
                            $responseXml = new \SimpleXMLElement($responseMatches[1]);
                            $soapFaultObject = NULL;
                        }else{
                            $responseXml = NULL;
                            $soapFaultObject = (object) array(
                                'Code'=>(object) array('Value'=>'ukwn'),
                                'Reason'=>(object) array('Text'=>'MLERR: Unknown problem with the '.$call.' soap call.')
                            );
                        }
                    }catch (\Exception $exception) {
                        $responseXml = NULL;
                        $soapFaultObject = (object) array(
                            'Code'=>(object) array('Value'=>$exception->getCode()),
                            'Reason'=>(object) array('Text'=>$exception->getMessage())
                        );
                    }

                    if (isset($soapFaultObject)) {
                        $error = '['.$soapFaultObject->Code->Value.'] '.$soapFaultObject->Reason->Text;
                        $error = preg_replace('#\v+#', ' \ ', $error);
                    }elseif (isset($responseXml)) {
                        $error = '';
                    }else{
                        $error = 'No valid response from Retail Express.';
                    }

                    if (strlen($error) == 0) {
                        $success = TRUE;
                    }else{
                        $error = 'Curl call '.$call.' failed. Error message: '.$error;
                        $logData['error'] = $error;
                    }
                }
            }catch (MagelinkException $exception) {
                $success = FALSE;
                $error = trim(curl_error($this->curlHandle).' '.$exception->getMessage());
                $retry = !$retry;
            }
        }while ($retry === TRUE && $success === FALSE);

        if ($success === TRUE) {
            $logLevel = LogService::LEVEL_INFO;
            $logCode .= '_suc';
            $message = 'Successful soap curl call: '.$call;
            unset($logData['data']);
        }else{
            $logLevel = LogService::LEVEL_ERROR;
            $logCode .= '_fault';
            $message = $error;
            $responseXml = NULL;
        }
        $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $message, $logData);

        return $responseXml;
    }

}
