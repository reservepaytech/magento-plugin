<?php

namespace Reservepay\Payment\Model\Api;

/**
 * Magento's cURL client with the cURL error number as the exception code. Magento's own throws only the message,
 * and Client needs the number to tell a request that never left from one that may have reached Reservepay.
 */
class Curl extends \Magento\Framework\HTTP\Client\Curl
{
    public function doError($string)
    {
        // phpcs:ignore Magento2.Exceptions.DirectThrow
        throw new \Exception($string, curl_errno($this->_ch));
    }
}
