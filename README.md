# Zero_Mollie

Payment on Mollie's page for Zero Checkout: the customer picks iDEAL,
Bancontact, card, SEPA, Klarna or any other method switched on in the
Mollie dashboard for the order's currency. No libraries: Mollie's Payments
API v2 over Magento's HTTP client.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require fixstacks/module-zero-mollie
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `fixstacks/module-zero-checkout` with it.

Part of [`fixstacks/zero`](https://fixstacks.com/zero), which installs the Zero theme and every Zero module at once.

## How a payment goes

1. **Placing the order** creates a Mollie payment for the order's grand
   total in the order's currency (the store view's currency, e.g. EUR in
   Europe (EUR)). The order stays **pending payment**, and Zero Checkout
   sends the customer to Mollie (`Placeorder::REDIRECT_URL`).
2. **The customer comes back** (`zero_mollie/payment/return`, also Mollie's
   cancel URL). The payment's state is asked from Mollie:
   - `paid`: captured, invoice registered, order email;
   - `authorized` (pay-later methods): reserved, the order is processing;
     capture it in the Mollie dashboard;
   - `canceled`, `expired`, `failed`: the order cancelled, the cart restored;
   - `open` (came back without paying): the payment is cancelled where the
     method allows it, then the order;
   - `pending`: the success page with a note.
3. **Mollie's webhook** (`zero_mollie/payment/webhook?order=…`, POST
   `id=tr_…`): unsigned by Mollie's design, so it only triggers asking
   Mollie again; the id must be the order's own payment. Not sent from a
   development site (Mollie refuses webhooks it can't reach); there the
   return updates the order.
   Handled now or from the queue: Zero Checkout > Payment Webhooks
   (the Zero_Checkout README).

A payment for another amount or currency than the order's leaves the order
pending with a comment. **Refunds:** a credit memo from the invoice refunds
online, in full or in part, in the order's currency.

## Settings

Stores > Configuration > Sales > Payment Methods > **Mollie (Zero)**:
Enabled, Title, **API Key** (encrypted; `test_…` for test payments),
countries, sort order. The method shows only with a key. Not for orders made
in the admin.

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/Fixstacks/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/Fixstacks/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com/zero) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://fixstacks.com/zero/docs. Demo store: https://zero.fixstacks.com.
