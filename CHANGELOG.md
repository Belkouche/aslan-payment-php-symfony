# Changelog

## 0.2.0 (2026-10-22)

### Added
- Subscription billing:
  - `$aslan->plans` — `create`, `list`, `retrieve`, `update` (archive with `isActive: false`).
  - `$aslan->subscriptions` — `create` (returns the hosted Paywall; `autoSubmitFormHtml()` renders the required form POST), `list`, `retrieve`, `update` (price override), `changePlan`, `cancel` (immediately or at period end), `uncancel`, `pause`, `resume`, `changeCard`, `repay`.
  - `$aslan->billingAttempts` — `list`, `receipt`, `receiptPdf` (raw bytes), `facture`.
  - `$aslan->invoices` — `list`, `retrieve`, `pdf` (raw bytes).
  - DTOs: `Plan`, `Subscription`, `SubscriptionCustomer`, `SubscriptionCard`, `SubscriptionCheckout`, `SubscriptionPaymentLink`, `SubscriptionPlanChange`, `SubscriptionStatusChange`, `SubscriptionPriceOverride`, `BillingAttempt`, `Invoice`, `InvoiceLine`, `DocumentData`.
- `subscription.*` webhook events: `WebhookEvent::asSubscription()`, `WebhookEvent::isSubscriptionEvent()`.
- `RawHttpClientInterface` (implemented by `SymfonyHttpClient`) for PDF downloads.
- `AslanException::$errorCode` / `getErrorCode()`.

### Fixed
- **`AslanException` caused a fatal error on load** on every PHP 8.x (`public readonly string $code` redeclared `Exception::$code`), so every SDK error path crashed instead of throwing. The API error code is now `$errorCode` / `getErrorCode()`; `getCode()` returns the HTTP status (int, `0` when none). **Breaking:** replace `$e->code` with `$e->errorCode`.
- Webhook header name is `X-Webhook-Signature` (was documented as `Aslan-Signature`). The `t=<unix>,v1=<hmac_hex>` scheme over `"<t>.<rawBody>"` is unchanged.
- `WebhookEvent` reads the event type from the payload's `event` field and parses the ISO 8601 `createdAt` (previously cast to an int, yielding the year); `asTransaction()` also reads `data.transaction`.
- Body-less `POST`/`DELETE` requests no longer send `Content-Type: application/json`, which the API rejects with an empty body.
- `PaginatedResponse::$totalPages` is derived from `total`/`pageSize` when the API omits it.

### Security / requirements
- PHP constraint is now `~8.2.35 || ~8.3.36 || ~8.4.27 || >=8.5.12`: Composer refuses to install on PHP builds vulnerable to CVE-2026-109618 / CVE-2026-109619 ("php2shell", fixed 2026-10-21).
- `symfony/http-client` `^6.4.15 || ^7.4 || ^8.1` (maintained lines; excludes versions affected by CVE-2024-50342), `symfony/contracts` `^3.5`.
- Dev: PHPUnit `^11.5.50` (latest major supporting PHP 8.2; excludes CVE-2026-24765).

## 0.1.0 (2024-01)

- Initial release
- CheckoutSessions, Transactions, PaymentLinks, Refunds, Vendors, Customers, ApiKeys, WebhooksConfig
- Webhook signature verification with replay-attack protection
- Symfony HttpClient adapter with exponential back-off retry
- PHP readonly DTOs
