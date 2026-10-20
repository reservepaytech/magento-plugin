<?php

namespace Reservepay\Payment\Model\Api;

use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use Reservepay\Payment\Model\ClientInfo;
use Reservepay\Payment\Model\Config;

class Client
{
    public const API_VERSION = '2025-04-01';
    private const TIMEOUT_SECONDS = 30;
    private const CONNECT_TIMEOUT_SECONDS = 10;
    // The checkout page waits for this call when its cache is cold.
    private const CHECKOUT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return string the Reservepay payment id
     */
    public function initiatePaymentFlow(int $storeId, array $args): string
    {
        $body = $this->post($storeId, 'merchants/initiate-payment-flow', $args);
        if (!is_string($body) || $body === '') {
            $this->logger->error('Reservepay initiate-payment-flow returned no payment id');
            throw new ApiException(ApiException::BAD_RESPONSE, 'initiate-payment-flow returned no payment id');
        }
        return $body;
    }

    /**
     * Accepts a Reservepay payment id or our external_id: find-payment resolves either from its payment_id argument.
     */
    public function findPayment(int $storeId, string $paymentIdOrExternalId): FoundPayment
    {
        $body = $this->post($storeId, 'merchants/find-payment', ['payment_id' => $paymentIdOrExternalId]);
        try {
            return FoundPayment::fromResponse($body);
        } catch (ApiException $e) {
            $this->logger->error('Reservepay find-payment returned an unexpected shape', ['id' => $paymentIdOrExternalId]);
            throw $e;
        }
    }

    /**
     * The installation's payment methods, from the endpoint the SDK itself calls. It is public, so no API key is sent.
     */
    public function retrieveInstallationSettings(int $storeId, string $merchantId, string $installationId): array
    {
        $body = $this->request($storeId, 'sdk/retrieve-installation-settings', [
            'merchant_id' => $merchantId,
            'installation_id' => $installationId,
        ], [], self::CHECKOUT_TIMEOUT_SECONDS);
        if (!is_array($body) || !is_array($body['payment_methods'] ?? null)) {
            $this->logger->error('Reservepay retrieve-installation-settings returned an unexpected shape');
            throw new ApiException(ApiException::BAD_RESPONSE, 'retrieve-installation-settings returned an unexpected shape');
        }
        return $body;
    }

    private function post(int $storeId, string $path, array $args): mixed
    {
        $apiKey = $this->config->apiKey($storeId);
        if ($apiKey === '') {
            $this->logger->critical('Reservepay API key is missing or cannot be decrypted', ['store_id' => $storeId]);
            throw new ApiException(ApiException::CONFIG, 'Reservepay is not configured');
        }
        return $this->request($storeId, $path, $args, ['Authorization' => 'Bearer ' . $apiKey], self::TIMEOUT_SECONDS);
    }

    private function request(int $storeId, string $path, array $args, array $headers, int $timeout): mixed
    {
        $curl = $this->curlFactory->create();
        $curl->setHeaders($headers + [
            'Api-Version' => self::API_VERSION,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => ClientInfo::TOKEN,
            'Client-Version' => ClientInfo::TOKEN,
        ]);
        $curl->setTimeout($timeout);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, min($timeout, self::CONNECT_TIMEOUT_SECONDS));

        try {
            $curl->post(rtrim($this->config->apiBaseUrl($storeId), '/') . '/' . $path, json_encode($args, JSON_THROW_ON_ERROR));
        } catch (\Exception $e) {
            $this->logger->warning('Reservepay request failed in transport', ['path' => $path, 'error' => $e->getMessage()]);
            throw new ApiException(ApiException::TRANSPORT, 'Reservepay request failed in transport');
        }

        $status = $curl->getStatus();
        try {
            $body = json_decode($curl->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('Reservepay returned a body that is not JSON', ['path' => $path, 'http_status' => $status]);
            throw new ApiException(ApiException::BAD_RESPONSE, 'Reservepay returned a body that is not JSON');
        }

        if ($status === 200) {
            return $body;
        }

        $isTriple = is_array($body) && is_string($body[0] ?? null);
        $code = $isTriple ? $body[0] : 'HTTP_' . $status;
        $this->logger->warning('Reservepay returned an error', [
            'path' => $path,
            'http_status' => $status,
            'code' => $code,
            'message' => $isTriple && is_string($body[1] ?? null) ? $body[1] : null,
        ]);
        throw new ApiException($code, 'Reservepay returned ' . $code);
    }
}
