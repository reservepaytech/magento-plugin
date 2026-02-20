<?php

namespace Reservepay\Payment\Test\Unit\Controller\Webhook;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Reservepay\Payment\Controller\Webhook\Callback;
use Reservepay\Payment\Service\WebhookHandler;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Psr\Log\LoggerInterface;

class CallbackTest extends TestCase
{
  /** @var Callback */
  private $controller;

  /** @var JsonFactory|MockObject */
  private $jsonFactory;

  /** @var RequestInterface|MockObject */
  private $request;

  /** @var JsonSerializer|MockObject */
  private $jsonSerializer;

  /** @var ScopeConfigInterface|MockObject */
  private $scopeConfig;

  /** @var EncryptorInterface|MockObject */
  private $encryptor;

  /** @var WebhookHandler|MockObject */
  private $webhookHandler;

  /** @var LoggerInterface|MockObject */
  private $logger;

  /** @var Json|MockObject */
  private $resultJson;

  protected function setUp(): void
  {
    $this->jsonFactory = $this->createMock(JsonFactory::class);
    $this->request = $this->getMockBuilder(RequestInterface::class)
      ->addMethods(['getContent', 'getHeader'])
      ->getMockForAbstractClass();
    $this->jsonSerializer = $this->createMock(JsonSerializer::class);
    $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
    $this->encryptor = $this->createMock(EncryptorInterface::class);
    $this->webhookHandler = $this->createMock(WebhookHandler::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->resultJson = $this->createMock(Json::class);
    $this->resultJson->method('setHttpResponseCode')->willReturnSelf();
    $this->resultJson->method('setData')->willReturnSelf();
    $this->jsonFactory->method('create')->willReturn($this->resultJson);

    $this->controller = new Callback(
      $this->jsonFactory,
      $this->request,
      $this->jsonSerializer,
      $this->scopeConfig,
      $this->encryptor,
      $this->webhookHandler,
      $this->logger
    );
  }

  public function testEmptyBodyReturns400()
  {
    $this->request->method('getContent')->willReturn('');

    $this->resultJson->expects($this->once())
      ->method('setHttpResponseCode')
      ->with(400);

    $this->controller->execute();
  }

  public function testInvalidJsonReturns400()
  {
    $this->request->method('getContent')->willReturn('not json');
    $this->jsonSerializer->method('unserialize')->willReturn('string_not_array');

    $this->resultJson->expects($this->once())
      ->method('setHttpResponseCode')
      ->with(400);

    $this->controller->execute();
  }

  public function testValidPayloadReturns200()
  {
    $payload = '{"event_type":"payment.completed","payment_id":"pay_123","status":"SUCCESSFUL","event_id":"evt_1"}';
    $this->request->method('getContent')->willReturn($payload);
    $this->jsonSerializer->method('unserialize')->willReturn([
      'event_type' => 'payment.completed',
      'payment_id' => 'pay_123',
      'status' => 'SUCCESSFUL',
      'event_id' => 'evt_1',
    ]);

    $this->webhookHandler->method('handleEvent')->willReturn(['status' => 'invoiced']);

    $this->resultJson->expects($this->once())
      ->method('setHttpResponseCode')
      ->with(200);

    $this->controller->execute();
  }

  public function testInvalidArgumentReturns400()
  {
    $payload = '{"event_type":"payment.completed"}';
    $this->request->method('getContent')->willReturn($payload);
    $this->jsonSerializer->method('unserialize')->willReturn([
      'event_type' => 'payment.completed',
    ]);

    $this->webhookHandler->method('handleEvent')
      ->willThrowException(new \InvalidArgumentException('Missing required fields'));

    $this->resultJson->expects($this->once())
      ->method('setHttpResponseCode')
      ->with(400);

    $this->controller->execute();
  }

  public function testOrderNotFoundReturns400()
  {
    $payload = '{"event_type":"payment.completed","payment_id":"pay_999","status":"SUCCESSFUL","event_id":"evt_1"}';
    $this->request->method('getContent')->willReturn($payload);
    $this->jsonSerializer->method('unserialize')->willReturn([
      'event_type' => 'payment.completed',
      'payment_id' => 'pay_999',
      'status' => 'SUCCESSFUL',
      'event_id' => 'evt_1',
    ]);

    $this->webhookHandler->method('handleEvent')
      ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException(__('Not found')));

    $this->resultJson->expects($this->once())
      ->method('setHttpResponseCode')
      ->with(400);

    $this->controller->execute();
  }

  public function testUnexpectedExceptionReturns500()
  {
    $payload = '{"event_type":"payment.completed","payment_id":"pay_123","status":"SUCCESSFUL","event_id":"evt_1"}';
    $this->request->method('getContent')->willReturn($payload);
    $this->jsonSerializer->method('unserialize')->willReturn([
      'event_type' => 'payment.completed',
      'payment_id' => 'pay_123',
      'status' => 'SUCCESSFUL',
      'event_id' => 'evt_1',
    ]);

    $this->webhookHandler->method('handleEvent')
      ->willThrowException(new \Exception('Unexpected error'));

    $this->resultJson->expects($this->once())
      ->method('setHttpResponseCode')
      ->with(500);

    $this->controller->execute();
  }

  public function testCsrfValidationReturnsTrue()
  {
    $request = $this->createMock(RequestInterface::class);
    $this->assertTrue($this->controller->validateForCsrf($request));
  }

  public function testCsrfExceptionReturnsNull()
  {
    $request = $this->createMock(RequestInterface::class);
    $this->assertNull($this->controller->createCsrfValidationException($request));
  }
}
