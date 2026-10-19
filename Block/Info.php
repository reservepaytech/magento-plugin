<?php

namespace Reservepay\Payment\Block;

use Reservepay\Payment\Model\OrderSync;

/**
 * The order's payment information in admin, the customer account, emails and PDFs: the checkout title and what the
 * shopper actually paid with, picked on the payment form.
 */
class Info extends \Magento\Payment\Block\Info
{
    protected $_template = 'Reservepay_Payment::info/default.phtml';

    public function getTitle(): string
    {
        return (string) $this->getMethod()->getTitle();
    }

    public function toPdf()
    {
        $this->setTemplate('Reservepay_Payment::info/pdf/default.phtml');
        return $this->toHtml();
    }

    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $paidWith = $this->getInfo()->getAdditionalInformation(OrderSync::PAID_WITH);
        if (is_string($paidWith) && $paidWith !== '') {
            $transport->setData((string) __('Paid with'), $paidWith);
        }
        return $transport;
    }
}
