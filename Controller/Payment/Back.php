<?php

namespace Reservepay\Payment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Reservepay\Payment\Model\OrderSync;
use Reservepay\Payment\Model\OrderToken;
use Reservepay\Payment\Model\StatusMap;

/**
 * "Choose another payment method" on the payment page: cancels the unpaid order and sends the shopper back to the
 * checkout's payment step with the cart restored. A paid order goes to the success page instead.
 */
class Back implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly OrderToken $orderToken,
        private readonly OrderSync $orderSync,
        private readonly CheckoutSession $checkoutSession,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $token = (string) $this->request->getParam(Form::TOKEN_PARAM);
        $order = $this->orderToken->orderFor($token);
        if (!$order) {
            $this->logger->warning('Reservepay payment method change refused');
            $this->messageManager->addErrorMessage(__('We could not open the payment page for this order.'));
            return $this->redirect('checkout/cart');
        }

        $outcome = $this->orderSync->cancelUnpaid(
            (int) $order->getEntityId(),
            __('Shopper chose another payment method.')
        );
        if ($outcome === StatusMap::PAID) {
            $this->showOnSuccessPage($order);
            return $this->redirect('checkout/onepage/success');
        }
        if ($outcome !== OrderSync::CANCELLED) {
            $this->messageManager->addNoticeMessage(
                __('Your payment is still being confirmed. Please wait a moment or check your order status.')
            );
            return $this->redirect('reservepay/payment/form', ['_query' => [Form::TOKEN_PARAM => $token]]);
        }
        if (!$this->restoreCart($order)) {
            $this->messageManager->addWarningMessage(
                __('Your cart could not be restored. Please add the items again.')
            );
            return $this->redirect('checkout/cart');
        }
        return $this->redirect('checkout', ['_fragment' => 'payment']);
    }

    /**
     * Magento restores the quote of the session's last placed order only, so a session that lost the order, or placed
     * another one since, cannot get this cart back. A repeated request finds the cart already restored.
     */
    private function restoreCart(OrderInterface $order): bool
    {
        if ((int) $this->checkoutSession->getLastRealOrder()->getEntityId() === (int) $order->getEntityId()) {
            return $this->checkoutSession->restoreQuote();
        }
        return (int) $this->checkoutSession->getQuote()->getId() === (int) $order->getQuoteId();
    }

    /**
     * Same as Sync: the success page shows the session's last order, which may be another one.
     */
    private function showOnSuccessPage(OrderInterface $order): void
    {
        $this->checkoutSession->setLastQuoteId($order->getQuoteId())
            ->setLastSuccessQuoteId($order->getQuoteId())
            ->setLastOrderId($order->getEntityId())
            ->setLastRealOrderId($order->getIncrementId());
    }

    private function redirect(string $path, array $params = []): Redirect
    {
        return $this->redirectFactory->create()->setPath($path, $params);
    }
}
