<?php

namespace Reservepay\Payment\Block;

use Magento\Framework\View\Element\Template\Context;
use Reservepay\Payment\Model\Config;
use Reservepay\Payment\Model\OrderSync;

/**
 * The order's payment information in admin, the customer account, emails and PDFs: the checkout title and what the
 * shopper actually paid with, picked on the payment form. The admin template adds the paying payment id.
 */
class Info extends \Magento\Payment\Block\Info
{
    protected $_template = 'Reservepay_Payment::info/default.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getTitle(): string
    {
        return (string) $this->getMethod()->getTitle();
    }

    /**
     * The Reservepay payment that paid the order and its page in the dashboard, or null before it is paid. The url is
     * null when the order's store has no merchant id.
     *
     * @return array{id: string, url: ?string}|null
     */
    public function getPaidPayment(): ?array
    {
        $info = $this->getInfo();
        $paymentId = OrderSync::paidPaymentId((array) $info->getAdditionalInformation());
        if ($paymentId === null) {
            return null;
        }
        $storeId = (int) $info->getOrder()->getStoreId();
        $merchantId = $this->config->merchantId($storeId);
        return [
            'id' => $paymentId,
            'url' => $merchantId === ''
                ? null
                : Config::dashboardPaymentUrl($this->config->dashboardUrl($storeId), $merchantId, $paymentId),
        ];
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
