<?php

namespace Reservepay\Payment\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\ScopeInterface;
use Reservepay\Payment\Model\AdminScope;
use Reservepay\Payment\Model\Config;
use Reservepay\Payment\Model\InstallationSettings;
use Reservepay\Payment\Model\PaymentCategories;

/**
 * The settings' "Payment methods" field: what checkout offers per category, worked out in the browser by the same
 * module checkout uses from the same cached settings, and the "Refresh payment methods" button. The button posts with
 * the form key to a controller outside the settings form, because a nested form cannot be submitted.
 */
class RefreshPaymentMethods extends Field
{
    protected $_template = 'Reservepay_Payment::system/config/payment-methods.phtml';

    public function __construct(
        Context $context,
        private readonly InstallationSettings $installationSettings,
        private readonly Config $config,
        private readonly AdminScope $adminScope,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $storeId = $this->adminScope->storeId($this->getRequest());
        $settings = $this->config->isConfigured($storeId) ? $this->installationSettings->forStore($storeId) : null;
        $this->addData([
            'categories' => $this->categories($storeId),
            'settings' => $settings,
            'updated' => $settings === null ? null : $this->updated($this->installationSettings->fetchedAt($storeId)),
            'init' => $settings === null ? null : [
                'settings' => $settings,
                'sdkUrl' => $this->config->sdkUrl($storeId),
                'paymentAssetsUrl' => $this->config->paymentAssetsUrl($storeId) ?: null,
                'text' => [
                    'shown' => (string) __('Shown'),
                    'notOffered' => (string) __('Not offered'),
                    'noLogos' => (string) __('Logos appear once the Reservepay SDK is updated.'),
                ],
            ],
            'button' => $this->button($element),
        ]);
        return $this->_toHtml();
    }

    /**
     * @return array<string, string> category => its checkout title, in the chooser's order
     */
    private function categories(int $storeId): array
    {
        $titles = [];
        foreach (PaymentCategories::CODES as $category => $code) {
            $title = $this->_scopeConfig->getValue("payment/{$code}/title", ScopeInterface::SCOPE_STORE, $storeId);
            $titles[$category] = (string) __((string) $title);
        }
        return $titles;
    }

    private function updated(?int $fetchedAt): ?string
    {
        if ($fetchedAt === null) {
            return null;
        }
        $minutes = intdiv(max(0, time() - $fetchedAt), 60);
        return (string) match (true) {
            $minutes === 0 => __('Updated less than a minute ago.'),
            $minutes === 1 => __('Updated 1 minute ago.'),
            $minutes < 60 => __('Updated %1 minutes ago.', $minutes),
            default => __('Updated %1.', $this->formatDate(
                new \DateTime('@' . $fetchedAt),
                \IntlDateFormatter::MEDIUM,
                true
            )),
        };
    }

    private function button(AbstractElement $element): string
    {
        $url = json_encode(
            $this->getUrl('reservepay/settings/refresh', $this->adminScope->params($this->getRequest())),
            JSON_UNESCAPED_SLASHES
        );
        $post = "var f = document.createElement('form'); f.method = 'post'; f.action = {$url};"
            . " var k = document.createElement('input'); k.type = 'hidden'; k.name = 'form_key';"
            . ' k.value = window.FORM_KEY;'
            . ' f.appendChild(k); document.body.appendChild(f); f.submit();';
        return $this->getLayout()->createBlock(Button::class)->setData([
            'id' => $element->getHtmlId(),
            'label' => __('Refresh payment methods'),
            'onclick' => $post,
        ])->toHtml();
    }
}
