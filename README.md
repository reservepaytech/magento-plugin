# Reservepay Payment Module for Magento 2

This module adds **Reservepay** payments to Magento 2. After placing the order, the customer pays on a page of your store that embeds the Reservepay payment form.

## Features

*   **One option per payment group**: Checkout shows one radio button for each group your Reservepay installation offers (card, installment, scan to pay, mobile banking, e-wallet), with the logos of the methods behind it. The payment form then opens on the group the customer picked.
*   **Embedded payment form**: After placing the order, the customer goes to a payment page on your store (`reservepay/payment/form`) where the Reservepay payment form runs.
*   **Server-side confirmation**: The browser never marks an order paid. The module asks Reservepay (`merchants/find-payment`) and completes the order only when the payment is successful and its amount, currency and payment id match the order.
*   **Automatic Invoicing**: A paid order gets one invoice and a capture transaction whose id is the Reservepay payment id.
*   **Reconciliation**: A cron job settles orders whose customer closed the tab after paying. It also flags money captured for an order that cannot take it, for a person to refund.
*   **Retry on the same order**: When the payment form reports a failed payment, it reloads for the same order so the customer can try again. The order is not cancelled, because a failed payment can still turn successful later.

## Requirements

*   Magento Open Source 2.4.7, 2.4.8 or 2.4.9, or Mage-OS 3.x
*   PHP 8.2 to 8.5, as far as your Magento version allows (2.4.9 and Mage-OS 3.x need PHP 8.3 or newer)
*   The Luma checkout. Hyvä Checkout is not supported yet.
*   Magento cron, for reconciliation (see [Reconciliation](#reconciliation))
*   Valid Reservepay Merchant Credentials (Merchant ID, Installation ID, API Key)

Tested with 2.4.7-p10 on PHP 8.2, 2.4.8-p5 on PHP 8.3, 2.4.9 on PHP 8.5 and Mage-OS 3.5.0 on PHP 8.4.

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
3.  Expand the **Reservepay Payment** section.
4.  Configure the following settings:
    *   **Enable**: Set to `Yes`.
    *   **Merchant ID**: Enter your Reservepay Merchant ID.
    *   **Installation ID**: Enter your Reservepay Installation ID.
    *   **API Key**: Enter your secret API Key.
5.  Click **Save Config**.

Until Reservepay is enabled and all three credentials are set, checkout shows no Reservepay option and does not fetch the installation settings or the logo manifest. Orders already placed with Reservepay keep being reconciled after Reservepay is turned off, so their payments still settle. Which payment groups checkout shows is set in your Reservepay installation, not in Magento. See [Payment groups at checkout](#payment-groups-at-checkout).

## Payment groups at checkout

Each Reservepay payment group is its own Magento payment method. They all use the **Reservepay Payment** settings above.

| Group | Payment method code | Title | Logos |
|---|---|---|---|
| `CARD` | `reservepay_card` | Credit / debit card | The card brands in the payment assets manifest |
| `INSTALLMENT` | `reservepay_installment` | Installment | The banks of the installation's installment method |
| `SCAN_TO_PAY` | `reservepay_scan_to_pay` | Scan to pay (PromptPay) | The installation's scan to pay methods |
| `MOBILE_BANKING` | `reservepay_mobile_banking` | Mobile banking | The installation's mobile banking methods |
| `EWALLET_APP` | `reservepay_ewallet_app` | E-wallet | The installation's e-wallet methods |

*   **Which groups show.** The module asks Reservepay for the installation's settings (`sdk/retrieve-installation-settings`, the endpoint the payment form itself uses, with no API key) and applies the payment form's own rules. INSTALLMENT needs an installment method and a default interest bearer. CARD does not count the installment method. The answer is cached for 10 minutes. If Reservepay cannot be reached, the last answer is used. If there has never been an answer, checkout shows the card option alone, so a configured Reservepay is never missing from checkout. A group the installation does not offer is also refused if a customer tries to select it through the API.
*   **Logos.** Each option shows up to three logos, then `+N` for the rest. They come from the payment assets manifest, by default `<SDK origin>/static/payment-assets/manifest.json`, fetched by the server, cached for 24 hours and revalidated with its ETag. The browser only loads the images. If the manifest or a logo is missing, the option shows its title alone. Only logos on the manifest's own origin are used, because that origin is the one the module adds to the page's `img-src` policy. No bank, wallet or card images ship with the module.
*   **Titles.** Checkout shows the group title alone. The order, invoice, credit memo, their emails and PDFs, and the customer account show it as "Reservepay - <title>", for example "Reservepay - Credit / debit card". The admin order grid's Payment Method column shows the group title alone, because Magento builds it from the configured titles. The defaults are in `etc/config.xml` as `payment/reservepay_<group>/title`. Set a store view value to change one. The module ships Thai translations, see [Translations](#translations).
*   **Order.** The radios follow the payment form's order: card, installment, scan to pay, mobile banking, e-wallet (`sort_order` 200 to 204).
*   **Payment form.** The form passes the order's group to the payment form as `initialPaymentGroup` and its currency as `currency`, so the form opens on that group. A payment form version without this option ignores it and shows its own chooser.
*   **What was actually paid with.** The customer can switch methods inside the payment form. When the payment succeeds, the method Reservepay reports (`payment_method_display_name` and `payment_method` from `find-payment`, for example "Card (CARD)") is kept in `reservepay_paid_with`, shown as **Paid with** in the order's payment information, and added as an order note.
*   **Caches.** The installation settings and the manifest stay in Magento's cache without a tag, so `bin/magento cache:flush` drops them and `cache:clean` does not. A failed fetch is retried after a minute.

`reservepay_payment` only holds the settings. It is never offered at checkout, so no order carries it.

## How orders move

1.  Placing the order with any Reservepay group puts it in **Pending Payment** with no email sent, and the browser goes to the payment form at `reservepay/payment/form?token=...`.
2.  Each payment session on the form is one attempt with its own `external_id`, `<prefix>_order_<increment id>_<n>`, for example `m2-demo-local-3f9a_order_000000047_1`. The prefix is `m2-`, up to 10 characters of the store's host, and 4 random hex characters, the same shape as the WooCommerce plugin's `wc-` prefix. It is made once, on the first payment, and kept in Magento's `flag` table (`reservepay_external_id_prefix`), so a reset store or a second store on the same Reservepay installation never reuses an id. It starts with `m2-` because Reservepay treats any id starting with `pay` as its own payment id. If a long custom increment id would make the id longer than 40 characters, the order's entity id replaces it. Attempts are stored in the order payment's `additional_information` (`reservepay_attempts`), and the one that paid in `reservepay_paid_attempt`. Two tabs or a reload add attempts instead of overwriting one.
3.  The attempt is saved before the module asks Reservepay to start the payment, so every payment Reservepay creates is on record for the reconciler. If Reservepay answers that the payment session already has a payment, for example after an earlier request timed out, the module looks it up by `external_id` and keeps it when its payment session matches.
4.  The form callbacks, the payment page's own check, the order success page and the cron job all run the same check. It runs under a per-order lock, so repeated or simultaneous calls complete the order once. The payment form does not always report a captured payment, so once the payment has started, the page also asks the server every 15 seconds, for up to 30 minutes, and goes to the success page as soon as the order is paid.

| Reservepay status | Result |
|---|---|
| `SUCCESSFUL`, `PARTIALLY_REFUNDED`, `REFUNDED`, `DISPUTED` | Order goes to Processing with an invoice, and the order email is sent |
| `PENDING`, `AUTHORIZED` | No change. When the payment form reported success, the customer sees "We are confirming your payment" with a **View order** link: the order in their account, or **Orders and Returns** for a guest |
| `FAILED`, `EXPIRED`, `REVERSED`, `VOIDED` | No change. When the payment form reports a failure, it reloads for the same order with "Your payment did not go through", and the next payment is a new attempt |
| Not found, or the API is unreachable | No change, logged. An attempt with no payment id that is still not found 24 hours after it started counts as failed, because it never reached Reservepay |

A successful payment whose amount or currency does not match the order puts the order **On Hold** with a note, logs an error, and is recorded in `reservepay_extra_captures`, so the reconciler does not flag it again.

A payment page whose token is bad or expired sends the customer to the cart with the message "We could not open the payment page for this order." The order is unchanged.

## Reconciliation

The cron job `reservepay_reconcile_orders` (group `default`, every 5 minutes) needs Magento cron to run. Each run checks one batch of 50 Reservepay orders, least recently checked first. An order takes part from its first attempt until it is paid by its only attempt, or until its newest attempt is 72 hours old, however old the order itself is. A new attempt brings it back. The time of each check is kept in `sales_order_payment.reservepay_last_checked`, which is empty for an order that takes no part. An order whose check fails is logged and sent to the back of the queue, and the rest of the batch still runs.

| Order | What the check does |
|---|---|
| Pending Payment | Completes it when an attempt is paid, as above |
| Processing, Complete or Closed, with more than one attempt | Looks for a second captured attempt. A paid order with a single attempt has nothing to watch and is skipped |
| On Hold | Looks for a second captured attempt on a paid order, or for any captured attempt on an unpaid one |
| Canceled | Looks for an attempt paid after the cancellation |

A captured payment that the order cannot take never changes the order. The module does not refund or void it. It logs an error and adds a private order note, once per payment:

*   Paid order: "Second Reservepay payment `pay_...` captured for this order. Refund it in the Reservepay dashboard."
*   Cancelled order: "Reservepay payment `pay_...` was paid after this order was cancelled. Refund it in the Reservepay dashboard or reinstate the order."
*   Unpaid order on hold: "Reservepay payment `pay_...` was captured while this order is on hold. Check it before releasing the hold, or refund it in the Reservepay dashboard."

The flagged payment ids are kept in the order payment's `reservepay_extra_captures`. Attempts older than 72 hours are no longer checked.

Magento's own `sales_clean_orders` cron cancels Pending Payment orders after **Stores > Configuration > Sales > Sales > Orders Cron Settings > Pending Payment Order Lifetime** (480 minutes by default). That is why cancelled orders are watched.

To run one batch by hand, optionally with another batch size:

```bash
bin/magento reservepay:reconcile
bin/magento reservepay:reconcile --batch-size=10
```

It prints the outcome per order increment id, for example `{"000000016":"paid"}`. An order that was already paid counts as `paid`, a cancelled or held one as `unknown`, and one whose check failed as `error`.

## Translations

`i18n/th_TH.csv` translates the five group titles and every message the module shows the customer: the payment page messages, the error when the payment page cannot open, and "Paid with". The other strings go through `__()` too, so a language pack can translate them.

The Thai wording has not been reviewed by a native speaker yet. Please have it reviewed before release.

## Technical Details

*   **API Endpoints**: The module calls `merchants/initiate-payment-flow` and `merchants/find-payment` on `https://api.reservepay.com/` with `Api-Version: 2025-04-01` and a 30 second timeout. It also calls `sdk/retrieve-installation-settings` without the API key and with a 5 second timeout, because checkout waits for it when the cache is cold.
*   **Logging**: `var/log/reservepay.log`. The API key and the `Authorization` header are never logged.
*   **Order token**: Right after checkout, the session that placed a Reservepay order gets a signed token for it, valid for 24 hours. The token is `<order entity id>.<expiry>.<signature>`, where the signature is an HMAC-SHA256 over the entity id, quote id and expiry, keyed with Magento's crypt key. The entity id, unlike the increment id, is unique across store views. The token in the form URL, and posted back by the form, is the only thing that decides which order a request is for. It needs no cookie, so it works on plain HTTP. A bad, expired or foreign token and an order that cannot be paid all get the same generic response.
*   **Callbacks**: The form posts the payment session to `reservepay/payment/paymentsession` and asks for a check at `reservepay/payment/sync`. A check with a bad or expired token answers `{"outcome":"refused"}`.
*   **Caching**: The payment form block is `cacheable="false"`, so full-page cache never serves one customer's form to another.
*   **Content Security Policy**: The payment form page enforces Magento's CSP even where the rest of the storefront only reports, and allows inline scripts only by nonce, the same as Magento's own checkout page. `etc/csp_whitelist.xml` allows `sdk.reservepay.com` and `api.reservepay.com`. When `payment/reservepay_payment/sdk_url` points at another host, the module adds that host to `script-src` and `frame-src` itself, and it adds the payment assets manifest's origin to `img-src` for the checkout logos.
*   **Hosts**: `payment/reservepay_payment/api_base_url`, `payment/reservepay_payment/sdk_url` and `payment/reservepay_payment/payment_assets_url` are not in the admin. Empty or unset means production (`https://api.reservepay.com/` and `https://sdk.reservepay.com/js/v1/reservepay.js`), and the manifest URL follows the SDK's origin. `bin/magento config:set` refuses paths that are not in the admin, so set them in `app/etc/env.php` or with environment config, for example `CONFIG__DEFAULT__PAYMENT__RESERVEPAY_PAYMENT__PAYMENT_ASSETS_URL`.
*   **Packaging**: `.gitattributes` marks `tests/`, `.gitignore` and `.gitattributes` `export-ignore`, so `composer archive` and GitHub release archives hold only what the store runs.

## Tests

Plain PHP scripts, no PHPUnit needed. Each prints one line per check and exits non-zero on a failure.

```bash
composer test                        # the two scripts below, from the module directory
php tests/payment-core-test.php      # StatusMap, MinorUnits, external_id prefix and format
php tests/payment-groups-test.php    # group rules against tests/fixtures/payment-groups.json, plus logos
```

`tests/order-token-test.php` signs and verifies payment page tokens with Magento's real crypt key, so it needs a Magento install with a checkout of the module in `app/code` (Composer installs leave `tests/` out). Run it from the Magento root:

```bash
php app/code/Reservepay/Payment/tests/order-token-test.php
```

`tests/fixtures/payment-groups.json` is built from the payment form's own test table (`getAvailablePaymentGroups` in browser-sdk). The WooCommerce plugin has a copy at the same path. The two copies must stay identical, so change both together.
