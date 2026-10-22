# AGENTS.md - Aslan Payment PHP SDK (`aslan/payment-php` 0.2.0)

Instructions for AI coding agents, both when *using* this SDK in an application and when *editing* this repository. Full human guide: [README.md](README.md).

## Facts

- Namespace `Aslan\Payment`; entry point `Aslan\Payment\Aslan` (`new Aslan($skKey)`), resources as properties: `checkoutSessions`, `transactions`, `paymentLinks`, `refunds`, `vendors`, `customers`, `apiKeys`, `webhooksConfig`, `plans`, `subscriptions`, `billingAttempts`, `invoices`. Webhook verifier: `Aslan::webhooks($secret)`.
- Base URL `https://api.aslanpay.ma/pay`. Auth `Authorization: Bearer sk_live_...` or `sk_test_...`; mode = key prefix.
- Amounts: integers in centimes, currency `MAD` (14900 = 149.00 MAD).
- Errors: `Aslan\Payment\AslanException`; `getErrorCode()` / `$e->errorCode` is the string API code, `getCode()` is the HTTP status (0 if none). No `$e->code`.
- PHP: `~8.2.35 || ~8.3.36 || ~8.4.27 || >=8.5.12` (fixes the "php2shell" RCEs CVE-2026-109618 / CVE-2026-109619). Never loosen it. Recommend PHP 8.4 or 8.5 (8.2 is end-of-life 2026-12-31).
- Laravel apps: use `aslan/payment-laravel` (https://github.com/Belkouche/aslan-payment-laravel).

## Rules when writing integration code

1. Amounts are int centimes. Never floats or strings.
2. Never log or hard-code `sk_*` keys, webhook secrets, or the subscription Paywall `payload`/`signature`. Use env vars.
3. Verify webhooks on the RAW body: `Aslan::webhooks($secret)->constructEvent(file_get_contents('php://input'), $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '')`. Header `X-Webhook-Signature: t=<unix>,v1=<hex>`, HMAC-SHA256 over `<t>.<rawBody>`, 300 s tolerance. Never verify a re-encoded array. Return 400 on `AslanException`.
4. Subscription `create()` returns `SubscriptionCheckout`. Send the customer to the Paywall with `echo $checkout->autoSubmitFormHtml();` (form POST). Do not use a `Location` redirect for it. `changeCard()` / `repay()` return `SubscriptionPaymentLink`: plain GET redirect to `->redirectUrl` is fine.
5. Persist `$checkout->subscriptionId` before redirecting.
6. Never mark an order paid / activate access from `successUrl` or `returnUrl`. Wait for the verified webhook.
7. Webhooks can be duplicated: dedupe on `$event->id`, keep handlers idempotent, return 2xx fast.
8. Real event names only: `payment.succeeded`, `payment.failed`, `payment.expired`, `payment.pending_cash`, `payment.cash_late_confirmation`, `refund.created`, `refund.updated`, `subscription.renewed`, `subscription.payment_failed`, `subscription.past_due`, `subscription.cancelled`, `subscription.plan_changed`, `subscription.plan_change_failed`. `checkout_session.completed` / `transaction.captured` do not exist.
9. Subscription statuses: `pending_initial` (until first payment succeeds), `trialing`, `active`, `past_due`, `paused`, `cancelled`. `cancel($id)` ends now; `cancel($id, atPeriodEnd: true)` ends at period end (reversible with `uncancel`).
10. Idempotency key (optional 2nd arg) exists on `create()` of checkoutSessions, paymentLinks, refunds, vendors, apiKeys, webhooksConfig only.
11. Use `sk_test_` keys unless the user asks for live.

## Intent to call

| Intent | Call |
|---|---|
| Charge once | `checkoutSessions->create([...], idempotencyKey: $k)` then redirect to `$session->url` |
| Reusable pay link / QR | `paymentLinks->create([...])`, `getQrCode($id)` |
| Refund | `refunds->create(['transactionId' => $id, 'amount' => $centimes])` |
| Recurring price | `plans->create(['name','amount','currency','interval', 'trialDays'?])` |
| Start subscription | `subscriptions->create(['planId','customerId','returnUrl','cancelUrl','callbackUrl'])` then `autoSubmitFormHtml()` |
| Retry first payment | `subscriptions->repay($id, [urls])` |
| Fix card / past_due | `subscriptions->changeCard($id, [urls])` |
| Change plan | `subscriptions->changePlan($id, $planId, when: 'immediate'\|'period_end'\|'immediate_proration', allowDowngrade: bool)` |
| Cancel / undo / pause / resume | `cancel` / `uncancel` / `pause` / `resume` |
| Receipts, invoices | `billingAttempts->receipt/receiptPdf/facture`, `invoices->retrieve/pdf` |
| Register webhook endpoint | `webhooksConfig->create(['url' => ..., 'events' => [...]])` (secret returned once) |

## Anti-patterns

`$e->code`; float amounts; `Location` redirect to the Paywall; `json_decode` + `json_encode` before verifying; granting access on redirect; unknown event names; header `Aslan-Signature`; logging secrets; idempotency key on plans/subscriptions; trusting a partial `asSubscription()->status` (may be null; call `subscriptions->retrieve()`); relaxing the PHP constraint.

## Rules when editing this repository

- Code: `src/` (`Aslan.php`, `AslanException.php`, `Resources/`, `Dto/`, `HttpClient/`). Tests: `tests/` (PHPUnit 11). Run `vendor/bin/phpunit` (or `composer test`) before committing.
- DTOs are `final readonly` classes with a `fromArray(array): self`; resources unwrap `$response['data'] ?? $response`.
- Keep README samples in sync with real class/method names and DTO property names; verify against `src/`.
- Do not change `composer.json` PHP/dependency floors without a security reason; run `composer audit`.
- Never add real secrets to tests or docs; use fake `sk_test_...` values.
