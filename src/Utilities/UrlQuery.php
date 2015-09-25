<?php

namespace allejo\DaPulse\Utilities;

use allejo\DaPulse\Exceptions\CurlException;
use allejo\DaPulse\Exceptions\HttpException;

class UrlQuery
{
    const DEFAULT_PROTOCOL = "https";

    private $url;
    private $cURL;
    private $token;
    private $headers;
    private $oAuth2Token;

    public function __construct ($url, $token = "", $email = "", $password = "")
    {
        $this->url   = $url;
        $this->token = $token;
        $this->cURL  = curl_init();

        // Build up the headers we'll need to pass
        $this->headers = array(
            'Accept: application/json',
            'Content-type: application/json',
            'X-App-Token: ' . $this->token
        );

        $this->configureCurl($email, $password);
    }

    public function __destruct ()
    {
        curl_close($this->cURL);
    }

    public function setOAuth2Token ($token)
    {
        if (!StringUtilities::isNullOrEmpty($token))
        {
            $this->oAuth2Token = $token;
            $this->headers[]   = "Authorization: OAuth " . $this->oAuth2Token;
        }
    }

    public function setParameters ($params)
    {
        $parameters = array();

        foreach ($params as $key => $value)
        {
            $parameters[] = rawurlencode($key) . "=" . rawurlencode($value);
        }

        return $parameters;
    }

    public function sendGet ($params, $associativeArray, &$headers = NULL)
    {
        if (is_array($params))
        {
            $parameters = $this->setParameters($params);
            $full_url   = self::buildQuery($this->url, $parameters);
        }
        else if (!empty($params))
        {
            $full_url = $this->url . "?" . $params;
        }
        else
        {
            $full_url = $this->url;
        }

        curl_setopt($this->cURL, CURLOPT_URL, $full_url);

        return $this->handleQuery($associativeArray, $headers);
    }

    public function sendPost ($dataAsJson, $associativeArray, &$headers = NULL)
    {
        $this->setPostFields($dataAsJson);

        curl_setopt_array($this->cURL, array(
            CURLOPT_POST => true,
            CURLOPT_CUSTOMREQUEST => "POST"
        ));

        return $this->handleQuery($associativeArray, $headers);
    }

    public function sendPut ($dataAsJson, $associativeArray, &$headers = NULL)
    {
        $this->setPostFields($dataAsJson);

        curl_setopt($this->cURL, CURLOPT_CUSTOMREQUEST, "PUT");

        return $this->handleQuery($associativeArray, $headers);
    }

    public function sendDelete ($dataAsJson, $associativeArray, &$headers = NULL)
    {
        $this->setPostFields($dataAsJson);

        curl_setopt($this->cURL, CURLOPT_CUSTOMREQUEST, "DELETE");

        return $this->handleQuery($associativeArray, $headers);
    }

    private function setPostFields ($dataAsJson)
    {
        curl_setopt($this->cURL, CURLOPT_POSTFIELDS, $dataAsJson);
    }

    private function handleQuery ($associativeArray, &$headers)
    {
        $result = $this->executeCurl();

        list($header, $body) = explode("\r\n\r\n", $result, 2);

        $this->saveHeaders($header, $headers);

        $resultArray = $this->handleResponseBody($body, $result);

        return ($associativeArray) ? $resultArray : json_decode($body, false);
    }

    private function configureCurl ($email, $password)
    {
        curl_setopt_array($this->cURL, array(
            CURLOPT_URL => $this->url,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_RETURNTRANSFER => true
        ));

        if (!StringUtilities::isNullOrEmpty($email) && !StringUtilities::isNullOrEmpty($password))
        {
            curl_setopt_array($this->cURL, array(
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $email . ":" . $password
            ));
        }
    }

    private function executeCurl ()
    {
        $result = curl_exec($this->cURL);

        if (!$result)
        {
            throw new CurlException($this->cURL);
        }

        return $result;
    }

    private function handleResponseBody ($body, $result)
    {
        // We somehow got a server error from Socrata without a JSON object with details
        if (!StringUtilities::isJson($body))
        {
            $httpCode = curl_getinfo($this->cURL, CURLINFO_HTTP_CODE);

            throw new HttpException($httpCode, $result);
        }

        $resultArray = json_decode($body, true);

        // We got an error JSON object back from Socrata
        if (array_key_exists('error', $resultArray) && $resultArray['error'])
        {
            throw new SodaException($resultArray);
        }

        return $resultArray;
    }

    private function saveHeaders ($header, &$headers)
    {
        if ($headers === NULL)
        {
            return;
        }

        $header       = explode("\r\n", $header);
        $headers      = array();
        $headerLength = count($header);

        // The 1st element is the HTTP code, so we can safely skip it
        for ($i = 1; $i < $headerLength; $i++)
        {
            list($key, $val) = explode(":", $header[$i]);
            $headers[$key] = trim($val);
        }
    }

    public static function buildQuery ($url, $params = array())
    {
        $full_url = $url;

        if (count($params) > 0)
        {
            $full_url .= "?" . implode("&", $params);
        }

        return $full_url;
    }
}
