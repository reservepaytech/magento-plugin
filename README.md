# Reservepay Payment Module for Magento 2

This module integrates the **Reservepay** payment gateway into Magento 2. It allows customers to pay securely using Reservepay's hosted payment flow.

## Features

*   **Redirect Payment Flow**: Redirects customers to a secure payment form to complete their transaction.
*   **Automatic Invoicing**: Automatically creates an invoice in Magento upon successful payment confirmation.
*   **Cart Restoration**: Automatically restores the customer's shopping cart if the payment fails or is cancelled, allowing them to retry easily.
*   **Secure Validation**: Uses encrypted cookies and API validation to ensure order integrity during the redirect process.

## Requirements

*   Magento 2.3.x or higher
*   PHP 7.3 or higher, below PHP 8.0
*   Valid Reservepay Merchant Credentials (Merchant ID, Installation ID, API Key)

## Installation

1.  **Download the module with Composer**
    Use Composer to add the module into your store:
    `composer require reservepay/reservepay-magento`

2.  **Enable the module**
    Run the following commands in your Magento root directory:

    ```bash
    bin/magento module:enable Reservepay_Payment
    bin/magento setup:upgrade
    bin/magento setup:di:compile
    bin/magento setup:static-content:deploy
    bin/magento cache:flush
    ```

## Configuration

1.  Log in to the Magento Admin Panel.
2.  Navigate to **Stores > Configuration > Sales > Payment Methods**.
3.  Expand the **Reservepay** section.
4.  Configure the following settings:
    *   **Enabled**: Set to `Yes`.
    *   **Title**: The payment method title shown to customers (e.g., "Pay with Reservepay").
    *   **Merchant ID**: Enter your Reservepay Merchant ID.
    *   **Installation ID**: Enter your Reservepay Installation ID.
    *   **API Key**: Enter your secret API Key.
5.  Click **Save Config**.

## Technical Details

*   **API Endpoints**: The module communicates with `https://api.reservepay.com/` to initiate payments and verify transaction status.
*   **Security**: Sensitive order data is validated using a secure, HTTP-only cookie (`payment_redirect_token`) to prevent unauthorized access during the payment redirect loop.
*   **Webhooks/Callbacks**: The module handles payment success and failure via the `reservepay/payment/paymentsuccess` and `reservepay/payment/paymentfail` controllers.