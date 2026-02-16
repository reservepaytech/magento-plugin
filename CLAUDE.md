# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a Magento 2 payment module that integrates the Reservepay payment gateway. It implements a **hosted redirect payment flow** where customers are redirected to Reservepay's secure platform to complete payment.

**Module:** `Reservepay_Payment`
**Namespace:** `Reservepay\Payment`
**Requirements:** Magento 2.3.x+, PHP 7.3+

## Development Commands

### Module Installation/Updates
```bash
# Enable the module
bin/magento module:enable Reservepay_Payment

# Run setup upgrade (apply schema/data changes)
bin/magento setup:upgrade

# Compile dependency injection
bin/magento setup:di:compile

# Deploy static content (for frontend JS/CSS changes)
bin/magento setup:static-content:deploy

# Clear cache
bin/magento cache:flush
```

### Development Workflow
```bash
# After modifying PHP files (controllers, models, blocks)
bin/magento setup:di:compile
bin/magento cache:flush

# After modifying XML files (layout, config, di.xml)
bin/magento cache:flush

# After modifying frontend JS/templates
bin/magento setup:static-content:deploy
bin/magento cache:flush

# Quick cache clean (without full flush)
bin/magento cache:clean
```

## Architecture Overview

### Payment Flow

The module follows a **secure redirect pattern**:

1. **Order Creation** → Order placed in `STATE_PENDING_PAYMENT`
2. **Security Token** → Plugin encrypts order ID into HTTP-only cookie (`payment_redirect_token`)
3. **Form Redirect** → Customer redirected to `reservepay/payment/form`
4. **Session Init** → Frontend calls `paymentsession` controller → API initiates payment
5. **External Payment** → Customer completes payment on Reservepay hosted form
6. **Callback** → SDK fires either `paymentsuccess` or `paymentfail` callback
7. **Processing** → Success creates invoice and completes order; Failure restores cart

### Key Controllers

All payment controllers are in `Controller/Payment/`:

- **Form.php** - Renders payment form page (GET)
  - URL: `/reservepay/payment/form/order_id/{orderId}/`
  - Renders template with Reservepay SDK

- **OrderData.php** - Returns merchant configuration data (POST, CSRF-disabled)
  - Called by frontend JavaScript to get payment parameters
  - Returns: `{merchant_id, installation_id, amount, currency}`

- **PaymentSession.php** - Initiates payment with API (POST, CSRF-disabled)
  - Calls Reservepay API to create payment session
  - Returns: `{payment_id, session_id}`

- **PaymentSuccess.php** - Verifies payment and creates invoice (POST, CSRF-disabled)
  - Validates payment with API
  - Creates invoice on successful payment
  - Handles idempotent processing

- **PaymentFail.php** - Cancels order and restores cart (POST, CSRF-disabled)
  - Cancels order
  - Restores quote to cart
  - Clears payment cookie

Routes are prefixed with `/reservepay/payment/` (configured in `etc/frontend/routes.xml`).

**Required Controller Dependencies:**

All payment controllers (OrderData, PaymentSession, PaymentSuccess, PaymentFail) require these dependencies:

```php
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

public function __construct(
    // ... other dependencies
    OrderRepositoryInterface $orderRepository,
    CheckoutSession $checkoutSession,
    CustomerSession $customerSession,
    EncryptorInterface $encryptor,
    CookieManagerInterface $cookieManager,
    LoggerInterface $logger
)
```

**Critical:** All controllers use direct entity_id lookup (`$this->orderRepository->get($orderId)`) instead of SearchCriteria filtering.

### Security Mechanisms

**Token Cookie (`payment_redirect_token`):**
- Plugin `SetOrderTokenCookie` intercepts checkout payment save
- Encrypts order ID using `Magento\Framework\Encryption\EncryptorInterface`
- Sets HTTP-only, Secure, SameSite=Strict cookie (10-minute TTL)
- Controllers validate access via session OR encrypted cookie

**Ownership Validation (Dual-Path Pattern):**

This module implements a **session-first, cookie-fallback** validation pattern that supports both fresh checkout and resume payment scenarios:

**For Logged-in Customers:**
```php
if ($this->customerSession->isLoggedIn()) {
    $customerId = $this->customerSession->getCustomerId();
    if ($order->getCustomerId() != $customerId) {
        throw new \Exception('Access Denied: Invalid order');
    }
}
```

**For Guest Users:**
```php
// Step 1: Check if order is in current checkout session (fresh checkout)
$sessionOrderId = $this->checkoutSession->getLastOrderId();
if ($sessionOrderId && $sessionOrderId == $orderId) {
    // Valid - order from current checkout session
} else {
    // Step 2: Fallback to cookie validation (resume payment)
    $token = $this->request->getCookie('payment_redirect_token');
    if (!$token) {
        throw new \Exception('Access Denied: Missing payment token');
    }
    $decryptedId = $this->encryptor->decrypt(base64_decode($token));
    if ($decryptedId != $orderId) {
        throw new \Exception('Access Denied: Invalid payment session');
    }
}
```

**Why This Pattern?**

Guest users may have multiple scenarios:
1. **Fresh Checkout** - Order just created, session still active → `CheckoutSession->getLastOrderId()` works
2. **Resume Payment** - Guest closed browser, reopened resume link → Session expired, cookie validation needed
3. **Multiple Orders** - Guest has orders 31, 32, 33 → Cookie allows resuming any order, not just the last one
4. **Multi-Tab** - Guest opens payment in multiple tabs → Each tab maintains its own context via URL path parameter

**Order State Validation:**
- Only processes orders in `STATE_NEW` or `STATE_PENDING_PAYMENT`
- Prevents duplicate processing or modification of completed orders

**API Key Encryption:**
- Stored encrypted in `payment/reservepay_payment/apikey` config
- Uses backend model `Magento\Config\Model\Config\Backend\Encrypted`
- Decrypted at runtime in controllers

**CSRF Protection:**
- Controllers implement `CsrfAwareActionInterface` but explicitly disable CSRF
- Necessary for SDK callbacks from external domain
- Mitigated by token cookie + order state validation

**URL Routing Pattern:**
- Payment form URLs use path parameters: `/reservepay/payment/form/order_id/34/`
- Extracted via PHP: `$orderId = (int) $block->getRequest()->getParam('order_id');`
- Each tab maintains its own order context without cookie conflicts

### API Integration

**Base URL:** `https://api.reservepay.com/`
**Client:** `Magento\Framework\HTTP\Client\Curl` (10-second timeout)
**Auth:** Bearer token (API key from encrypted config)

**Endpoints:**
1. **POST `/merchants/initiate-payment-flow`** (PaymentSession)
   - Payload: `{payment_session_id, capture: true, amount, currency, return_url}`
   - Returns: `payment_id`
   - Purpose: Initialize payment session

2. **POST `/merchants/find-payment`** (PaymentSuccess)
   - Payload: `{payment_id}`
   - Returns: `{status: 'SUCCESSFUL'|...}`
   - Purpose: Verify payment completion

### Configuration Files

- **`etc/module.xml`** - Module metadata and dependencies
- **`etc/config.xml`** - Default payment method configuration
- **`etc/di.xml`** - Dependency injection (registers token cookie plugin)
- **`etc/adminhtml/system.xml`** - Admin configuration UI fields
- **`etc/frontend/routes.xml`** - Frontend URL routing
- **`etc/csp_whitelist.xml`** - Whitelists `sdk.reservepay.com` for SDK loading

### Frontend Integration

**Layout Files:**
- `view/frontend/layout/checkout_index_index.xml` - Integrates payment method into checkout
- `view/frontend/layout/reservepay_payment_form.xml` - Payment form page layout

**JavaScript Components:**
- `view/frontend/web/js/view/payment/reservepay_payment.js` - Payment method UI component
- `view/frontend/web/js/view/payment/method-renderer/reservepay.js` - Method renderer

**Template:**
- `view/frontend/templates/form.phtml` - Loads Reservepay SDK and handles payment callbacks

**Block:**
- `Block/Form.php` - Data provider for template with order validation methods

### Critical Implementation Details

**Idempotent Success Handling:**
- `PaymentSuccess` stores `reservepay_processed_payment_id` in order's additional information
- Prevents duplicate invoice creation if callback fires multiple times

**Cart Restoration:**
- `PaymentFail` restores the quote to active state
- Clears reservation and replaces checkout session
- Customer can retry checkout without re-adding items

**Offline Invoice Capture:**
- Uses `Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE`
- Payment already captured by gateway, Magento just records it

**Order State Transitions:**
- Initial: `STATE_NEW` → `STATE_PENDING_PAYMENT`
- Success: `STATE_PENDING_PAYMENT` → `STATE_PROCESSING`
- Failure: `STATE_PENDING_PAYMENT` → `STATE_CANCELED`

### File Reference Patterns

When modifying controllers, use this access validation pattern (found in all payment controllers):

```php
// Logged-in customer validation
if ($this->customerSession->isLoggedIn()) {
    $customerId = $this->customerSession->getCustomerId();
    if ($order->getCustomerId() != $customerId) {
        $this->logger->warning('Customer ID mismatch', [
            'session_customer_id' => $customerId,
            'order_customer_id' => $order->getCustomerId(),
            'order_id' => $orderId
        ]);
        throw new \Exception('Access Denied: Invalid order');
    }
} else {
    // Guest user: Try session-based validation first
    $sessionOrderId = $this->checkoutSession->getLastOrderId();
    if ($sessionOrderId && $sessionOrderId == $orderId) {
        // Valid via session
    } else {
        // Fallback to cookie token validation
        $token = $this->request->getCookie('payment_redirect_token');
        if (!$token) {
            throw new \Exception('Access Denied: Missing payment token');
        }
        try {
            $decryptedId = $this->encryptor->decrypt(base64_decode($token));
            if ($decryptedId != $orderId) {
                $this->logger->warning('Order ID validation failed', [
                    'session_order_id' => $sessionOrderId,
                    'cookie_order_id' => $decryptedId,
                    'requested_order_id' => $orderId
                ]);
                throw new \Exception('Access Denied: Invalid payment session');
            }
        } catch (\Exception $e) {
            throw new \Exception('Access Denied: Invalid payment token');
        }
    }
}
```

### Comparison with Other Payment Gateways

**Reservepay vs Omise (Guest Checkout Patterns)**

| Feature | Reservepay | Omise |
|---------|-----------|-------|
| **Token Type** | Encrypted order ID | Random 64-char hex |
| **Token Storage** | HTTP-only encrypted cookie | URL query parameter + payment additional info |
| **Order Retrieval** | Session + Cookie fallback | `CheckoutSession->getLastRealOrder()` only |
| **Resume After Session Expires** | ✅ Yes (cookie persists) | ❌ No (requires active session) |
| **Multiple Orders (Guest)** | ✅ Yes (cookie per order) | ❌ Only last order in session |
| **Multi-Tab Support** | ✅ Yes (URL path parameter) | ⚠️ Limited (session-based) |
| **URL Pattern** | `/form/order_id/34/` | `/callback/offsite?token=abc123...` |
| **Security Validation** | Customer ID / Session / Cookie | Session + Token match |

**Why Reservepay's Approach is More Robust:**

1. **Persistent Resume Payment** - Cookie allows guests to resume payment even after closing browser, while Omise requires active session
2. **Multiple Order Support** - Guests can have orders 31, 32, 33 and resume any of them, not just the most recent
3. **Multi-Tab Friendly** - Each tab maintains context via URL path parameter, no cookie conflicts
4. **Graceful Degradation** - Session first (fast), cookie fallback (reliable)

**Omise's Limitations (Reference: `omise-magento/Controller/Callback/Offsite.php`):**
- Relies entirely on `$this->session->getLastRealOrder()` which requires active CheckoutSession
- Token in URL only prevents unauthorized access, doesn't help if session expires
- Guest closing browser loses ability to complete payment (session-dependent)

### Security Considerations

Recent fixes address:
- **Internal Order ID Exposure** - Avoid exposing internal order IDs in frontend
- **Missing Order State Validation** - Always validate order state before processing
- **API Key Decryption** - Handle decryption failures gracefully with fallback

When working with payment processing:
- Always validate order state before modifications
- Use increment_id (visible ID) for frontend/API communication
- Use entity_id (internal ID) only for backend validation
- Never skip cookie deletion after payment completion
- Log warnings for invalid states instead of silently failing
