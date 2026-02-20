<?php

namespace Reservepay\Payment\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Reservepay\Payment\Service\WebhookHandler;
use Psr\Log\LoggerInterface;

class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
  protected $jsonFactory;
  protected $request;
  protected $jsonSerializer;
  protected $scopeConfig;
  protected $encryptor;
  protected $webhookHandler;
  protected $logger;

  public function __construct(
    JsonFactory $jsonFactory,
    RequestInterface $request,
    JsonSerializer $jsonSerializer,
    ScopeConfigInterface $scopeConfig,
    EncryptorInterface $encryptor,
    WebhookHandler $webhookHandler,
    LoggerInterface $logger
  ) {
    $this->jsonFactory = $jsonFactory;
    $this->request = $request;
    $this->jsonSerializer = $jsonSerializer;
    $this->scopeConfig = $scopeConfig;
    $this->encryptor = $encryptor;
    $this->webhookHandler = $webhookHandler;
    $this->logger = $logger;
  }

  public function execute()
  {
    $result = $this->jsonFactory->create();

    try {
      $rawBody = $this->request->getContent();
      if (!$rawBody) {
        return $result->setHttpResponseCode(400)->setData([
          'status' => 'error',
          'message' => 'Empty request body',
        ]);
      }

      // Signature verification (commented out until signing key is configured)
      // $signature = $this->request->getHeader('X-Reservepay-Signature');
      // if (!$this->verifySignature($rawBody, $signature)) {
      //     $this->logger->warning('Webhook: Invalid signature');
      //     return $result->setHttpResponseCode(400)->setData([
      //         'status' => 'error',
      //         'message' => 'Invalid signature',
      //     ]);
      // }

      $data = $this->jsonSerializer->unserialize($rawBody);
      if (!is_array($data)) {
        return $result->setHttpResponseCode(400)->setData([
          'status' => 'error',
          'message' => 'Invalid JSON payload',
        ]);
      }

      $response = $this->webhookHandler->handleEvent($data);

      return $result->setHttpResponseCode(200)->setData($response);

    } catch (\InvalidArgumentException $e) {
      $this->logger->warning('Webhook: Bad request - ' . $e->getMessage());
      return $result->setHttpResponseCode(400)->setData([
        'status' => 'error',
        'message' => $e->getMessage(),
      ]);
    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
      $this->logger->warning('Webhook: Order not found - ' . $e->getMessage());
      return $result->setHttpResponseCode(400)->setData([
        'status' => 'error',
        'message' => $e->getMessage(),
      ]);
    } catch (\Exception $e) {
      $this->logger->critical('Webhook processing error: ' . $e->getMessage());
      return $result->setHttpResponseCode(500)->setData([
        'status' => 'error',
        'message' => 'Internal processing error',
      ]);
    }
  }

  /**
   * Verify webhook signature.
   * Format: sha256={hash} where hash = hash('sha256', $rawBody . $signingKey)
   */
  protected function verifySignature($rawBody, $signature)
  {
    if (!$signature) {
      return false;
    }

    $encrypted = $this->scopeConfig->getValue(
      'payment/reservepay_payment/webhook_signing_key',
      ScopeInterface::SCOPE_STORE
    );
    if (!$encrypted) {
      $this->logger->warning('Webhook: No signing key configured');
      return false;
    }

    try {
      $signingKey = $this->encryptor->decrypt($encrypted);
    } catch (\Exception $e) {
      $this->logger->critical('Webhook: Failed to decrypt signing key');
      return false;
    }

    $expectedHash = hash('sha256', $rawBody . $signingKey);
    $expected = 'sha256=' . $expectedHash;

    return hash_equals($expected, $signature);
  }

  /**
   * @inheritDoc
   */
  public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
  {
    return null;
  }

  /**
   * @inheritDoc
   */
  public function validateForCsrf(RequestInterface $request): ?bool
  {
    return true;
  }
}
