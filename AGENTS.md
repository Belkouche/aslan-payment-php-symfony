# AGENTS.md - Aslan Payment PHP SDK (`aslan/payment-php` 0.2.0)

Instructions for AI coding agents, both when *using* this SDK in an application and when *editing* this repository. Full human guide: [README.md](README.md).

## Facts

- Namespace `Aslan\Payment`; entry point `Aslan\Payment\Aslan` (`new Aslan($skKey)`), resources as properties: `checkoutSessions`, `transactions`, `paymentLinks`, `refunds`, `vendors`, `customers`, `apiKeys`, `webhooksConfig`, `plans`, `subscriptions`, `billingAttempts`, `invoices`. Webhook verifier: `Aslan::webhooks($secret)`.
- Base URL `https://api.aslanpay.ma/pay`. Auth `Authorization: Bearer sk_live_...` or `sk_test_...`; mode = key prefix.
- Amounts: integers in centimes, currency `MAD` (14900 = 149.00 MAD).
- Errors: `Aslan\Payment\AslanException`; `getErrorCode()` / `$e->errorCode` is the string code, `getCode()` is the HTTP status (0 if none). No `$e->code`. The API sends `{"error": {"code","message","details"}}`; validation errors are HTTP 400 `VALIDATION_ERROR`, a duplicate resource (e.g. customer email) is 409 `CONFLICT`.
- PHP: `~8.2.35 || ~8.3.36 || ~8.4.27 || >=8.5.12` (fixes the "php2shell" RCEs CVE-2026-109618 / CVE-2026-109619). Never loosen it. Recommend PHP 8.4 or 8.5 (8.2 is end-of-life 2026-12-31).
- Laravel apps: use `aslan/payment-laravel` (https://github.com/Belkouche/aslan-payment-laravel).

## Rules when writing integration code

1. Amounts are int centimes. Never floats or strings.
2. Never log or hard-code `sk_*` keys, webhook secrets, or the subscription Paywall `payload`/`signature`. Use env vars.
3. Verify webhooks on the RAW body: `Aslan::webhooks($secret)->constructEvent(file_get_contents('php://input'), $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '')`. Header `X-Webhook-Signature: t=<unix>,v1=<hex>`, HMAC-SHA256 over `<t>.<rawBody>`, 300 s tolerance. Never verify a re-encoded array. Return 400 on `AslanException`.
4. Create the customer first: `customers->create(['email' => ..., 'name' => ..., 'phone' => '+212XXXXXXXXX'])` (at least email or phone; duplicate email = 409 `CONFLICT`). Subscription `create()` returns `SubscriptionCheckout`. Send the customer to the Paywall with `echo $checkout->autoSubmitFormHtml();` (form POST). Do not use a `Location` redirect for it. `changeCard()` / `repay()` return `SubscriptionPaymentLink`: plain GET redirect to `->redirectUrl` is fine.
5. Persist `$checkout->subscriptionId` before redirecting.
6. Never mark an order paid / activate access from `successUrl` or `returnUrl`. Wait for the verified webhook.
7. Webhooks can be duplicated: dedupe on `$event->id`, keep handlers idempotent, return 2xx fast.
8. Real event names only: `payment.succeeded`, `payment.failed`, `payment.expired`, `payment.pending_cash`, `payment.cash_late_confirmation`, `refund.created`, `refund.updated`, `subscription.renewed`, `subscription.payment_failed`, `subscription.past_due`, `subscription.cancelled`, `subscription.plan_changed`, `subscription.plan_change_failed`. `checkout_session.completed` / `transaction.captured` do not exist. Meanings that are easy to get wrong:
   - `subscription.cancelled` is sent only when the API cancels on its own: `cancelReason` `period_end`, `dunning` or `abandoned` (`pending_initial` unpaid after ~24 h). Immediate `cancel()`, `pause()`, `resume()`, `uncancel()` send **no** webhook.
   - Renewals send `subscription.renewed`, never `payment.succeeded` (that one covers one-off payments and a subscription's first payment).
   - `subscription.plan_changed` is sent as soon as `changePlan()` is accepted, for every mode; `effectiveAt` = now for `immediate`/`immediate_proration`, = `currentPeriodEnd` for `period_end`.
   - `subscription.plan_change_failed` = a scheduled `period_end` change could not be applied because the target plan was archived (renews on the current plan). A failed proration charge is NOT a webhook: `changePlan()` throws `AslanException` (400 `VALIDATION_ERROR`) and the plan is unchanged.
   - `payment.pending_cash` = customer chose cash and got a payment code (`referenceCode`, `networkName`, `expiresAt`). `payment.cash_late_confirmation` = cash paid after code expiry + grace (2 h default); transaction status `cash_late_confirmation`, not `succeeded`; reconcile manually.
   - Webhook delivery: 5 attempts total, retries ~10 s / 30 s / 90 s / 270 s (+0-50 % jitter), 10 s timeout, success = 2xx, redirects not followed. The `events` list given to `webhooksConfig->create()` is not stored: all events are delivered.
9. Subscription statuses: `pending_initial` (until first payment succeeds), `trialing`, `active`, `past_due`, `paused`, `cancelled`. Dunning: renewal failures 1 and 2 keep the status and retry after 2 and 5 days; failure 3 sets `past_due` (+ `subscription.past_due`) and retries after 7 days; failure 4 cancels (reason `dunning`). Each failure sends `subscription.payment_failed`; a successful retry sends `subscription.renewed` and returns to `active`. `repay()` works only on `pending_initial`; `changeCard()` swaps the card used by the next retry. `cancel($id)` ends now; `cancel($id, atPeriodEnd: true)` ends at period end (reversible with `uncancel`). `pause` only from `active`, `resume` only from `paused`.
10. Idempotency key (optional 2nd arg): honoured by the API only on `create()` of checkoutSessions, paymentLinks, refunds. vendors, apiKeys, webhooksConfig accept the argument but the API ignores it. plans, subscriptions, customers have none.
11. Use `sk_test_` keys unless the user asks for live.
12. Every redirect URL (`successUrl`, `cancelUrl`, `returnUrl`, `callbackUrl`) and the webhook URL must be `https://`. `callbackUrl` on subscription create/changeCard/repay is required but ignored by the API.
13. Some 0.1.0 DTO helpers do not match API values (`CheckoutSession::isPending/isCompleted`, `Transaction::isSuccessful/isRefundable`, `PaymentLink` and `Vendor` fields, `ApiKey::isLive/isTest`). Compare `->status` with API values instead; see README "Known gaps in 0.2.0".

## Intent to call

| Intent | Call |
|---|---|
| Charge once | `checkoutSessions->create([...], idempotencyKey: $k)` then redirect to `$session->url` |
| Reusable pay link / QR | `paymentLinks->create([...])`, `getQrCode($id)` |
| Refund | `refunds->create(['transactionId' => $id, 'amount' => $centimes])` |
| Recurring price | `plans->create(['name','amount','currency','interval', 'trialDays'?])` |
| Create customer | `customers->create(['email'?, 'phone'?, 'name'?, 'metadata'?])` (email or phone required) |
| Start subscription | `customers->create([...])`, then `subscriptions->create(['planId','customerId','returnUrl','cancelUrl','callbackUrl'])` then `autoSubmitFormHtml()` |
| Retry first payment | `subscriptions->repay($id, [urls])` |
| Fix card / failing renewals / past_due | `subscriptions->changeCard($id, [urls])` |
| Change plan | `subscriptions->changePlan($id, $planId, when: 'immediate'\|'period_end'\|'immediate_proration', allowDowngrade: bool)` |
| Cancel / undo / pause / resume | `cancel` / `uncancel` / `pause` / `resume` |
| Receipts, invoices | `billingAttempts->receipt/receiptPdf/facture`, `invoices->retrieve/pdf` |
| Register webhook endpoint | `webhooksConfig->create(['url' => 'https://...'])`; `->signingSecret` is set only the first time (null afterwards) |

## Anti-patterns

`$e->code`; float amounts; `Location` redirect to the Paywall; `json_decode` + `json_encode` before verifying; granting access on redirect; unknown event names; header `Aslan-Signature`; logging secrets; idempotency key on plans/subscriptions/customers; waiting for `subscription.cancelled` after your own immediate `cancel()`; expecting `payment.succeeded` for renewals; `repay()` on a non-`pending_initial` subscription; `http://` URLs; trusting a partial `asSubscription()->status` (may be null; call `subscriptions->retrieve()`); relaxing the PHP constraint.

## Rules when editing this repository

- Code: `src/` (`Aslan.php`, `AslanException.php`, `Resources/`, `Dto/`, `HttpClient/`). Tests: `tests/` (PHPUnit 11). Run `vendor/bin/phpunit` (or `composer test`) before committing.
- DTOs are `final readonly` classes with a `fromArray(array): self`; resources unwrap `$response['data'] ?? $response`.
- Keep README samples in sync with real class/method names and DTO property names; verify against `src/`. Verify API behaviour claims against the `aslan-payment-api` source, not assumptions.
- Do not change `composer.json` PHP/dependency floors without a security reason; run `composer audit`.
- Never add real secrets to tests or docs; use fake `sk_test_...` values.
