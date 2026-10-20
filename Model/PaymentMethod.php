<?php

namespace Reservepay\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Sales\Model\Order;
use Reservepay\Payment\Block\Info;

class PaymentMethod extends AbstractMethod
{
    public const CODE = 'reservepay_payment';

    protected $_code = self::CODE;
    protected $_infoBlockType = Info::class;

    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = false;

    /**
     * Translated, so the default title follows the store's locale. A title the merchant saved passes through as is.
     */
    public function getTitle()
    {
        return (string) __((string) $this->getConfigData('title'));
    }

    /**
     * The order waits in pending_payment, with no customer email, until OrderSync sees the payment succeed.
     */
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);
        // Payment::place() falls back to customer_note_notify, which would mark the first history row as notified.
        $this->getInfoInstance()->getOrder()->setCustomerNoteNotify(false)->setCanSendNewEmailFlag(false);
        return $this;
    }
}
