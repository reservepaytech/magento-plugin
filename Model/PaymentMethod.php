<?php

namespace Reservepay\Payment\Model;

use Magento\Framework\DataObject;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Reservepay\Payment\Block\Info;

/**
 * Without a category this is reservepay_payment, which holds the settings and is never offered at checkout. etc/di.xml
 * makes one virtual type per payment category (data "category"), and each reads every setting except its own title and
 * sort order from reservepay_payment.
 */
class PaymentMethod extends AbstractMethod
{
    public const CODE = PaymentCategories::SETTINGS_CODE;
    /**
     * Set when checkout could not ask the SDK which categories to show and offered one "Pay with Reservepay" option in
     * the card slot. The payment page then lets the SDK show its own chooser.
     */
    public const FALLBACK = 'reservepay_fallback';

    private const OWN_FIELDS = ['title', 'sort_order'];

    protected $_code = self::CODE;
    protected $_infoBlockType = Info::class;

    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = false;

    public function getCategory(): ?string
    {
        $category = $this->getData('category');
        return is_string($category) && isset(PaymentCategories::CODES[$category]) ? $category : null;
    }

    public function getCode()
    {
        $category = $this->getCategory();
        return $category ? PaymentCategories::CODES[$category] : self::CODE;
    }

    public function canUseCheckout()
    {
        return $this->getCategory() !== null;
    }

    public function assignData(DataObject $data)
    {
        parent::assignData($data);
        $additional = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        $info = $this->getInfoInstance();
        // Cleared too, so a quote paid again after a fallback attempt does not keep the old flag.
        if (is_array($additional) && !empty($additional[self::FALLBACK])) {
            $info->setAdditionalInformation(self::FALLBACK, true);
        } else {
            $info->unsAdditionalInformation(self::FALLBACK);
        }
        return $this;
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
