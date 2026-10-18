<?php

namespace Reservepay\Payment\Block\Adminhtml;

use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * The settings' "Refresh payment methods" button. It posts with the form key to a controller outside the settings
 * form, because a nested form cannot be submitted.
 */
class RefreshPaymentMethods extends Field
{
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $scope = array_filter([
            'website' => $this->getRequest()->getParam('website'),
            'store' => $this->getRequest()->getParam('store'),
        ], fn ($value) => $value !== null && $value !== '');
        $url = json_encode($this->getUrl('reservepay/settings/refresh', $scope), JSON_UNESCAPED_SLASHES);
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
