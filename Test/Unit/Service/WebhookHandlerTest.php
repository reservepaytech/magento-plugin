<?php

namespace Reservepay\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Reservepay\Payment\Service\WebhookHandler;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteria;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\Data\OrderPaymentSearchResultInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;

class WebhookHandlerTest extends TestCase
{
  /** @var WebhookHandler */
  private $handler;

  /** @var OrderRepositoryInterface|MockObject */
  private $orderRepository;

  /** @var OrderManagementInterface|MockObject */
  private $orderManagement;

  /** @var InvoiceService|MockObject */
  private $invoiceService;

  /** @var InvoiceSender|MockObject */
  private $invoiceSender;

  /** @var CreditmemoFactory|MockObject */
  private $creditmemoFactory;

  /** @var CreditmemoService|MockObject */
  private $creditmemoService;

  /** @var Transaction|MockObject */
  private $transaction;

  /** @var SearchCriteriaBuilder|MockObject */
  private $searchCriteriaBuilder;

  /** @var OrderPaymentRepositoryInterface|MockObject */
  private $paymentRepository;

  /** @var LoggerInterface|MockObject */
  private $logger;

  protected function setUp(): void
  {
    $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
    $this->orderManagement = $this->createMock(OrderManagementInterface::class);
    $this->invoiceService = $this->createMock(InvoiceService::class);
    $this->invoiceSender = $this->createMock(InvoiceSender::class);
    $this->creditmemoFactory = $this->createMock(CreditmemoFactory::class);
    $this->creditmemoService = $this->createMock(CreditmemoService::class);
    $this->transaction = $this->createMock(Transaction::class);
    $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
    $this->paymentRepository = $this->createMock(OrderPaymentRepositoryInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->handler = new WebhookHandler(
      $this->orderRepository,
      $this->orderManagement,
      $this->invoiceService,
      $this->invoiceSender,
      $this->creditmemoFactory,
      $this->creditmemoService,
      $this->transaction,
      $this->searchCriteriaBuilder,
      $this->paymentRepository,
      $this->logger
    );
  }

  public function testHandleEventMissingFieldsThrowsException()
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->handler->handleEvent(['event_type' => 'payment.completed']);
  }

  public function testHandleEventMissingEventTypeThrowsException()
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->handler->handleEvent(['payment_id' => 'pay_123', 'event_id' => 'evt_1']);
  }

  public function testHandleEventUnknownTypeReturnsIgnored()
  {
    $result = $this->handler->handleEvent([
      'event_type' => 'payment.unknown',
      'payment_id' => 'pay_123',
      'event_id' => 'evt_1',
    ]);

    $this->assertEquals('ignored', $result['status']);
  }

  public function testHandlePaymentCompletedSuccessfulCreatesInvoice()
  {
    $order = $this->createOrderMock(34, Order::STATE_PENDING_PAYMENT, true);
    $payment = $this->createPaymentMock('pay_123', null);
    $order->method('getPayment')->willReturn($payment);

    $this->setupFindOrderByPaymentId('pay_123', $payment, 34, $order);

    $invoice = $this->createMock(Invoice::class);
    $invoice->method('getOrder')->willReturn($order);
    $invoice->method('getIncrementId')->willReturn('INV001');
    $this->invoiceService->method('prepareInvoice')->willReturn($invoice);
    $this->transaction->method('addObject')->willReturnSelf();

    $statusHistory = $this->createMock(\Magento\Sales\Model\Order\Status\History::class);
    $statusHistory->method('setIsCustomerNotified')->willReturnSelf();
    $statusHistory->method('save')->willReturnSelf();
    $order->method('addCommentToStatusHistory')->willReturn($statusHistory);

    $result = $this->handler->handlePaymentCompleted('pay_123', 'SUCCESSFUL', 'evt_1');

    $this->assertEquals('invoiced', $result['status']);
  }

  public function testHandlePaymentCompletedFailedCancelsOrder()
  {
    $order = $this->createOrderMock(34, Order::STATE_PENDING_PAYMENT, true);
    $payment = $this->createPaymentMock('pay_123', null);
    $order->method('getPayment')->willReturn($payment);

    $this->setupFindOrderByPaymentId('pay_123', $payment, 34, $order);

    $this->orderManagement->expects($this->once())->method('cancel')->with(34);

    $result = $this->handler->handlePaymentCompleted('pay_123', 'FAILED', 'evt_1');

    $this->assertEquals('cancelled', $result['status']);
  }

  public function testHandlePaymentCompletedIdempotent()
  {
    $order = $this->createOrderMock(34, Order::STATE_PROCESSING, false);
    $payment = $this->createPaymentMock('pay_123', 'evt_1');
    $order->method('getPayment')->willReturn($payment);

    $this->setupFindOrderByPaymentId('pay_123', $payment, 34, $order);

    $this->invoiceService->expects($this->never())->method('prepareInvoice');

    $result = $this->handler->handlePaymentCompleted('pay_123', 'SUCCESSFUL', 'evt_1');

    $this->assertEquals('already_processed', $result['status']);
  }

  public function testHandlePaymentVoidedCancelsOrder()
  {
    $order = $this->createOrderMock(34, Order::STATE_PENDING_PAYMENT, true);
    $payment = $this->createPaymentMock('pay_123', null);
    $order->method('getPayment')->willReturn($payment);

    $this->setupFindOrderByPaymentId('pay_123', $payment, 34, $order);

    $this->orderManagement->expects($this->once())->method('cancel')->with(34);

    $result = $this->handler->handlePaymentVoided('pay_123', 'evt_2');

    $this->assertEquals('cancelled', $result['status']);
  }

  public function testHandlePaymentVoidedIdempotent()
  {
    $order = $this->createOrderMock(34, Order::STATE_CANCELED, false);
    $payment = $this->createPaymentMock('pay_123', 'evt_2');
    $order->method('getPayment')->willReturn($payment);

    $this->setupFindOrderByPaymentId('pay_123', $payment, 34, $order);

    $this->orderManagement->expects($this->never())->method('cancel');

    $result = $this->handler->handlePaymentVoided('pay_123', 'evt_2');

    $this->assertEquals('already_processed', $result['status']);
  }

  public function testHandlePaymentReversedCreatesCreditmemo()
  {
    $order = $this->createOrderMock(34, Order::STATE_PROCESSING, false);
    $payment = $this->createPaymentMock('pay_123', null);
    $order->method('getPayment')->willReturn($payment);
    $order->method('hasInvoices')->willReturn(true);

    $invoice = $this->createMock(Invoice::class);
    $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
    $invoiceCollection->method('getFirstItem')->willReturn($invoice);
    $order->method('getInvoiceCollection')->willReturn($invoiceCollection);

    $this->setupFindOrderByPaymentId('pay_123', $payment, 34, $order);

    $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
    $this->creditmemoFactory->method('createByOrder')->willReturn($creditmemo);

    $statusHistory = $this->createMock(\Magento\Sales\Model\Order\Status\History::class);
    $statusHistory->method('save')->willReturnSelf();
    $order->method('addCommentToStatusHistory')->willReturn($statusHistory);

    $result = $this->handler->handlePaymentReversed('pay_123', 'evt_3');

    $this->assertEquals('refunded', $result['status']);
  }

  public function testHandlePaymentReversedNoInvoicesThrows()
  {
    $order = $this->createOrderMock(34, Order::STATE_PROCESSING, false);
    $payment = $this->createPaymentMock('pay_123', null);
    $order->method('getPayment')->willReturn($payment);
    $order->method('hasInvoices')->willReturn(false);

    $this->setupFindOrderByPaymentId('pay_123', $payment, 34, $order);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('no invoices');

    $this->handler->handlePaymentReversed('pay_123', 'evt_3');
  }

  public function testFindOrderByPaymentIdNotFoundThrows()
  {
    $searchCriteria = $this->createMock(SearchCriteria::class);
    $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
    $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

    $searchResult = $this->createMock(OrderPaymentSearchResultInterface::class);
    $searchResult->method('getItems')->willReturn([]);
    $this->paymentRepository->method('getList')->willReturn($searchResult);

    $this->expectException(\Magento\Framework\Exception\NoSuchEntityException::class);

    $this->handler->findOrderByPaymentId('pay_nonexistent');
  }

  public function testHandleEventRoutesToPaymentCompleted()
  {
    $order = $this->createOrderMock(34, Order::STATE_PENDING_PAYMENT, true);
    $payment = $this->createPaymentMock('pay_123', null);
    $order->method('getPayment')->willReturn($payment);

    $this->setupFindOrderByPaymentId('pay_123', $payment, 34, $order);

    $invoice = $this->createMock(Invoice::class);
    $invoice->method('getOrder')->willReturn($order);
    $invoice->method('getIncrementId')->willReturn('INV001');
    $this->invoiceService->method('prepareInvoice')->willReturn($invoice);
    $this->transaction->method('addObject')->willReturnSelf();

    $statusHistory = $this->createMock(\Magento\Sales\Model\Order\Status\History::class);
    $statusHistory->method('setIsCustomerNotified')->willReturnSelf();
    $statusHistory->method('save')->willReturnSelf();
    $order->method('addCommentToStatusHistory')->willReturn($statusHistory);

    $result = $this->handler->handleEvent([
      'event_type' => 'payment.completed',
      'payment_id' => 'pay_123',
      'status' => 'SUCCESSFUL',
      'event_id' => 'evt_1',
    ]);

    $this->assertEquals('invoiced', $result['status']);
  }

  // --- Helpers ---

  private function createOrderMock($orderId, $state, $canCancel)
  {
    $order = $this->createMock(Order::class);
    $order->method('getId')->willReturn($orderId);
    $order->method('getState')->willReturn($state);
    $order->method('canCancel')->willReturn($canCancel);
    $order->method('canInvoice')->willReturn($state !== Order::STATE_PROCESSING);
    return $order;
  }

  private function createPaymentMock($paymentId, $processedEventId)
  {
    $payment = $this->createMock(Payment::class);
    $payment->method('getAdditionalInformation')->willReturnCallback(
      function ($key) use ($paymentId, $processedEventId) {
        if ($key === 'reservepay_payment_id') {
          return $paymentId;
        }
        if ($key === 'reservepay_processed_event_id') {
          return $processedEventId;
        }
        return null;
      }
    );
    return $payment;
  }

  private function setupFindOrderByPaymentId($paymentId, $payment, $orderId, $order)
  {
    $searchCriteria = $this->createMock(SearchCriteria::class);
    $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
    $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

    $paymentRecord = $this->createMock(Payment::class);
    $paymentRecord->method('getAdditionalInformation')->willReturn([
      'reservepay_payment_id' => $paymentId,
    ]);
    $paymentRecord->method('getParentId')->willReturn($orderId);

    $searchResult = $this->createMock(OrderPaymentSearchResultInterface::class);
    $searchResult->method('getItems')->willReturn([$paymentRecord]);
    $this->paymentRepository->method('getList')->willReturn($searchResult);

    $this->orderRepository->method('get')->with($orderId)->willReturn($order);
  }
}
