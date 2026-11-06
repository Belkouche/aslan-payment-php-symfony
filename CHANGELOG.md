# Changelog

## Unreleased

### Added
- Several PSP accounts per merchant (aslan-payment-api PR #42): optional `providerAccount` alias (`^[a-z0-9][a-z0-9-]{0,31}$`, `default` = default account) on `checkoutSessions->create`, `paymentLinks->create` and `subscriptions->create` (documented; params are passed through as-is).
- `providerAccount` (`?string`) on the `CheckoutSession`, `Transaction`, `PaymentLink` and `Subscription` DTOs. Payment webhook payloads carry it in `data.transaction` (may be null).
- Error codes `PROVIDER_ACCOUNT_NOT_FOUND`, `PROVIDER_ACCOUNT_INACTIVE`, `PROVIDER_ACCOUNT_UNSUPPORTED`, `PROVIDER_ACCOUNT_RECURRING_DISABLED` (HTTP 400, available as `AslanException::$errorCode`).
- `subscription.renewal_suspended` webhook event (read `reason`, `skippedPeriodStart`, `skippedPeriodEnd` from `$event->data['subscription']`; `asSubscription()` exposes `id`, `planId`, `nextBillingAt`).

## 0.2.0 (2026-10-22)

### Added
- Subscription billing:
  - `$aslan->plans` — `create`, `list`, `retrieve`, `update` (archive with `isActive: false`).
  - `$aslan->subscriptions` — `create` (returns the hosted Paywall; `autoSubmitFormHtml()` renders the required form POST), `list`, `retrieve`, `update` (price override), `changePlan`, `cancel` (immediately or at period end), `uncancel`, `pause`, `resume`, `changeCard`, `repay`.
  - `$aslan->billingAttempts` — `list`, `receipt`, `receiptPdf` (raw bytes), `facture`.
  - `$aslan->invoices` — `list`, `retrieve`, `pdf` (raw bytes).
  - DTOs: `Plan`, `Subscription`, `SubscriptionCustomer`, `SubscriptionCard`, `SubscriptionCheckout`, `SubscriptionPaymentLink`, `SubscriptionPlanChange`, `SubscriptionStatusChange`, `SubscriptionPriceOverride`, `BillingAttempt`, `Invoice`, `InvoiceLine`, `DocumentData`.
- `$aslan->customers->create()` (`POST /api/v1/customers`): email and/or `+212XXXXXXXXX` phone, optional name and metadata; a duplicate email surfaces as `AslanException` (HTTP 409, `CONFLICT`). No idempotency key (not supported by the API on this route).
- `subscription.*` webhook events: `WebhookEvent::asSubscription()`, `WebhookEvent::isSubscriptionEvent()`.
- `RawHttpClientInterface` (implemented by `SymfonyHttpClient`) for PDF downloads.
- `AslanException::$errorCode` / `getErrorCode()`.

### Fixed
- DTOs now map the API's real response shapes (0.1.0 names are kept as deprecated aliases where cheap):
  - `CheckoutSession`: `isPending()` is true for `open` / `awaiting_cash` / `processing`, `isCompleted()` for `complete`; `transactionId` (and new `transactionStatus`) are read from the API's nested `transaction` on `retrieve()`; `openAmount` is derived from a null `amount` when absent.
  - `Transaction`: `isSuccessful()` tests `succeeded`; `isRefundable()` tests `succeeded` / `partially_refunded`; `refundedAmount` is computed from the returned refunds (excluding `failed` / `rejected`, the API's own rule). New `providerRef`, `paidAt`, `failedAt`, `errorMessage`, `refunds`. A null or missing API amount reads as 0.
  - `PaymentLink`: new `shortCode`, `maxPayments`, `paymentCount`, `minAmount`, `maxAmount`, `purpose`, `vendorId`; `hasReachedMaxUses()` uses them; new `isFinalized()`. `title` / `slug` / `maxUses` / `usageCount` are deprecated aliases.
  - `Vendor`: `status` / `isActive()` come from the API's `isActive`; new `externalId`, `commissionRate`. `code` is a deprecated alias of `externalId`.
  - `ApiKey`: new `type` (read from the API's `type`), `publishableKey`, `revokedAt`; `isLive()` / `isTest()` use `type`; `status` comes from `isActive`. `environment` is a deprecated alias.
  - `Customer`: `totalSpend` / `transactionCount` (and new `successfulTransactionCount`) are read from `retrieve()`'s `transactions` summary; the internal `encryptedEmail` key the API leaks in customer list metadata is removed from `$metadata`.
- `PaginatedResponse` reads the vendors list envelope (top-level `total` / `page` / `limit` / `totalPages`).
- Resource docblocks list the parameters the API really accepts.
- **`AslanException` caused a fatal error on load** on every PHP 8.x (`public readonly string $code` redeclared `Exception::$code`), so every SDK error path crashed instead of throwing. The API error code is now `$errorCode` / `getErrorCode()`; `getCode()` returns the HTTP status (int, `0` when none). **Breaking:** replace `$e->code` with `$e->errorCode`.
- Webhook header name is `X-Webhook-Signature` (was documented as `Aslan-Signature`). The `t=<unix>,v1=<hmac_hex>` scheme over `"<t>.<rawBody>"` is unchanged.
- `WebhookEvent` reads the event type from the payload's `event` field and parses the ISO 8601 `createdAt` (previously cast to an int, yielding the year); `asTransaction()` also reads `data.transaction`.
- Body-less `POST`/`DELETE` requests no longer send `Content-Type: application/json`, which the API rejects with an empty body.
- `PaginatedResponse::$totalPages` is derived from `total`/`pageSize` when the API omits it.
- API errors are read from the API's `{"error": {"code", "message", "details"}}` envelope (in `SymfonyHttpClient` and `AslanException::fromApiResponse()`). Previously the API message was replaced by a generic one and every 400 surfaced as `SERVER_ERROR` instead of `VALIDATION_ERROR`.
- `WebhookConfig::$signingSecret` is read from the API's `secret` field (full `whsec_...` value only; the masked value returned on later calls yields null); a missing `id` no longer raises an undefined-key warning.
- `WebhookEvent::asRefund()` reads `data.refund`, where the API puts refund payloads.

### Documentation
- README and AGENTS.md fact-checked against the current API: idempotency support per route, HTTPS-only URLs, subscription lifecycle (trials: refunded verification charge at signup and first charge at trial end, dunning schedule incl. trial-end failures, billing anchor day and catch-up re-anchoring, price-override window, plan changes during a trial, which actions send which webhooks), `subscription.renewed` metadata, `payment.succeeded` for verification charges, payment links paid once, `subscription.plan_change_failed` meaning, cash events, webhook delivery retries/timeout, request parameters of 0.1.0-era resources.

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
