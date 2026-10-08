<?php

namespace Reservepay\Payment\Block\Adminhtml;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\UrlInterface;

/**
 * Shows the storefront's webhook URL in the settings, read-only, for pasting into the Reservepay dashboard.
 */
class WebhookUrl extends Field
{
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return '<code>' . $this->escapeHtml($this->webhookUrl()) . '</code>';
    }

    private function webhookUrl(): string
    {
        $storeId = $this->getRequest()->getParam('store');
        $websiteId = $this->getRequest()->getParam('website');
        $store = match (true) {
            $storeId !== null => $this->_storeManager->getStore($storeId),
            $websiteId !== null => $this->_storeManager->getWebsite($websiteId)->getDefaultStore(),
            default => $this->_storeManager->getDefaultStoreView(),
        };
        return $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true) . 'reservepay/webhook';
    }
}
