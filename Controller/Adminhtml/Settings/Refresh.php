<?php

namespace Reservepay\Payment\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Reservepay\Payment\Model\AdminScope;
use Reservepay\Payment\Model\Config;
use Reservepay\Payment\Model\InstallationSettings;

/**
 * The settings' "Refresh payment methods" button: fetches the installation settings for the store view the settings
 * page shows and replaces the cached copy, so checkout shows a newly enabled payment method's logo at once.
 */
class Refresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Payment::payment';

    public function __construct(
        Context $context,
        private readonly InstallationSettings $installationSettings,
        private readonly Config $config,
        private readonly AdminScope $adminScope
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create()->setPath(
            'adminhtml/system_config/edit',
            ['section' => 'payment'] + $this->adminScope->params($this->getRequest())
        );
        $storeId = $this->adminScope->storeId($this->getRequest());

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

        $names = InstallationSettings::methodNames($settings);
        if ($names) {
            $this->messageManager->addSuccessMessage(
                __('Payment methods refreshed. Reservepay offers: %1.', implode(', ', $names))
            );
        } else {
            $this->messageManager->addWarningMessage(
                __('Payment methods refreshed, but your Reservepay installation offers no payment methods.')
            );
        }
        return $redirect;
    }
}
