# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a Magento 2 payment module that integrates the Reservepay payment gateway. It implements a **hosted redirect payment flow** where customers are redirected to Reservepay's secure platform to complete payment. **Payment processing is handled server-side via webhooks** - the frontend SDK callbacks only trigger immediate redirects.

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

### Running Tests
```bash
# Run unit tests for this module
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Reservepay/Payment/Test/Unit/
```

## Architecture Overview

### Payment Flow

The module follows a **webhook-driven payment pattern**:

1. **Order Creation** → Order placed in `STATE_PENDING_PAYMENT`
2. **Security Token** → Plugin encrypts order ID into HTTP-only cookie (`payment_redirect_token`)
3. **Form Redirect** → Customer redirected to `reservepay/payment/form`
4. **Session Init** → Frontend calls `paymentsession` controller → API initiates payment, stores `payment_id` in order
5. **External Payment** → Customer completes payment on Reservepay hosted form
6. **Frontend Redirect** → SDK fires `onPaymentSuccess` or `onPaymentFailed` → immediate redirect (no server calls)
7. **Webhook Processing** → Reservepay sends webhook to `/reservepay/webhook/callback` → invoice/cancel/refund

### Key Controllers

**Payment Controllers** (`Controller/Payment/`):

- **Form.php** - Renders payment form page (GET)
  - URL: `/reservepay/payment/form/order_id/{orderId}/`
  - Renders template with Reservepay SDK

- **OrderData.php** - Returns merchant configuration data (POST, CSRF-disabled)
  - Called by frontend JavaScript to get payment parameters
  - Returns: `{merchant_id, installation_id, amount, currency}`

- **PaymentSession.php** - Initiates payment with API (POST, CSRF-disabled)
  - Calls Reservepay API to create payment session
  - Stores `reservepay_payment_id` in order payment additional_info
  - Returns: `payment_id`

**Webhook Controller** (`Controller/Webhook/`):

- **Callback.php** - Receives webhooks from Reservepay (POST, CSRF-disabled)
  - URL: `/reservepay/webhook/callback`
  - Signature verification (currently commented out, ready to enable)
  - Delegates to `Service/WebhookHandler` for business logic
  - Returns: 200/400/500

Routes are prefixed with `/reservepay/` (configured in `etc/frontend/routes.xml`).

### Webhook Handler

`Service/WebhookHandler.php` contains all server-side payment processing logic:

- **`handleEvent(array $data)`** - Routes by `event_type`
- **`handlePaymentCompleted(paymentId, status, eventId)`** - SUCCESSFUL → invoice, FAILED → cancel
- **`handlePaymentVoided(paymentId, eventId)`** - Cancel order
- **`handlePaymentReversed(paymentId, eventId)`** - Create credit memo
- **`findOrderByPaymentId(paymentId)`** - Lookup via `reservepay_payment_id` in payment additional_info

**Idempotency:** Each event is tracked via `reservepay_processed_event_id` in order additional_info. Duplicate webhook deliveries are safely ignored.

**Webhook Payload Format:**
```json
{
  "event_type": "payment.completed",
  "payment_id": "pay_abc123",
  "status": "SUCCESSFUL",
  "event_id": "evt_def456"
}
```

**Supported Event Types:** `payment.completed`, `payment.voided`, `payment.reversed`

**Required Controller Dependencies:**

Frontend payment controllers (Form, OrderData, PaymentSession) require these dependencies:

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

**Critical:** Frontend controllers use direct entity_id lookup (`$this->orderRepository->get($orderId)`). The webhook handler uses `findOrderByPaymentId()` to look up orders by `reservepay_payment_id` in payment additional_info.

### Security Mechanisms

**Token Cookie (`payment_redirect_token`):**
- Plugin `SetOrderTokenCookie` intercepts checkout payment save
- Encrypts order ID using `Magento\Framework\Encryption\EncryptorInterface`
- Sets HTTP-only, Secure, SameSite=Strict cookie (10-minute TTL)
- Used by Form, OrderData, and PaymentSession controllers for guest checkout validation

**Ownership Validation (Dual-Path Pattern):**

Frontend controllers implement a **session-first, cookie-fallback** validation pattern:

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
    // Step 2: Fallback to cookie validation
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

**Webhook Signature Verification:**
- Format: `sha256=` + `hash('sha256', $rawBody . $signingKey)`
- Signing key stored encrypted in `payment/reservepay_payment/webhook_signing_key`
- Currently commented out in `Controller/Webhook/Callback.php` - enable once signing key is configured in Reservepay dashboard

**Order State Validation:**
- Only processes orders in `STATE_NEW` or `STATE_PENDING_PAYMENT`
- Prevents duplicate processing or modification of completed orders

**API Key Encryption:**
- Stored encrypted in `payment/reservepay_payment/apikey` config
- Uses backend model `Magento\Config\Model\Config\Backend\Encrypted`
- Decrypted at runtime in controllers

**CSRF Protection:**
- Controllers implement `CsrfAwareActionInterface` but explicitly disable CSRF
- Necessary for SDK callbacks from external domain and webhook delivery
- Mitigated by token cookie + order state validation (frontend) and signature verification (webhook)

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

### Configuration Files

- **`etc/module.xml`** - Module metadata and dependencies
- **`etc/config.xml`** - Default payment method configuration
- **`etc/di.xml`** - Dependency injection (registers token cookie plugin)
- **`etc/adminhtml/system.xml`** - Admin configuration UI fields (includes `webhook_signing_key`)
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
- `view/frontend/templates/form.phtml` - Loads Reservepay SDK; callbacks are immediate redirects (no server calls)

**Block:**
- `Block/Form.php` - Data provider for template with order validation methods

### Critical Implementation Details

**Webhook Idempotency:**
- `WebhookHandler` stores `reservepay_processed_event_id` in order's additional information
- Prevents duplicate invoice creation or order cancellation on webhook redelivery

**Offline Invoice Capture:**
- Uses `Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE`
- Payment already captured by gateway, Magento just records it

**Credit Memo on Reversal:**
- `payment.reversed` webhook triggers credit memo creation
- Requires order to have at least one invoice

**Order State Transitions:**
- Initial: `STATE_NEW` → `STATE_PENDING_PAYMENT`
- Success (webhook): `STATE_PENDING_PAYMENT` → `STATE_PROCESSING`
- Failure (webhook): `STATE_PENDING_PAYMENT` → `STATE_CANCELED`
- Reversal (webhook): `STATE_PROCESSING` → refunded via credit memo

**No Cart Restoration on Webhook Failure:**
- Webhooks run without customer session context
- Cart restoration is not possible server-side
- Customer sees cart page on redirect and can retry checkout

### File Reference Patterns

When modifying frontend controllers, use this access validation pattern:

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

### Security Considerations

When working with payment processing:
- Always validate order state before modifications
- Use entity_id (internal ID) for all backend operations - it's passed via URL path parameter
- Use increment_id (visible ID) only for display purposes (order confirmation emails, admin grid)
- Log warnings for invalid states instead of silently failing
- Frontend controllers: validate customer ownership (customer ID for logged-in, session/cookie for guests)
- Webhook controller: validate via signature verification (no session/cookie available)
- Webhook handler: always check idempotency via `reservepay_processed_event_id`

## Troubleshooting

### DI Compilation Issues

If you encounter DI compilation errors after adding dependencies to controllers:

**Problem:** "Directory /app/generated/code/Magento cannot be deleted" warnings
**Solution:**
```bash
# Try setup:upgrade first (regenerates DI and applies schema changes)
bin/magento setup:upgrade

# If that fails, clear generated code and try again
rm -rf generated/code/*
bin/magento setup:di:compile

# Last resort: Clear everything
rm -rf var/cache/* var/page_cache/* generated/*
bin/magento setup:upgrade
```

**Common Causes:**
- Adding new constructor parameters without proper type hints
- Changing constructor parameter order
- Missing use statements for dependency classes
- Circular dependencies in DI

### Payment Form Not Loading (Missing CSS)

**Problem:** Page renders without styling after `setup:upgrade`
**Solution:**
```bash
# Deploy static content (required after setup:upgrade)
bin/magento setup:static-content:deploy -f

# Flush cache
bin/magento cache:flush
```

### Order ID Mismatch Errors

**Problem:** "Order not found" or "Invalid order ID"
**Cause:** Confusing entity_id (34) with increment_id (000000034)

**Solution:**
- Frontend always passes entity_id via URL: `/form/order_id/34/`
- Backend uses direct lookup: `$this->orderRepository->get($orderId)`
- Never use SearchCriteria to filter by increment_id
- increment_id is for display only (emails, customer account pages)

### CSRF Validation Errors

**Problem:** 302 redirects or CSRF validation failures on POST endpoints

**Current Implementation:**
- All payment controllers implement `CsrfAwareActionInterface`
- CSRF is explicitly disabled (returns `null` for token validation)
- Security provided by: order state validation + customer/session/cookie validation (frontend) or signature verification (webhook)
- X-Requested-With header sent by frontend for additional protection

**Do NOT:**
- Send form_key in POST body (handled via cookies automatically)
- Enable CSRF validation (breaks SDK callbacks and webhook delivery)

### Webhook Debugging

**Problem:** Webhooks not processing orders

**Check:**
1. Verify webhook URL is configured in Reservepay dashboard: `https://yourdomain.com/reservepay/webhook/callback`
2. Check `var/log/system.log` for webhook-related log entries
3. Verify `reservepay_payment_id` is stored in order payment additional_info
4. Check order state (must be `STATE_NEW` or `STATE_PENDING_PAYMENT` for invoice/cancel)

**Debug Pattern:**
```php
$this->logger->info('Webhook debug', [
    'event_type' => $data['event_type'],
    'payment_id' => $data['payment_id'],
    'event_id' => $data['event_id'],
]);
```
