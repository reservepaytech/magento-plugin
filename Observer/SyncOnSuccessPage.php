<?php

namespace Reservepay\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Reservepay\Payment\Model\OrderSync;
use Reservepay\Payment\Model\PaymentMethod;

class SyncOnSuccessPage implements ObserverInterface
{
    public function __construct(private readonly OrderSync $orderSync)
    {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if ($order && $order->getEntityId() && $order->getPayment()?->getMethod() === PaymentMethod::CODE) {
            $this->orderSync->sync((int) $order->getEntityId(), 'success page');
        }
    }
}
