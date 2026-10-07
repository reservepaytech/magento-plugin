<?php

namespace Reservepay\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Reservepay\Payment\Model\OrderSync;
use Reservepay\Payment\Model\PaymentGroups;

class SyncOnSuccessPage implements ObserverInterface
{
    public function __construct(private readonly OrderSync $orderSync)
    {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if ($order && $order->getEntityId() && PaymentGroups::isReservepay($order->getPayment()?->getMethod())) {
            $this->orderSync->sync((int) $order->getEntityId(), 'success page');
        }
    }
}
