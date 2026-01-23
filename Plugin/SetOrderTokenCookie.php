<?php
namespace Reservepay\Payment\Plugin;

use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class SetOrderTokenCookie
{
    const COOKIE_NAME = 'payment_redirect_token';

    protected $cookieManager;
    protected $cookieMetadataFactory;
    protected $encryptor;

    public function __construct(
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        EncryptorInterface $encryptor
    ) {
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->encryptor = $encryptor;
    }

    public function afterSavePaymentInformationAndPlaceOrder($subject, $orderId)
    {
        if ($orderId) {
            $this->setSecureCookie($orderId);
        }
        return $orderId;
    }

    private function setSecureCookie($orderId)
    {
        $encryptedId = $this->encryptor->encrypt($orderId . "");
        $token = base64_encode($encryptedId);
        $metadata = $this->cookieMetadataFactory
            ->createPublicCookieMetadata()
            ->setDuration(600) // 10 minutes
            ->setPath('/')
            ->setHttpOnly(true); // Accessible only by PHP, not JS
        $this->cookieManager->setPublicCookie(self::COOKIE_NAME, $token, $metadata);
    }
}