<?php

namespace Reservepay\Payment\Model;

use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The scope an admin settings page shows, from its website and store params, and the store view whose settings
 * checkout would use there: the store, else the website's default store, else the default store view.
 */
class AdminScope
{
    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    /**
     * @return array<string, string> the non-empty website and store params
     */
    public function params(RequestInterface $request): array
    {
        return array_filter([
            'website' => $request->getParam('website'),
            'store' => $request->getParam('store'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function storeId(RequestInterface $request): int
    {
        $params = $this->params($request);
        $store = match (true) {
            isset($params['store']) => $this->storeManager->getStore($params['store']),
            isset($params['website']) => $this->storeManager->getWebsite($params['website'])->getDefaultStore(),
            default => $this->storeManager->getDefaultStoreView(),
        };
        return (int) $store->getId();
    }
}
