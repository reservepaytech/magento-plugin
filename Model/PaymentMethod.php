<?php

namespace Reservepay\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Reservepay\Payment\Block\Info;

/**
 * Without a group this is reservepay_payment, which holds the settings and is never offered at checkout. etc/di.xml
 * makes one virtual type per payment group (data "group"), and each reads every setting except its own title and sort
 * order from reservepay_payment. Observer\OfferedGroupsOnly hides the groups the installation does not offer.
 */
class PaymentMethod extends AbstractMethod
{
    public const CODE = PaymentGroups::SETTINGS_CODE;

    private const OWN_FIELDS = ['title', 'sort_order'];

    protected $_code = self::CODE;
    protected $_infoBlockType = Info::class;

    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = false;

    public function getGroup(): ?string
    {
        $group = $this->getData('group');
        return is_string($group) && isset(PaymentGroups::CODES[$group]) ? $group : null;
    }

    public function getCode()
    {
        $group = $this->getGroup();
        return $group ? PaymentGroups::CODES[$group] : self::CODE;
    }

    public function canUseCheckout()
    {
        return $this->getGroup() !== null;
    }

    public function getConfigData($field, $storeId = null)
    {
        $code = in_array($field, self::OWN_FIELDS, true) ? $this->getCode() : self::CODE;
        return $this->_scopeConfig->getValue(
            'payment/' . $code . '/' . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId ?? $this->getStore()
        );
    }

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
