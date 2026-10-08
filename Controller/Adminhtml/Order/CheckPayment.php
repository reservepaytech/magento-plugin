<?php

namespace Reservepay\Payment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Reservepay\Payment\Model\OrderSync;
use Reservepay\Payment\Model\PaymentGroups;

/**
 * The order view's "Check Reservepay payment" button.
 */
class CheckPayment extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Reservepay_Payment::check_payment';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSync $orderSync
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $redirect = $this->resultRedirectFactory->create();
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('This order no longer exists.'));
            return $redirect->setPath('sales/order/');
        }
        $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
        if (!PaymentGroups::isReservepay($order->getPayment()?->getMethod())) {
            $this->messageManager->addErrorMessage(__('This order does not use Reservepay.'));
            return $redirect;
        }

        $note = $this->orderSync->checkNow($orderId);
        if ($note === null) {
            $this->messageManager->addErrorMessage(__('The order is being updated right now. Please try again.'));
        } else {
            $this->messageManager->addSuccessMessage($note);
        }
        return $redirect;
    }
}
