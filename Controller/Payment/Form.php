<?php

namespace Reservepay\Payment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Reservepay\Payment\Model\Config;
use Reservepay\Payment\Model\OrderToken;
use Reservepay\Payment\Model\PaymentGroups;

/**
 * The payment page for one order, named by its token. Right after checkout there is no token yet: the order this
 * session just placed gets one and the shopper is redirected to the tokenized URL.
 */
class Form implements HttpGetActionInterface
{
    public const TOKEN_PARAM = 'token';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly OrderToken $orderToken,
        private readonly CheckoutSession $checkoutSession,
        private readonly Config $config,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $token = (string) $this->request->getParam(self::TOKEN_PARAM);
        if ($token === '') {
            $order = $this->checkoutSession->getLastRealOrder();
            return $order->getEntityId() && $this->isPayable($order)
                ? $this->redirectFactory->create()->setPath('reservepay/payment/form', [
                    '_query' => [self::TOKEN_PARAM => $this->orderToken->issue($order)],
                ])
                : $this->refuse();
        }

        $order = $this->orderToken->orderFor($token);
        if (!$order || !$this->isPayable($order)) {
            return $this->refuse();
        }
        $page = $this->pageFactory->create();
        $page->getLayout()->getBlock('reservepay.payment.form')->setOrder($order)->setToken($token);
        return $page;
    }

    private function isPayable(OrderInterface $order): bool
    {
        return PaymentGroups::isReservepay($order->getPayment()?->getMethod())
            && $order->getState() === Order::STATE_PENDING_PAYMENT
            && (float) $order->getGrandTotal() > 0
            && $this->isConfigured((int) $order->getStoreId());
    }

    private function isConfigured(int $storeId): bool
    {
        if ($this->config->isConfigured($storeId)) {
            return true;
        }
        $this->logger->critical('Reservepay is missing its merchant id, installation id or API key', ['store_id' => $storeId]);
        return false;
    }

    /**
     * One response for every reason, so a bad token, a missing order and an unpayable one look the same.
     */
    private function refuse(): Redirect
    {
        $this->logger->warning('Reservepay payment page refused');
        $this->messageManager->addErrorMessage(__('We could not open the payment page for this order.'));
        return $this->redirectFactory->create()->setPath('checkout/cart');
    }
}
