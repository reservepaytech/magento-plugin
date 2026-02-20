<?php

namespace Reservepay\Payment\Service;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Psr\Log\LoggerInterface;

class WebhookHandler
{
  protected $orderRepository;
  protected $orderManagement;
  protected $invoiceService;
  protected $invoiceSender;
  protected $creditmemoFactory;
  protected $creditmemoService;
  protected $transaction;
  protected $searchCriteriaBuilder;
  protected $paymentRepository;
  protected $logger;

  public function __construct(
    OrderRepositoryInterface $orderRepository,
    OrderManagementInterface $orderManagement,
    InvoiceService $invoiceService,
    InvoiceSender $invoiceSender,
    CreditmemoFactory $creditmemoFactory,
    CreditmemoService $creditmemoService,
    Transaction $transaction,
    SearchCriteriaBuilder $searchCriteriaBuilder,
    OrderPaymentRepositoryInterface $paymentRepository,
    LoggerInterface $logger
  ) {
    $this->orderRepository = $orderRepository;
    $this->orderManagement = $orderManagement;
    $this->invoiceService = $invoiceService;
    $this->invoiceSender = $invoiceSender;
    $this->creditmemoFactory = $creditmemoFactory;
    $this->creditmemoService = $creditmemoService;
    $this->transaction = $transaction;
    $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    $this->paymentRepository = $paymentRepository;
    $this->logger = $logger;
  }

  /**
   * Route webhook event to the appropriate handler.
   *
   * @param array $data Parsed webhook payload
   * @return array Response data
   */
  public function handleEvent(array $data)
  {
    $eventType = $data['event_type'] ?? null;
    $paymentId = $data['payment_id'] ?? null;
    $status = $data['status'] ?? null;
    $eventId = $data['event_id'] ?? null;

    if (!$eventType || !$paymentId || !$eventId) {
      throw new \InvalidArgumentException('Missing required webhook fields: event_type, payment_id, event_id');
    }

    $this->logger->info('Webhook event received', [
      'event_type' => $eventType,
      'payment_id' => $paymentId,
      'event_id' => $eventId,
    ]);

    switch ($eventType) {
      case 'payment.completed':
        return $this->handlePaymentCompleted($paymentId, $status, $eventId);
      case 'payment.voided':
        return $this->handlePaymentVoided($paymentId, $eventId);
      case 'payment.reversed':
        return $this->handlePaymentReversed($paymentId, $eventId);
      default:
        $this->logger->info('Unhandled webhook event type', ['event_type' => $eventType]);
        return ['status' => 'ignored', 'message' => 'Unhandled event type'];
    }
  }

  /**
   * Handle payment.completed event: create invoice on success, cancel on failure.
   */
  public function handlePaymentCompleted($paymentId, $status, $eventId)
  {
    $order = $this->findOrderByPaymentId($paymentId);
    $payment = $order->getPayment();

    // Idempotency check
    $processedEventId = $payment->getAdditionalInformation('reservepay_processed_event_id');
    if ($processedEventId === $eventId) {
      $this->logger->info('Webhook event already processed', [
        'event_id' => $eventId,
        'order_id' => $order->getId(),
      ]);
      return ['status' => 'already_processed'];
    }

    $normalizedStatus = strtolower($status ?? '');

    if ($normalizedStatus === 'successful') {
      $this->invoiceOrder($order);
      $payment->setAdditionalInformation('reservepay_processed_event_id', $eventId);
      $this->orderRepository->save($order);
      $this->logger->info('Webhook: Invoice created', ['order_id' => $order->getId()]);
      return ['status' => 'invoiced'];
    }

    // Payment failed
    $this->cancelOrder($order);
    $payment->setAdditionalInformation('reservepay_processed_event_id', $eventId);
    $this->orderRepository->save($order);
    $this->logger->info('Webhook: Order cancelled (payment failed)', ['order_id' => $order->getId()]);
    return ['status' => 'cancelled'];
  }

  /**
   * Handle payment.voided event: cancel the order.
   */
  public function handlePaymentVoided($paymentId, $eventId)
  {
    $order = $this->findOrderByPaymentId($paymentId);
    $payment = $order->getPayment();

    $processedEventId = $payment->getAdditionalInformation('reservepay_processed_event_id');
    if ($processedEventId === $eventId) {
      $this->logger->info('Webhook event already processed', [
        'event_id' => $eventId,
        'order_id' => $order->getId(),
      ]);
      return ['status' => 'already_processed'];
    }

    $this->cancelOrder($order);
    $payment->setAdditionalInformation('reservepay_processed_event_id', $eventId);
    $this->orderRepository->save($order);
    $this->logger->info('Webhook: Order cancelled (voided)', ['order_id' => $order->getId()]);
    return ['status' => 'cancelled'];
  }

  /**
   * Handle payment.reversed event: create credit memo.
   */
  public function handlePaymentReversed($paymentId, $eventId)
  {
    $order = $this->findOrderByPaymentId($paymentId);
    $payment = $order->getPayment();

    $processedEventId = $payment->getAdditionalInformation('reservepay_processed_event_id');
    if ($processedEventId === $eventId) {
      $this->logger->info('Webhook event already processed', [
        'event_id' => $eventId,
        'order_id' => $order->getId(),
      ]);
      return ['status' => 'already_processed'];
    }

    if (!$order->hasInvoices()) {
      $this->logger->warning('Webhook: Cannot reverse order without invoices', [
        'order_id' => $order->getId(),
      ]);
      throw new \Exception('Order has no invoices to reverse');
    }

    $invoices = $order->getInvoiceCollection();
    $invoice = $invoices->getFirstItem();

    $creditmemo = $this->creditmemoFactory->createByOrder($order);
    $creditmemo->setInvoice($invoice);
    $this->creditmemoService->refund($creditmemo);

    $order->addCommentToStatusHistory(
      __('Payment reversed via webhook. Credit memo created. Event: %1', $eventId)
    )->save();

    $payment->setAdditionalInformation('reservepay_processed_event_id', $eventId);
    $this->orderRepository->save($order);
    $this->logger->info('Webhook: Credit memo created (reversed)', ['order_id' => $order->getId()]);
    return ['status' => 'refunded'];
  }

  /**
   * Find order by reservepay_payment_id stored in payment additional_info.
   *
   * @param string $paymentId
   * @return \Magento\Sales\Api\Data\OrderInterface
   * @throws \Exception
   */
  public function findOrderByPaymentId($paymentId)
  {
    $searchCriteria = $this->searchCriteriaBuilder
      ->addFilter('method', 'reservepay_payment')
      ->addFilter('additional_information', '%' . $paymentId . '%', 'like')
      ->create();

    $payments = $this->paymentRepository->getList($searchCriteria);

    foreach ($payments->getItems() as $paymentRecord) {
      $additionalInfo = $paymentRecord->getAdditionalInformation();
      if (isset($additionalInfo['reservepay_payment_id']) && $additionalInfo['reservepay_payment_id'] === $paymentId) {
        $orderId = $paymentRecord->getParentId();
        return $this->orderRepository->get($orderId);
      }
    }

    throw new \Magento\Framework\Exception\NoSuchEntityException(
      __('No order found for payment_id: %1', $paymentId)
    );
  }

  /**
   * Create invoice for order (offline capture).
   */
  protected function invoiceOrder($order)
  {
    $allowedStates = [
      \Magento\Sales\Model\Order::STATE_NEW,
      \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT
    ];

    if (!in_array($order->getState(), $allowedStates, true)) {
      $this->logger->warning('Webhook: Order not in invoiceable state', [
        'order_id' => $order->getId(),
        'state' => $order->getState(),
      ]);
      return;
    }

    if (!$order->canInvoice()) {
      $this->logger->info('Webhook: Order cannot be invoiced', ['order_id' => $order->getId()]);
      return;
    }

    $invoice = $this->invoiceService->prepareInvoice($order);
    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
    $invoice->register();
    $invoice->getOrder()->setIsInProcess(true);

    $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
    $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);

    $transactionSave = $this->transaction
      ->addObject($invoice)
      ->addObject($order);
    $transactionSave->save();

    try {
      $this->invoiceSender->send($invoice);
    } catch (\Exception $e) {
      $this->logger->warning('Webhook: Failed to send invoice email', [
        'order_id' => $order->getId(),
        'error' => $e->getMessage(),
      ]);
    }

    $order->addCommentToStatusHistory(
      __('Payment completed via webhook. Invoice #%1 created.', $invoice->getIncrementId())
    )->setIsCustomerNotified(true)->save();
  }

  /**
   * Cancel order if it can be cancelled.
   */
  protected function cancelOrder($order)
  {
    $allowedStates = [
      \Magento\Sales\Model\Order::STATE_NEW,
      \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT
    ];

    if (!in_array($order->getState(), $allowedStates, true)) {
      $this->logger->warning('Webhook: Order not in cancellable state', [
        'order_id' => $order->getId(),
        'state' => $order->getState(),
      ]);
      return;
    }

    if ($order->canCancel()) {
      $this->orderManagement->cancel($order->getId());
    }
  }
}
