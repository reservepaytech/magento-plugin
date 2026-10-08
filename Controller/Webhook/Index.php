<?php

namespace Reservepay\Payment\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Reservepay\Payment\Model\Webhook;

/**
 * Reservepay posts webhooks to reservepay/webhook. The signature, not a form key, proves where a request came from.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly RawFactory $rawFactory,
        private readonly Webhook $webhook
    ) {
    }

    public function execute()
    {
        $status = $this->webhook->receive(
            (string) $this->request->getContent(),
            (string) $this->request->getHeader(Webhook::SIGNATURE_HEADER)
        );
        return $this->rawFactory->create()->setHttpResponseCode($status)->setContents('');
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
