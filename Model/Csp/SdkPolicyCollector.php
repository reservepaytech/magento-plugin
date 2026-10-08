<?php

namespace Reservepay\Payment\Model\Csp;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Reservepay\Payment\Model\Config;

/**
 * Allows the configured SDK and payment assets hosts, which csp_whitelist.xml cannot know when they are not
 * production. The SDK loads its payment iframe from the same origin as its script. Checkout logos come only from the
 * manifest's origin (PaymentAssets drops any other), so that origin is the only image host needed.
 */
class SdkPolicyCollector implements PolicyCollectorInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function collect(array $defaultPolicies = []): array
    {
        $sdk = Config::origin($this->config->sdkUrl());
        if ($sdk !== '') {
            $defaultPolicies[] = new FetchPolicy('script-src', false, [$sdk]);
            $defaultPolicies[] = new FetchPolicy('frame-src', false, [$sdk]);
        }
        $assets = Config::origin($this->config->paymentAssetsUrl());
        if ($assets !== '') {
            $defaultPolicies[] = new FetchPolicy('img-src', false, [$assets]);
        }
        return $defaultPolicies;
    }
}
