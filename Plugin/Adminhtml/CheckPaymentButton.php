<?php

namespace Reservepay\Payment\Plugin\Adminhtml;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use Reservepay\Payment\Controller\Adminhtml\Order\CheckPayment;
use Reservepay\Payment\Model\PaymentMethod;

class CheckPaymentButton
{
    public function __construct(private readonly AuthorizationInterface $authorization)
    {
    }

    public function beforeSetLayout(View $subject, LayoutInterface $layout): array
    {
        $order = $subject->getOrder();
        if ($order
            && $order->getPayment()?->getMethod() === PaymentMethod::CODE
            && $this->authorization->isAllowed(CheckPayment::ADMIN_RESOURCE)
        ) {
            $message = $subject->escapeJs(__('Check this order\'s payment with Reservepay now?'));
            $url = $subject->escapeJs(
                $subject->getUrl('reservepay/order/checkPayment', ['order_id' => $order->getId()])
            );
            $subject->addButton('reservepay_check_payment', [
                'label' => __('Check Reservepay payment'),
                'onclick' => "deleteConfirm('{$message}', '{$url}', {data: {}})",
            ]);
        }
        return [$layout];
    }
}
