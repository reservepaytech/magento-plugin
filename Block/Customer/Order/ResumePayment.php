<?php

namespace Reservepay\Payment\Block\Customer\Order;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Registry;

class ResumePayment extends Template
{
    protected $coreRegistry;

    public function __construct(
        Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->coreRegistry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Get current order
     *
     * @return \Magento\Sales\Model\Order|null
     */
    public function getOrder()
    {
        return $this->coreRegistry->registry('current_order');
    }

    /**
     * Check if resume payment button should be displayed
     *
     * @return bool
     */
    public function canResumePayment()
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return false;
        }

        // Check payment method
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== 'reservepay_payment') {
            return false;
        }

        // Check order state
        $allowedStates = [
            \Magento\Sales\Model\Order::STATE_NEW,
            \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT
        ];

        return in_array($order->getState(), $allowedStates, true);
    }

    /**
     * Get resume payment URL
     *
     * @return string
     */
    public function getResumePaymentUrl()
    {
        $order = $this->getOrder();
        if (!$order) {
            return '';
        }

        return $this->getUrl('reservepay/payment/form', [
            'order_id' => $order->getId(),
            '_use_rewrite' => true
        ]);
    }
}
