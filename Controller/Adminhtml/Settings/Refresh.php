<?php

namespace Reservepay\Payment\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Reservepay\Payment\Model\Config;
use Reservepay\Payment\Model\InstallationSettings;

/**
 * The settings' "Refresh payment methods" button: fetches the installation settings for the store view the settings
 * page shows and replaces the cached copy, so checkout offers a newly enabled category at once.
 */
class Refresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Payment::payment';

    public function __construct(
        Context $context,
        private readonly InstallationSettings $installationSettings,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $storeParam = $this->getRequest()->getParam('store');
        $websiteParam = $this->getRequest()->getParam('website');
        $redirect = $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit', array_filter([
            'section' => 'payment',
            'website' => $websiteParam,
            'store' => $storeParam,
        ], fn ($value) => $value !== null && $value !== ''));
        $store = match (true) {
            $storeParam !== null => $this->storeManager->getStore($storeParam),
            $websiteParam !== null => $this->storeManager->getWebsite($websiteParam)->getDefaultStore(),
            default => $this->storeManager->getDefaultStoreView(),
        };
        $storeId = (int) $store->getId();

        if (!$this->config->isConfigured($storeId)) {
            $this->messageManager->addErrorMessage(
                __('Enter the Merchant ID, Installation ID and API Key, save, then refresh the payment methods.')
            );
            return $redirect;
        }
        try {
            $settings = $this->installationSettings->refresh($storeId);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__(
                'Reservepay could not be reached, so the payment methods were not refreshed. Checkout keeps the ones it had.'
            ));
            return $redirect;
        }

        $names = array_values(array_unique(array_filter(
            array_column($settings['payment_methods'], 'name'),
            fn ($name) => is_string($name) && $name !== ''
        )));
        if ($names) {
            $this->messageManager->addSuccessMessage(
                __('Payment methods refreshed. Reservepay offers: %1.', implode(', ', $names))
            );
        } else {
            $this->messageManager->addWarningMessage(__(
                'Payment methods refreshed, but your Reservepay installation offers no payment methods. Checkout shows Credit / debit card.'
            ));
        }
        return $redirect;
    }
}
