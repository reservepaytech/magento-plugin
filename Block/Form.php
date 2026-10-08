<?php

namespace Reservepay\Payment\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\Order;
use Reservepay\Payment\Model\Config;
use Reservepay\Payment\Model\PaymentGroups;
use Reservepay\Payment\Model\Thb;

/**
 * Renders the SDK form for the order and token that the Form controller validated and set.
 */
class Form extends Template
{
    public function __construct(Context $context, private readonly Config $config, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function getSdkUrl(): string
    {
        return $this->config->sdkUrl();
    }

    public function getInitPayload(): string
    {
        /** @var Order $order */
        $order = $this->getData('order');
        $storeId = (int) $order->getStoreId();
        return (string) json_encode([
            'merchantId' => $this->config->merchantId($storeId),
            'installationId' => $this->config->installationId($storeId),
            'containerSelector' => '#reservepay-payment-form',
            'amount' => Thb::satang($order->getGrandTotal()),
            'currency' => Thb::CODE,
            'initialPaymentGroup' => PaymentGroups::groupOf($order->getPayment()?->getMethod()),
            'paymentsessionUrl' => $this->getUrl('reservepay/payment/paymentsession'),
            'syncUrl' => $this->getUrl('reservepay/payment/sync'),
            'successUrl' => $this->getUrl('checkout/onepage/success'),
            'cartUrl' => $this->getUrl('checkout/cart'),
            // Guests have no account, so they get Magento's own order lookup.
            'orderUrl' => $order->getCustomerIsGuest()
                ? $this->getUrl('sales/guest/form')
                : $this->getUrl('sales/order/view', ['order_id' => $order->getEntityId()]),
            'token' => (string) $this->getData('token'),
            'messages' => [
                'startFailed' => (string) __('We could not start the payment. Please try again.'),
                'tryAgain' => (string) __('Try again'),
                'processing' => (string) __('We are confirming your payment. You will get an email as soon as your order is confirmed.'),
                'viewOrder' => (string) __('View order'),
            ],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
