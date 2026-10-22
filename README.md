# Aslan Payment PHP SDK

Official PHP SDK for the [Aslan Payment API](https://aslanpay.ma): accept card payments, share payment links, issue refunds, bill customers on a recurring basis (subscriptions), and receive signed webhook events.

- Package: `aslan/payment-php` (version **0.2.0**)
- API base URL: `https://api.aslanpay.ma/pay`
- Language: PHP, framework-agnostic (uses Symfony HttpClient under the hood)
- Using Laravel? Use the dedicated integration instead: [`aslan/payment-laravel`](https://github.com/Belkouche/aslan-payment-laravel).
- Writing code with an AI coding agent? Jump to [For LLM coding agents](#11-for-llm-coding-agents) (also see [`AGENTS.md`](AGENTS.md) and [`llms.txt`](llms.txt)).

> **Still on 0.1.0? Upgrade now.** In 0.1.0 the exception class `AslanException` could not even be loaded (PHP raised a fatal error), so every error path of the SDK crashed instead of throwing a catchable exception. 0.2.0 fixes this. See [Upgrading from 0.1.0](#13-upgrading-from-010).

## Table of contents

1. [What this is and who it is for](#1-what-this-is-and-who-it-is-for)
2. [Requirements and why PHP must be patched](#2-requirements-and-why-php-must-be-patched)
3. [Installation](#3-installation)
4. [Concepts in 2 minutes](#4-concepts-in-2-minutes)
5. [Quick start: a one-time payment](#5-quick-start-a-one-time-payment)
6. [Subscriptions, step by step](#6-subscriptions-step-by-step)
7. [Webhooks in depth](#7-webhooks-in-depth)
8. [Errors and retries](#8-errors-and-retries)
9. [Testing your integration](#9-testing-your-integration)
10. [API reference](#10-api-reference)
11. [For LLM coding agents](#11-for-llm-coding-agents)
12. [Security checklist](#12-security-checklist)
13. [Upgrading from 0.1.0](#13-upgrading-from-010)
14. [Changelog, license, support](#14-changelog-license-support)

---

## 1. What this is and who it is for

This SDK is a thin, typed PHP wrapper around the Aslan Payment API. You, the merchant, run a website or backend; Aslan runs the payment page and talks to the card networks. Your code never sees a card number.

Typical flows:

| You want to... | You use... |
|---|---|
| Charge a customer once, for an order | a **checkout session** (section 5) |
| Share a "pay me" URL or QR code (invoice, donation), paid once | a **payment link** |
| Charge a customer every month/year | a **plan** + a **subscription** (section 6) |
| Give money back | a **refund** |
| Know that a payment really happened | a **webhook** (section 7) |

The SDK is organised as one main class, `Aslan\Payment\Aslan`, with one property per API resource (`$aslan->checkoutSessions`, `$aslan->subscriptions`, ...). Almost every method returns a typed, read-only object (a DTO) such as `CheckoutSession` or `Subscription`. The exceptions: `paymentLinks->getQrCode()` returns an array, the PDF methods return raw bytes, `delete()` methods return a bool, and webhook payloads also give you the raw array in `$event->data`.

---

## 2. Requirements and why PHP must be patched

| Requirement | Constraint | Where it is enforced |
|---|---|---|
| PHP | `~8.2.35 \|\| ~8.3.36 \|\| ~8.4.27 \|\| >=8.5.12` | `composer.json` |
| `symfony/http-client` | `^6.4.15 \|\| ^7.4 \|\| ^8.1` | `composer.json` |
| `symfony/contracts` | `^3.5` | `composer.json` |
| PHPUnit (dev only) | `^11.5.50` | `composer.json` |

### Why PHP >= 8.x.y? (read this, it is short)

On **2026-10-21** the PHP project released **8.5.12, 8.4.27, 8.3.36 and 8.2.35**. These releases fix two critical, unauthenticated **remote code execution** (RCE) flaws:

- **CVE-2026-109618**: out-of-memory corruption
- **CVE-2026-109619**: stale global state

Together they are nicknamed **"php2shell"** (CVSS v4 score 9.2, i.e. "critical"). They were publicly disclosed *before* the fix existed, so attackers had a head start. "Unauthenticated RCE" means a stranger on the internet can make your server run code of their choosing, without logging in.

A payment integration holds the keys to money: your API secret key and your webhook secret live on that server. An attacker who runs code there can read both. So this SDK takes a firm stance: **it refuses to install on a PHP version that is vulnerable.**

Sources:

- [PHP release announcement](https://news-web.php.net/php.announce/509)
- [PHP supported versions](https://www.php.net/supported-versions.php)
- [Help Net Security coverage](https://www.helpnetsecurity.com/2026/10/21/php2shell-php-vulnerabilities-patched-cve-2026-109618-cve-2026-109619/)

### How to read the constraint

`~8.3.36` means "8.3.36 **or later 8.3.x**, but not 8.4". The four alternatives are OR-ed together, one per supported PHP line:

| Your PHP line | Minimum accepted version |
|---|---|
| 8.2.x | 8.2.35 |
| 8.3.x | 8.3.36 |
| 8.4.x | 8.4.27 |
| 8.5.x and later | 8.5.12 |
| 8.1 or older | not supported (also end-of-life) |

### What Composer does about it

- `composer require` / `composer install` **refuses to install** the SDK on a vulnerable PHP and tells you which platform requirement failed.
- Composer also generates a runtime `platform_check` in `vendor/composer/`, but it only enforces the **lowest bound (8.2.35)**, not the per-line floors. Do not rely on it: **keep the PHP that actually serves your site patched**, not just the one that ran `composer install` on a build machine.

### Check and fix your PHP

```bash
php -v          # e.g. "PHP 8.4.27 (cli) ..." -> OK;  "PHP 8.4.25" -> too old
composer check-platform-reqs
```

To upgrade, use your OS package manager or image (for example a newer official `php:8.4` Docker tag, or your distribution's security update), then restart PHP-FPM / Apache / your worker processes. Check `php -v` for the *web* PHP too (`php-fpm -v`), since the CLI and FPM can differ.

**Interim mitigation (only if you cannot upgrade immediately):** set `session.upload_progress.enabled=0` in `php.ini`. This blocks the *known* exploitation paths of the second flaw. It does **not** cover all variants and does not replace upgrading.

### End of life for PHP 8.2

PHP 8.2 reaches **end of life on 2026-12-31**; after that it gets no security fixes. New projects should use **PHP 8.4 or 8.5**.

### The other version floors, and why

- `symfony/http-client ^6.4.15 || ^7.4 || ^8.1`: 6.4 is an LTS line that still receives patches; 7.4 and 8.1 are current. The `6.4.15` floor excludes versions affected by CVE-2024-50342.
- `symfony/contracts ^3.5`: the contracts line shared by the maintained Symfony versions.
- PHPUnit `^11.5.50` (development only): the floor excludes CVE-2026-24765. PHPUnit 11 is the last major that still runs on PHP 8.2.

Run `composer audit` in your CI so that known-vulnerable dependencies fail the build:

```bash
composer audit
```

---

## 3. Installation

```bash
composer require aslan/payment-php
```

If the package is not on Packagist yet, install it straight from GitHub by adding a VCS repository to your `composer.json`, then requiring it:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Belkouche/aslan-payment-php-symfony" }
    ]
}
```

```bash
composer require aslan/payment-php
```

Then create the client once and reuse it:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Aslan\Payment\Aslan;

$aslan = new Aslan(getenv('ASLAN_SECRET_KEY')); // sk_test_... or sk_live_...
```

Constructor arguments (all but the key are optional):

| Argument | Type | Default | Meaning |
|---|---|---|---|
| `$secretKey` | `string` | required | `sk_live_...` or `sk_test_...`. Any other prefix throws `AslanException` with code `AUTHENTICATION_ERROR` |
| `$baseUrl` | `string` | `https://api.aslanpay.ma/pay` | Override only for special environments |
| `$timeout` | `int` | `30` | Request timeout in seconds |
| `$maxRetries` | `int` | `2` | Automatic retries on transient errors |
| `$httpClient` | `?HttpClientInterface` | Symfony client | Inject your own (tests, other HTTP libraries) |

Helpers: `$aslan->isTestMode()`, `$aslan->isLiveMode()`, `$aslan->getBaseUrl()`.

---

## 4. Concepts in 2 minutes

### Keys and modes

You authenticate with a secret key sent as `Authorization: Bearer sk_...` (the SDK does this for you). **Test or live mode is decided only by the key prefix**:

| Prefix | Mode | Money moves? |
|---|---|---|
| `sk_test_...` | test | No. Safe for development |
| `sk_live_...` | live | Yes. Real customers, real money |

There is no "mode" flag to set. Switch key, switch mode. Keep keys in environment variables, never in source control.

### Amounts are integers in centimes

All amounts are **integers in centimes** (1/100 of a dirham). The currency is **MAD**.

| You want to charge | You send |
|---|---|
| 149.00 MAD | `14900` |
| 50.00 MAD | `5000` |
| 0.50 MAD | `50` |

Never send floats (`149.0`, `"149.00"`). Convert once at the edge: `(int) round($priceInMad * 100)`.

### Checkout session vs payment link vs subscription

- **Checkout session**: a single-use, time-limited payment for one order (it expires 30 minutes after creation; 48 hours when cash is offered). You create it, redirect the customer to `$session->url`, done.
- **Payment link**: a shareable URL (with a QR code, optional expiry and attempt limit) that you can send by any channel. It is **paid once**: after a successful payment the API sets it to `finalized` and refuses further payments, so create one link per invoice. Good for invoices and donations.
- **Subscription**: a customer pays a **plan** automatically every interval until cancelled. Needs a customer who saves a card once.

### Webhooks are the source of truth

When the customer comes back to your `successUrl`, that only proves their *browser* arrived. It does not prove the payment succeeded: anyone can type that URL. The reliable signal is a **signed webhook** (`payment.succeeded`, ...) sent server-to-server to you. Mark an order as paid only when that webhook arrives and verifies. See [section 7](#7-webhooks-in-depth).

### Idempotency

Creating things over a network can fail halfway (timeout: did it work or not?). To retry safely, pass an **idempotency key** (sent as the `Idempotency-Key` header): the same key returns the cached result (kept 24 hours) instead of creating a duplicate.

The API honours the key on exactly three of the SDK's create calls: `checkoutSessions->create`, `paymentLinks->create` and `refunds->create`. A replayed response carries the header `Idempotency-Replayed: true`; a second request with the same key while the first is still running is rejected with `409 CONFLICT`.

```php
$session = $aslan->checkoutSessions->create($params, idempotencyKey: 'order_' . $orderId);
```

`vendors->create`, `apiKeys->create` and `webhooksConfig->create` also accept the argument, but the API ignores the header on those routes, so it gives no protection there. `plans->create`, `subscriptions->create` and `customers->create` take no key at all. Guard against double submission on your side (for example store the `subscriptionId` against your customer record first and refuse to create a second one; a duplicate customer email is rejected with `409` anyway).

---

## 5. Quick start: a one-time payment

### Step 1: create a checkout session

```php
use Aslan\Payment\Aslan;
use Aslan\Payment\AslanException;

$aslan = new Aslan(getenv('ASLAN_SECRET_KEY'));

try {
    $session = $aslan->checkoutSessions->create([
        'amount'     => 14900,                        // 149.00 MAD
        'currency'   => 'MAD',
        'successUrl' => 'https://shop.example/thanks', // required, must be https://
        'cancelUrl'  => 'https://shop.example/cart',   // required, must be https://
        'customer'   => ['email' => 'jane@example.com', 'name' => 'Jane Doe'], // optional
        'metadata'   => ['orderId' => '1042'],        // your own order id goes here
    ], idempotencyKey: 'order_1042');
} catch (AslanException $e) {
    error_log($e->getErrorCode() . ': ' . $e->getMessage());
    http_response_code(502);
    exit('Could not start the payment.');
}

header('Location: ' . $session->url);   // send the customer to the hosted payment page
exit;
```

**What happens:** the API creates a checkout session (status `open`, expires 30 minutes after creation; there is no parameter to change that) and returns its hosted `url`. The customer pays on Aslan's page. Card data never touches your server. Fields the API does not know (for example `reference`, `description`, `expiresIn`) are silently dropped, so put your own identifiers in `metadata` (max 20 keys, keys up to 40 chars, values up to 500 chars; keys starting with `__` are reserved and dropped).

`CheckoutSession` properties: `id`, `token`, `url`, `amount` (`?int`), `currency`, `status`, `openAmount`, `transactionId`, `transactionStatus`, `successUrl`, `cancelUrl`, `metadata`, `expiresAt`, `createdAt` (plus deprecated `reference`, `description`, `updatedAt`, which the API never fills). `create()` returns only the core fields; `retrieve()` adds `successUrl`, `cancelUrl`, `metadata` and the linked transaction (`transactionId`, `transactionStatus`). Status values: `open`, `awaiting_cash`, `processing`, `complete`, `expired`. Helpers: `isPending()` (`open`, `awaiting_cash` or `processing`), `isCompleted()` (`complete`), `isExpired()`.

### Step 2: wait for the webhook

Do **not** mark the order paid on `successUrl`. Configure a webhook (section 7) and, when `payment.succeeded` arrives and verifies, mark order `1042` as paid, using the transaction id or the `metadata` you stored.

### Step 3 (optional): look it up later

```php
$session     = $aslan->checkoutSessions->retrieve($sessionId);      // $session->status: open | awaiting_cash | processing | complete | expired
$transaction = $aslan->transactions->retrieve($transactionId);       // id from the payment.succeeded webhook
echo $transaction->status;   // pending | pending_cash | cash_late_confirmation | processing | succeeded | failed | expired | refunded | partially_refunded
```

### Payment link example

A link is paid once: after a successful payment its status becomes `finalized`.

```php
$link = $aslan->paymentLinks->create([
    'description' => 'Support our association',
    // no 'amount' => the payer chooses it (optionally bounded by minAmount / maxAmount)
    'minAmount'   => 1000,
]);
echo $link->url;                          // share it
$qr = $aslan->paymentLinks->getQrCode($link->id);   // ['dataUri' => 'data:image/png;base64,...', 'mimeType' => 'image/png']
```

### Refund example

```php
$refund = $aslan->refunds->create([
    'transactionId' => $transactionId,
    'amount'        => 5000,              // required, centimes (50.00 MAD); pass the full amount for a full refund
    'reason'        => 'customer_request', // optional free text, max 500 chars
], idempotencyKey: 'refund_1042_1');
```

**Common mistakes**

- Sending `149.00` or `"149"` instead of `14900`.
- Treating the redirect to `successUrl` as proof of payment.
- Forgetting that open-amount sessions and links have `amount === null`.
- Using `http://` URLs: `successUrl` / `cancelUrl` must be `https://` (the API rejects anything else with a 400).
- Creating the `Aslan` client with a `pk_...` key (only `sk_live_` / `sk_test_` are accepted).

---

## 6. Subscriptions, step by step

### The big picture

```
 You                         Aslan API                    Customer
  |  1. plans->create()  ------>|                              |
  |  2. customers->create() ---->|                              |
  |  3. subscriptions->create -->|  (status: pending_initial)   |
  |<-- SubscriptionCheckout -----|                              |
  |  4. autoSubmitFormHtml() ----------------------------------> browser POSTs to Paywall
  |                              |<---- card + 3-D Secure ------|
  |<== webhook payment.succeeded |  (active; or trialing after a refunded card check)
  |                              |                              |
  |<== webhook subscription.renewed   every period, automatically
  |<== webhook subscription.payment_failed (+ past_due / cancelled)   when renewals fail (dunning)
```

Lifecycle of the subscription `status`:

```mermaid
stateDiagram-v2
    [*] --> pending_initial: subscriptions.create()
    pending_initial --> pending_initial: repay() (fresh Paywall link)
    pending_initial --> active: first payment succeeded (plan without trial)
    pending_initial --> trialing: card saved, verification refunded (plan with trialDays)
    pending_initial --> cancelled: unpaid after ~24 h (abandoned)
    trialing --> active: trial-end charge succeeded (subscription.renewed)
    trialing --> past_due: trial-end charge failed (retry in 2 days)
    active --> active: renewal succeeded (subscription.renewed)
    active --> active: 1st / 2nd renewal failure, retry in 2 / 5 days
    active --> past_due: 3rd consecutive failure (retry in 7 days)
    past_due --> active: a dunning retry succeeds (subscription.renewed)
    past_due --> cancelled: 4th consecutive failure (dunning)
    active --> paused: pause()
    paused --> active: resume()
    active --> cancelled: cancel() / period end after cancel(atPeriodEnd)
    past_due --> cancelled: cancel() / period end after cancel(atPeriodEnd)
    trialing --> cancelled: cancel() / period end after cancel(atPeriodEnd)
    paused --> cancelled: cancel() / period end after cancel(atPeriodEnd)
    cancelled --> [*]
```

| Status | Meaning | What you should do |
|---|---|---|
| `pending_initial` | Created, first payment not completed yet. If still unpaid after about 24 hours it is cancelled as abandoned (`subscription.cancelled`) | Do **not** grant access. If the customer abandoned the Paywall, call `repay()` |
| `trialing` | Card saved on a plan with `trialDays > 0` (only a refunded verification amount was charged); the trial runs until `trialEndsAt`, where the first real charge is made | Grant access |
| `active` | Paid up, renews automatically. Also stays `active` after a 1st or 2nd failed renewal while retries are pending | Grant access |
| `past_due` | Three renewal charges in a row failed (one last retry 7 days later), or the trial-end charge failed (retries after 2, 5 and 7 days) | Warn the customer; offer `changeCard()`. Decide your own grace policy |
| `paused` | Paused via `pause()` (only an `active` subscription can be paused); not billed while paused | Suspend access per your policy |
| `cancelled` | Ended (merchant cancel, period end, dunning or abandoned) | Revoke access |

The statuses are exposed as `Subscription::$status` (a nullable string). Helpers: `isActive()` (`active` or `trialing`), `isPastDue()`, `isCancelled()`.

### Step 1: create a plan (once)

A plan is the price list entry: how much, how often. Plans cannot be deleted, only archived. `amount` and `interval` are immutable after creation.

```php
$plan = $aslan->plans->create([
    'name'          => 'Pro (monthly)',
    'amount'        => 9900,          // 99.00 MAD per period, in centimes
    'currency'      => 'MAD',
    'interval'      => 'monthly',     // daily | weekly | monthly | quarterly | yearly
    'intervalCount' => 1,             // optional, 1-12 (every N intervals)
    'trialDays'     => 14,            // optional, 0 = no trial
    'metadata'      => ['tier' => 'pro'],
]);

echo $plan->id;                       // keep this uuid
```

`Plan` properties: `id`, `name`, `amount`, `currency`, `interval`, `intervalCount`, `trialDays`, `isActive`, `tvaRateBps` (`?int`), `metadata`, `createdAt`, `updatedAt`. Helper: `hasTrial()`.

Archive instead of delete: `$aslan->plans->update($plan->id, ['isActive' => false]);` (only `name`, `isActive`, `metadata` can change).

### Step 2: create the customer

A subscription belongs to an Aslan **customer** (`customerId`, a uuid). Create one with at least an email or a phone number:

```php
use Aslan\Payment\AslanException;

try {
    $customer = $aslan->customers->create([
        'email'    => 'jane@example.com',   // unique per merchant
        'name'     => 'Jane Doe',           // optional, max 200 chars
        'phone'    => '+212612345678',      // optional, format +212XXXXXXXXX
        'metadata' => ['crmId' => '42'],    // optional string map
    ]);
} catch (AslanException $e) {
    if ($e->getCode() === 409) {            // errorCode CONFLICT: this email already exists
        // use the customer id you stored the first time (see below)
    }
    throw $e;
}

$customerId = $customer->id;               // store it against your user
```

Store the returned `id` against your own user record and reuse it; creating the same email twice is rejected with `409`. The API offers no reliable lookup by email: emails and names are stored encrypted, so `customers->list(['search' => ...])` does not find them. Your stored `id` is the reference.

### Step 3: create the subscription

You need the **customer id** from step 2 and the **plan id** from step 1.

```php
$checkout = $aslan->subscriptions->create([
    'planId'      => $planId,
    'customerId'  => $customerId,
    'returnUrl'   => 'https://shop.example/billing/return',   // customer comes back here
    'cancelUrl'   => 'https://shop.example/billing/cancelled',
    'callbackUrl' => 'https://shop.example/billing/callback', // required, but ignored by the API
    'metadata'    => ['invoice_id' => 'INV-42'],
]);

// SAVE THIS NOW, before sending the customer away:
saveSubscriptionIdForCustomer($customerId, $checkout->subscriptionId);
```

`create()` returns a `SubscriptionCheckout` with: `subscriptionId`, `transactionId` (the initial charge; reported later by `payment.succeeded`), `redirectUrl` (the Paywall URL), and `paywall` (an array `['payload' => ..., 'signature' => ...]`).

Optional params: `priceOverride` (int centimes, between 1 and the plan amount), `priceOverrideMonths` (int, >= 1, requires `priceOverride`; counted in calendar months from creation, also for a plan with a trial; omit for a permanent override), `metadata` (string map; keys starting with `__` are reserved and dropped). For a plan **without** a trial the metadata is also copied onto the initial transaction, so it appears in `payment.succeeded`; for a plan **with** a trial it is not (see step 4). All three URLs (`returnUrl`, `cancelUrl`, `callbackUrl`) are required and must start with `https://`. The API ignores `callbackUrl`: payment-provider notifications always go to Aslan, and you hear about the result through your webhook.

### Step 4: send the customer to the Paywall (form POST, not a redirect)

The Paywall expects the browser to **POST** the signed `payload` and `signature` to `redirectUrl`. A plain `header('Location: ...')` redirect is a GET and **will not work**. The SDK builds the right page for you:

```php
header('Content-Type: text/html; charset=utf-8');
echo $checkout->autoSubmitFormHtml();   // a tiny page that auto-submits the form
exit;
```

**What happens:** the customer lands on the Paywall, saves a card, completes 3-D Secure, pays, and is sent back to your `returnUrl`. The Paywall link expires about 30 minutes after it is issued; use `repay()` to get a fresh one.

What the Paywall charges depends on the plan:

- **No trial** (`trialDays` = 0): the first period's price (the plan amount, or `priceOverride`). On success the subscription becomes `active` and its first period starts now.
- **With a trial** (`trialDays` > 0): only a small **card-verification amount** (100 centimes = 1.00 MAD by default, configurable on the API side), used to save the card with 3-D Secure. It is refunded automatically once the payment is confirmed. On success the subscription becomes `trialing` until `trialEndsAt` (now + `trialDays`). The **first real charge** (the plan amount, or the override if it still applies) is made automatically at `trialEndsAt` and reported with `subscription.renewed`; if it fails, the subscription goes straight to `past_due` and the normal retry schedule applies (step 7).

Subscriptions with a trial created before this verification charge existed paid the full price at signup; at trial end they become `active` **without** a second charge (and without a webhook); their next renewal is charged as usual.

### Step 5: wait for the webhook, then activate

The subscription stays `pending_initial` until the first payment succeeds. Do not grant access when the customer returns to `returnUrl`; wait for the verified `payment.succeeded` webhook (its `data.transaction.id` matches `$checkout->transactionId`; after a `repay()` it is a new transaction id, so also match on the `metadata` you passed, which is copied to the transaction for plans without a trial), then re-read the state if you need it.

For a plan with a trial, that `payment.succeeded` is the verification charge: `data.transaction.amount` is the verification amount, and `data.transaction.metadata` holds only internal markers (`__subscriptionId`, `__payzoneRecurrenceType`, `__trialVerification: true`), not your metadata. It is followed by `refund.*` events for the automatic refund. Do not book it as revenue.

```php
$sub = $aslan->subscriptions->retrieve($subscriptionId);   // Subscription
if ($sub->isActive() || $sub->status === 'trialing') {
    grantAccess($sub->customerId);
}
```

`Subscription` properties: `id`, `customerId`, `customer` (`?SubscriptionCustomer`: `id`, `email`, `name`, `phone`), `planId`, `plan` (`?Plan`), `providerId`, `status`, `failureCount`, `currentPeriodStart`, `currentPeriodEnd`, `nextBillingAt`, `trialEndsAt`, `cancelAtPeriodEnd`, `cancelledAt`, `priceOverride`, `priceOverrideUntil`, `metadata`, `card` (`?SubscriptionCard`: `brand`, `last4`, `expiryMonth`, `expiryYear`), `createdAt`, `updatedAt`. `customer` and `card` are only filled by `retrieve()`; `list()` items include `plan` but not those two. `isActive()` is true for `active` **and** `trialing`.

If the customer abandoned the Paywall and the subscription is still `pending_initial`, mint a fresh link (see "Change card / repay" below).

### Step 6: renewals are automatic

Once `nextBillingAt` has passed, Aslan charges the saved card for the next period (the API checks for due subscriptions every 5 minutes; the new period starts where the previous one ended). You do nothing. The amount is the plan amount, or `priceOverride` while it applies (see "Price override").

On success you receive `subscription.renewed`, whose `$event->data` holds `subscription` (`id`, `planId`, `currentPeriodStart`, `currentPeriodEnd`, `nextBillingAt`, `metadata`: the subscription's own metadata without internal `__` keys) and `transaction` (`id`, `amount` in centimes, `currency`, `providerRef`); use it to extend access and to create your own invoice. The same event reports the first real charge at the end of a trial. The renewal flow sends `subscription.renewed`, not `payment.succeeded`; if you also handle `payment.succeeded`, key it on the transaction id so a subscription charge is never mistaken for a one-off order. Use `currentPeriodEnd` / `nextBillingAt` for "next payment on ..." messages.

**Billing day.** Monthly, quarterly and yearly plans renew on a fixed day of the month, the *billing anchor*: the UTC day-of-month on which the paid periods started (activation, trial end, `resume()` or an immediate plan change sets a new anchor). In shorter months the date is clamped to the month's last day and then returns to the anchor, so a subscription anchored on the 31st renews Jan 31, Feb 28 (29), Mar 31, Apr 30. Daily and weekly plans have no anchor. All dates are computed in UTC.

**Catch-up.** If a renewal is charged so late that the period it would pay for is already over (for example a dunning retry that succeeds long after the due date), the period is re-anchored on the moment of the charge: one charge, and the next renewal is one interval later. Missed periods are never billed back-to-back.

### Step 7: dunning (failed renewals)

When a renewal charge fails Aslan retries it on a fixed schedule ("dunning"). Every failed charge sends `subscription.payment_failed` (with `subscription.failureCount` and the failed `transaction`, including `failureReason`):

| Consecutive failure | Status afterwards | Next retry | Extra webhook |
|---|---|---|---|
| 1st | unchanged (`active`) | 2 days later | none |
| 2nd | unchanged | 5 days later | none |
| 3rd | `past_due` | 7 days later | `subscription.past_due` |
| 4th | `cancelled` (reason `dunning`) | none | `subscription.cancelled` |

Exception: when the **trial-end** charge fails, the subscription goes to `past_due` at the 1st failure (with `subscription.past_due`, sent only once) and follows the same 2 / 5 / 7-day retries, then cancellation at the 4th failure.

A retry that succeeds sends `subscription.renewed`, sets the status back to `active` and resets `failureCount` to 0. Tell the customer to update their card (`changeCard()`) as soon as the first `subscription.payment_failed` arrives: the new card is used by the next scheduled retry (changing the card does not trigger an immediate charge). If the payment provider answers without a definitive outcome, the attempt stays `pending`, no webhook is sent and the next try is pushed back 24 hours.

`Subscription::$failureCount` counts consecutive failures. Each charge is a **billing attempt**, listed with `$aslan->billingAttempts->list(['subscriptionId' => $id])`.

### Change card / repay

Both return a `SubscriptionPaymentLink` (`subscriptionId`, `paymentLinkId`, `redirectUrl`) and use a normal **GET redirect** (unlike the Paywall):

```php
$link = $aslan->subscriptions->changeCard($subscriptionId, [
    'returnUrl'   => 'https://shop.example/billing/return',
    'cancelUrl'   => 'https://shop.example/billing/cancelled',
    'callbackUrl' => 'https://shop.example/billing/callback',   // required, ignored by the API
]);
header('Location: ' . $link->redirectUrl);
```

- `changeCard()` re-collects the card with 3-D Secure. A small verification amount (1.00 MAD by default) is charged and then refunded automatically. It only swaps the card on file: status and `failureCount` are unchanged, and the next scheduled renewal or dunning retry uses the new card. Rejected (400) on a `cancelled` subscription.
- `repay()` is for a subscription still in `pending_initial` whose first payment was never completed (API returns 400 for any other status). It charges what `create()` would: the first period's price, or the refunded verification amount for a plan with a trial. If the subscription is no longer `pending_initial` when the customer opens the link, the link is refused.
- Both links are single-use and valid for 7 days by default (set by the API). All three URLs must be `https://`; `callbackUrl` is required but ignored by the API. They show up in `paymentLinks->list()` with `purpose` `change_card` / `repay`.
- The verification charge of `changeCard()` also produces a `payment.succeeded` webhook (with `data.transaction.metadata.__changeCard` = `true`) and `refund.*` webhooks for its refund. It is not a sale.

### Change plan

```php
$change = $aslan->subscriptions->changePlan(
    $subscriptionId,
    $newPlanId,
    when: 'immediate',          // immediate | period_end | immediate_proration
    allowDowngrade: false,      // must be true to move to a cheaper plan (403 otherwise)
);
// SubscriptionPlanChange: subscriptionId, planId, previousPlanId, when,
// proratedTransactionId, proratedAmount, proratedCurrency
```

Only an `active` or `trialing` subscription can change plan (400 otherwise). The target plan must be active. The card on file is reused. `allowDowngrade` is needed when the target plan's amount is lower than the current plan's (a subscription whose `metadata.allowDowngrade` is `"true"` is also allowed).

| `when` | What happens |
|---|---|
| `immediate` | The new plan applies now; a new period starts now (next renewal one new-plan interval from now, new billing anchor), any price override and any scheduled period-end cancellation are cleared. Nothing is charged now. |
| `immediate_proration` | The new plan applies now and the renewal date is kept; any price override is cleared. For an upgrade, the difference between the new plan amount and what the current period is billed (plan amount or override), times the unused fraction of the period, rounded **up** to a whole dirham, is charged immediately (`proratedTransactionId`, `proratedAmount`). A downgrade charges nothing and credits nothing. |
| `period_end` | Nothing changes now; the new plan is applied by the next renewal, which charges the new plan's full amount (any price override is dropped then). |

During a **trial** nothing has been charged yet, so `immediate` and `immediate_proration` just switch the plan: no proration charge, the trial end stays the same (the first charge at trial end uses the new plan), and a scheduled period-end cancellation is kept.

`subscription.plan_changed` is sent as soon as the API accepts the change, for all three modes. Its `data.subscription` holds `id`, `planId`, `previousPlanId`, `effectiveAt` and, for a proration, `proratedTransactionId`. `effectiveAt` is the time of the change for `immediate` / `immediate_proration`, and the current `currentPeriodEnd` for `period_end` (null only if the subscription has no current period).

If the proration charge fails, the plan is **not** changed, no webhook is sent, and `changePlan()` throws `AslanException` (HTTP 400, `VALIDATION_ERROR`, message "Proration charge failed; plan not changed"). The proration is recorded as a billing attempt of type `proration`.

`subscription.plan_change_failed` is only about `period_end` changes: it is sent at renewal time when the scheduled target plan has been archived (or no longer exists). Its `data.subscription` holds `id` and `pendingPlanId`; the pending change is dropped and the subscription renews on its current plan.

### Price override

```php
$aslan->subscriptions->update($subscriptionId, ['priceOverride' => 7900, 'priceOverrideMonths' => 3]); // returns SubscriptionPriceOverride
$aslan->subscriptions->update($subscriptionId, ['priceOverride' => null]);                                // clear it
```

It takes effect at the **next renewal** (nothing is charged now), must not exceed the plan amount, and is blocked (400) on cancelled subscriptions. A plan change clears it. `priceOverrideMonths` counts calendar months from the moment of the call (it requires a non-null `priceOverride`); without it the override is permanent. The rule: the override applies to every period that **starts on or before** `priceOverrideUntil`; the first renewal whose period starts later is charged the plan amount, and the override is then cleared. The charged amount never exceeds the current plan amount.

### Cancel, uncancel, pause, resume

```php
$aslan->subscriptions->cancel($id);                       // cancel NOW
$aslan->subscriptions->cancel($id, atPeriodEnd: true);    // keep access until the period ends, then stop
$aslan->subscriptions->uncancel($id);                     // reverse a scheduled period-end cancellation
$aslan->subscriptions->pause($id);
$aslan->subscriptions->resume($id);
```

All of them return a `SubscriptionStatusChange` (`subscriptionId`, `status`, `cancelAtPeriodEnd`):

- `cancel($id)` cancels immediately (response status `cancelled`). **No webhook is sent**: update your own records right away.
- `cancel($id, atPeriodEnd: true)` leaves the status unchanged (`active`, `trialing`, `past_due` or `paused`) with `cancelAtPeriodEnd === true`. Once `currentPeriodEnd` has passed the subscription is cancelled instead of renewed and `subscription.cancelled` is sent with `cancelReason: "period_end"`. For a trial, `currentPeriodEnd` is the trial end, so it ends there without any charge.
- `uncancel($id)` clears a scheduled period-end cancellation.
- `pause($id)` only affects an `active` subscription; a paused subscription is not billed. `resume($id)` only affects a `paused` one: it starts a fresh billing period from now (new billing anchor) without charging; the next charge is at the end of that period. The response echoes `paused` / `resumed` even when nothing changed (`resumed` is not a real subscription status).

`subscription.cancelled` is sent only for cancellations the API makes on its own: `cancelReason` `period_end`, `dunning` (4th failed renewal) or `abandoned` (`pending_initial` unpaid after about 24 hours). Immediate `cancel()`, `pause()`, `resume()` and `uncancel()` send no webhook. Re-read with `retrieve()` if you need the full state.

### Receipts and invoices

```php
$attempts = $aslan->billingAttempts->list(['subscriptionId' => $id, 'status' => 'succeeded']);
foreach ($attempts->data as $attempt) {            // BillingAttempt
    $doc   = $aslan->billingAttempts->receipt($attempt->id);       // DocumentData
    $bytes = $aslan->billingAttempts->receiptPdf($attempt->id);    // raw PDF string
    file_put_contents("receipt-{$attempt->id}.pdf", $bytes);
}
$invoice = $aslan->billingAttempts->facture($attempt->id);         // Invoice (facture mode only, idempotent)
$pdf     = $aslan->invoices->pdf($invoice->id);                    // raw PDF string
```

Receipts and invoices exist only for `succeeded` attempts, and never for the refunded verification charge of a trial signup (the API answers 400 for those).

---

## 7. Webhooks in depth

### Why webhooks

Aslan calls **your** server when something happens. Because anyone could call your URL pretending to be Aslan, every delivery is **signed**. You verify the signature before trusting anything in it.

### Setup

Register one endpoint per merchant (also possible from the dashboard). The URL must start with `https://` and must not point to a private or internal address. The signing secret (`whsec_...`) is returned in full **only the first time** a URL is configured; store it as `ASLAN_WEBHOOK_SECRET`. Calling `create()` again only changes the URL and keeps the same secret.

```php
$config = $aslan->webhooksConfig->create([
    'url' => 'https://shop.example/webhooks/aslan',
]);
echo $config->signingSecret;   // save it now; null on later calls (the API then returns it masked)
```

About `events`: if you pass it, it must be a non-empty list taken from `payment.succeeded`, `payment.failed`, `payment.expired`, `refund.created`, `refund.updated` and the six `subscription.*` events (anything else, including the cash events, is rejected with a 400). The API currently **does not store** this list: every event is delivered to your URL whatever you pass, and `retrieve()` always reports the full list. Filter by `$event->type` in your handler.

### The signature

Each delivery carries the header:

```
X-Webhook-Signature: t=1761127200,v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd
```

- `t` is a Unix timestamp in seconds.
- `v1` is `HMAC-SHA256(secret, "<t>.<rawBody>")` as hex.
- The event name is in the JSON body's `event` field.
- Default tolerance is **300 seconds**: events whose `t` is further from now are rejected (replay protection).

### Verify against the RAW body

This is the number one cause of "signature mismatch". The signature covers the **exact bytes** Aslan sent. If you `json_decode()` and `json_encode()` again, spacing/key order/escaping change and the signature no longer matches. Read the raw body, verify it, then parse.

```php
use Aslan\Payment\Aslan;
use Aslan\Payment\AslanException;

$rawBody   = file_get_contents('php://input');                     // RAW, untouched
$sigHeader = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

try {
    $event = Aslan::webhooks(getenv('ASLAN_WEBHOOK_SECRET'))
        ->constructEvent($rawBody, $sigHeader);                    // default tolerance 300 s
} catch (AslanException $e) {                                      // errorCode WEBHOOK_SIGNATURE_ERROR
    http_response_code(400);
    exit;
}
```

In Symfony use `$request->getContent()` and `$request->headers->get('X-Webhook-Signature', '')`. In any framework, make sure no middleware consumed or rewrote the body first.

`constructEvent(string $rawBody, string $sigHeader, int $tolerance = 300): WebhookEvent`. Pass `0` as tolerance to disable the age check (tests only).

`WebhookEvent` properties: `id` (unique event id), `type` (the `event` field), `apiVersion` (always empty: the API sends none), `createdAt` (unix int), `data` (array), `rawPayload` (array). Helpers: `isSubscriptionEvent()`, `asSubscription()`, `asTransaction()`, `asRefund()`, `asCheckoutSession()`, `asPaymentLink()`. For `subscription.*` events `$event->data` holds `subscription` and sometimes `transaction`; `asSubscription()` is *partial* (fields absent from the payload are null, for example `status`), so call `$aslan->subscriptions->retrieve($event->asSubscription()->id)` when you need the full state.

### Delivery and retries

Each event is POSTed as JSON with the `X-Webhook-Signature` header. A delivery counts as successful only when your endpoint answers **2xx within 10 seconds** (redirects are not followed). Otherwise Aslan retries: **5 attempts in total**, the retries coming about 10 s, 30 s, 90 s and 270 s after the previous failure (each delay plus a random 0-50 %, and longer if your URL keeps failing). After the 5th failed attempt the event is not retried again.

### Handle events idempotently

Deliveries can arrive **more than once** (a retry after a timeout on your side, for example) and not always in order. Therefore:

1. Store each processed `$event->id` (unique column) and skip an id you have seen.
2. Make the action itself safe to repeat ("set order paid" is fine, "add 100 credits" is not, unless guarded by the id).
3. Respond quickly with a 2xx; do heavy work in a queue. Any non-2xx or timeout should be treated as "Aslan may deliver this again".

```php
function handle(\Aslan\Payment\Dto\WebhookEvent $event, \PDO $db): void
{
    $inserted = $db->prepare('INSERT INTO processed_events (id) VALUES (?) ON CONFLICT DO NOTHING');
    $inserted->execute([$event->id]);
    if ($inserted->rowCount() === 0) {
        return;                                   // duplicate delivery: already handled
    }

    switch ($event->type) {
        case 'payment.succeeded':
            markOrderPaid($event->asTransaction());
            break;
        case 'subscription.renewed':
            extendAccess($event->asSubscription());
            break;
        case 'subscription.payment_failed':
        case 'subscription.past_due':
            notifyCustomerToFixCard($event->asSubscription());
            break;
        case 'subscription.cancelled':
            revokeAccess($event->asSubscription());
            break;
        default:
            // unknown or unhandled event: ignore and still return 2xx
    }
}
```

(In production, wrap the insert and the business action in one transaction so a crash in between does not lose the event.)

### Event table

| Event | Sent when | What to do |
|---|---|---|
| `payment.succeeded` | A payment succeeded: checkout session, payment link, a confirmed cash payment, or a subscription's **first** payment (renewals send `subscription.renewed` instead). Also sent for the refunded card-verification charges of a trial signup (`data.transaction.metadata.__trialVerification` = `true`) and of `changeCard()` (`__changeCard` = `true`): those are not sales | Mark the order paid, activate what was bought |
| `payment.failed` | A payment attempt failed | Tell the customer, let them retry |
| `payment.expired` | A pending payment expired unpaid (for cash: the payment code expired, `data.transaction.reason` = `cash_code_expired`) | Release reserved stock, cancel the pending order |
| `payment.pending_cash` | The customer chose to pay **cash** at checkout and received a payment code: `data.transaction` holds `id`, `referenceCode`, `networkName` (where to pay) and `expiresAt` (48 h by default) | Keep the order "awaiting payment"; do not ship yet |
| `payment.cash_late_confirmation` | The cash network confirmed a payment **after** the code's expiry plus the grace period (2 h by default). The transaction is set to status `cash_late_confirmation`, not `succeeded`, and no `payment.succeeded` is sent | The customer did pay: reconcile manually (fulfil the order or refund) |
| `refund.created` | A refund was created | Record it in your books |
| `refund.updated` | A refund changed status (`pending`, `approved`, `processing`, `succeeded`, `failed`, `rejected`) | Update your records; use `asRefund()` |
| `subscription.renewed` | A renewal charge succeeded (including a successful dunning retry and the first charge at the end of a trial); carries the subscription's `metadata` | Extend access, issue your invoice |
| `subscription.payment_failed` | A renewal charge failed (sent for every failure, see the dunning table) | Ask the customer to update their card |
| `subscription.past_due` | The 3rd consecutive renewal failed, or the trial-end charge failed; the subscription is now `past_due` (sent once per entry into `past_due`) | Apply your grace policy |
| `subscription.cancelled` | The API ended the subscription: `data.subscription.cancelReason` is `period_end`, `dunning` or `abandoned`. **Not** sent for an immediate `cancel()` | Revoke access |
| `subscription.plan_changed` | A `changePlan()` call was accepted (immediately, for all modes; `effectiveAt` tells when it applies) | Update the entitlements of the customer |
| `subscription.plan_change_failed` | A scheduled `period_end` plan change could not be applied because the target plan was archived; the subscription renewed on its current plan | Tell the customer, pick another plan |

Payloads are `{"id", "event", "createdAt", "data"}`. All amounts are integers in centimes, `payment.*` included. `payment.succeeded` / `payment.failed` carry `data.transaction` with `id`, `amount`, `currency`, `status`, `providerRef`, `metadata`, `paidAt` or `failedAt` (plus `vendorId` / split fields for marketplace payments). `payment.*` events carry `data.transaction`, `refund.*` events carry `data.refund`, `subscription.*` events carry `data.subscription` (plus `data.transaction` for `renewed` / `payment_failed`). `asTransaction()` reads `data.transaction`, `asRefund()` reads `data.refund`, `asSubscription()` reads `data.subscription` (each falls back to `data.object`, then `data`). Always rely on `$event->data` / `$event->rawPayload` when in doubt. Event names such as `checkout_session.completed` or `transaction.captured` (used in 0.1.0-era docs) **do not exist**; do not subscribe to or switch on them.

### Testing a webhook handler

Sign a payload yourself with the same secret:

```php
$webhooks = Aslan::webhooks('whsec_test');
$body     = json_encode(['id' => 'evt_1', 'event' => 'payment.succeeded', 'createdAt' => gmdate('c'), 'data' => ['transaction' => ['id' => 'txn_1', 'amount' => 14900, 'currency' => 'MAD']]]);
$header   = $webhooks->generateSignatureHeader($body);       // "t=...,v1=..."
$event    = $webhooks->constructEvent($body, $header);       // passes
```

---

## 8. Errors and retries

Every failure raises `Aslan\Payment\AslanException` (extends `\RuntimeException`):

```php
try {
    $aslan->refunds->create(['transactionId' => $id, 'amount' => 999999]);
} catch (\Aslan\Payment\AslanException $e) {
    $e->getErrorCode();   // string API code, e.g. "VALIDATION_ERROR" (same as $e->errorCode)
    $e->getCode();        // int HTTP status (0 when there was no HTTP response)
    $e->httpStatus;       // same as getCode()
    $e->getMessage();     // human-readable message
    $e->details;          // array, validation details when present
    $e->requestId;        // ?string, quote it to Aslan support
}
```

| `errorCode` | HTTP | Meaning | What to do |
|---|---|---|---|
| `VALIDATION_ERROR` | 400 | Invalid parameters or an action not allowed in the current state (e.g. `repay()` on an active subscription, a failed proration). In production `$e->details` lists `[{field, message}]` for field errors | Read the message and `$e->details`, fix, do not retry as is |
| `AUTHENTICATION_ERROR` | 401 (or 0 for a bad key format) | Missing/invalid key | Check the key and its prefix |
| `AUTHORIZATION_ERROR` | 403 | Not allowed (e.g. downgrade without `allowDowngrade`) | Fix permissions or parameters |
| `NOT_FOUND` | 404 | Unknown id, or an inactive plan | Check the id and the mode (test vs live) |
| `CONFLICT` | 409 | The resource conflicts with an existing one (e.g. a customer with this email already exists), a request with the same idempotency key is still running, or an expired payment link was updated | Use the existing resource; for an in-flight idempotent request, retry later with the same key |
| `RATE_LIMIT_ERROR` | 429 | Too many requests (the API's own code is `RATE_LIMIT_EXCEEDED`; the SDK reports every 429 as `RATE_LIMIT_ERROR`) | Back off |
| `PSP_ERROR` | 502 | The payment provider returned an error | Retry later |
| `INTERNAL_ERROR` | 500 | Unexpected server error | Retry later |
| `SERVER_ERROR` | 5xx | Server error without a readable error body | Retry later |
| `NETWORK_ERROR` | 0 | Connection or timeout failure | Retry with the same idempotency key |
| `WEBHOOK_SIGNATURE_ERROR` | 0 | Webhook verification failed | Reject with 400 |
| `UNSUPPORTED_OPERATION` | 0 | Custom HTTP client cannot download PDFs | Implement `RawHttpClientInterface` |

The API answers errors as `{"error": {"code", "message", "details"}}`; the SDK unwraps that envelope. For 401, 403, 404, 409, 422 and 429 the SDK sets `errorCode` from the HTTP status as shown above; for any other status (including 400 and 5xx) it uses the API's own `code` unchanged, so never assume the list above is exhaustive.

**Automatic retries.** The bundled client retries up to `maxRetries` times (default 2) with exponential back-off (500 ms, 1 s, ... capped at 5 s) on HTTP 408, 429, 500, 502, 503, 504 and on network errors, for every method including `POST`. Because a retried create might already have succeeded, pass an `idempotencyKey` to the create calls the API deduplicates (checkout sessions, payment links, refunds). For other creates (subscriptions, customers, plans, ...) a retry after a lost response can create a duplicate; if that matters, set `maxRetries: 0` on the client you use for them and reconcile yourself.

---

## 9. Testing your integration

### Use test keys

Use an `sk_test_...` key in development and CI. Nothing moves real money, and `$aslan->isTestMode()` returns `true`.

### Fake the HTTP layer (no network)

Inject a Symfony `MockHttpClient` through the SDK's own adapter. This is exactly how the SDK's own tests exercise the full error path:

```php
use Aslan\Payment\Aslan;
use Aslan\Payment\HttpClient\SymfonyHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$mock = new MockHttpClient([
    new MockResponse(
        json_encode(['data' => ['id' => 'plan-1', 'name' => 'Pro', 'amount' => 9900, 'currency' => 'MAD',
                                'interval' => 'monthly', 'isActive' => true]]),
        ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']],
    ),
]);

$aslan = new Aslan(
    'sk_test_prefix_secret',
    httpClient: new SymfonyHttpClient('sk_test_prefix_secret', maxRetries: 0, client: $mock),
);

$plan = $aslan->plans->create(['name' => 'Pro', 'amount' => 9900, 'interval' => 'monthly']);
assert($plan->amount === 9900);
```

Responses must declare `content-type: application/json`, otherwise the adapter returns an empty array.

Or implement your own double of `Aslan\Payment\HttpClient\HttpClientInterface` (one method, `request(string $method, string $url, array $body = [], array $headers = [], array $query = []): array`); add `RawHttpClientInterface` if the code under test downloads PDFs.

### Test webhooks

Use `Aslan::webhooks($secret)->generateSignatureHeader($rawBody, ?int $timestamp = null)` to produce a valid header (see section 7).

### Run the SDK's own test suite

```bash
composer install
vendor/bin/phpunit
```

---

## 10. API reference

All methods throw `AslanException` on failure. `list()` methods return `PaginatedResponse` (`data` array of DTOs, `page`, `pageSize`, `total`, `totalPages`, `hasNextPage()`, `hasPreviousPage()`); query params common to all lists: `page` (default 1) and `pageSize` (default 20, max 100).

### `$aslan->checkoutSessions` (`CheckoutSessions`)

| Method | Returns | Notes |
|---|---|---|
| `create(array $params, ?string $idempotencyKey = null)` | `CheckoutSession` | `successUrl`, `cancelUrl` required (`https://`); `amount` (centimes, 100 to 100 000 000; omit or null for an open amount), `currency` (MAD only, default), optional `customer` (`email`, `name`, `phone`), `lineItems`, `billingAddress`, `shippingAddress`, `paymentMethods` (`card`, `cash`; default `card`), `vendorId`, `require3ds` (default true), `metadata`. Expires after 30 minutes (48 h with cash) |
| `retrieve(string $id)` | `CheckoutSession` | adds `successUrl`, `cancelUrl`, `metadata`, `transactionId`, `transactionStatus` |

### `$aslan->transactions` (`Transactions`, read-only)

| Method | Returns | Notes |
|---|---|---|
| `list(array $query = [])` | `PaginatedResponse<Transaction>` | filters `status` (pending, processing, succeeded, failed, expired, refunded, partially_refunded), `from`, `to` (dates); `sortBy` (createdAt, amount, status), `sortOrder` (asc, desc) |
| `retrieve(string $id)` | `Transaction` | includes the refunds |

`Transaction`: `id`, `amount`, `currency`, `status`, `providerRef`, `paidAt`, `failedAt`, `errorMessage`, `checkoutSessionId`, `refunds` (raw arrays), `refundedAmount` (sum of refunds not `failed` / `rejected`), `metadata`, `createdAt`, `updatedAt`; helpers `isSuccessful()` (`succeeded`), `isRefundable()` (`succeeded` or `partially_refunded` with an amount left), `refundableAmount()`. Status values: `pending`, `pending_cash`, `cash_late_confirmation`, `processing`, `succeeded`, `failed`, `expired`, `refunded`, `partially_refunded`. `list()` items carry no refunds, `checkoutSessionId`, `failedAt` or `updatedAt`; use `retrieve()` before deciding on a refund. Deprecated, always null: `paymentMethod`, `cardLast4`, `cardBrand`, `reference`, `description`, `paymentLinkId`.

### `$aslan->paymentLinks` (`PaymentLinks`)

| Method | Returns | Notes |
|---|---|---|
| `create(array $params, ?string $idempotencyKey = null)` | `PaymentLink` | all optional: `amount` (centimes; omit for an open amount), `minAmount`, `maxAmount`, `currency` (MAD), `description` (max 500), `maxPayments` (limit on payment attempts), `vendorId`, `expiresAt`, `metadata` |
| `list(array $query = [])` | `PaginatedResponse<PaymentLink>` | filter `status`: active, inactive, expired, finalized (paid) |
| `retrieve(string $id)` | `PaymentLink` | |
| `update(string $id, array $params)` | `PaymentLink` | only `description`, `status` (active, inactive), `maxPayments`, `expiresAt`, `metadata`; an expired link cannot be updated (409) |
| `delete(string $id)` | `bool` | soft delete (status becomes `inactive`) |
| `getQrCode(string $id)` | `array` | `dataUri` (base64 PNG data URI), `mimeType` |

`PaymentLink`: `id`, `url`, `shortCode`, `status`, `purpose` (`payment`, or `change_card` / `repay` for links minted by subscriptions), `amount`, `minAmount`, `maxAmount`, `currency`, `openAmount` (`amount === null`), `description`, `maxPayments`, `paymentCount` (payment attempts started from the link), `vendorId`, `expiresAt`, `metadata`, `createdAt`, `updatedAt`; helpers `isActive()`, `isFinalized()` (paid), `hasReachedMaxUses()` (`paymentCount >= maxPayments`). Deprecated aliases: `title` (= `description`), `slug` (= `shortCode`), `maxUses` (= `maxPayments`), `usageCount` (= `paymentCount`); `successUrl` / `cancelUrl` are always null.

### `$aslan->refunds` (`Refunds`)

| Method | Returns | Notes |
|---|---|---|
| `create(array $params, ?string $idempotencyKey = null)` | `Refund` | `transactionId` (uuid) and `amount` (centimes) required; optional `reason` (free text, max 500) |
| `list(array $query = [])` | `PaginatedResponse<Refund>` | filters `transactionId`, `status` (pending, approved, processing, succeeded, failed, rejected) |
| `retrieve(string $id)` | `Refund` | |

`Refund`: `id`, `transactionId`, `amount`, `currency`, `status`, `reason`, `note`, `metadata`, `createdAt`, `updatedAt`; helpers `isSucceeded()`, `isPending()`, `isFailed()`.

### `$aslan->vendors` (`Vendors`, marketplace sub-merchants)

| Method | Returns | Notes |
|---|---|---|
| `create(array $params, ?string $idempotencyKey = null)` | `Vendor` | `name` required; optional `externalId`, `email`, `commissionRate` (0 to 1), `metadata`. Only for a merchant configured as a marketplace (400 otherwise). The idempotency key is ignored by the API |
| `list(array $query = [])` | `PaginatedResponse<Vendor>` | `page`, `limit` (not `pageSize`; max 100), filters `search`, `isActive` (`"true"` / `"false"`) |
| `retrieve(string $id)` | `Vendor` | |
| `update(string $id, array $params)` | `Vendor` | `name`, `email`, `commissionRate`, `externalId`, `isActive`, `metadata` |
| `delete(string $id)` | `bool` | deactivates the vendor (`isActive: false`) |

`Vendor`: `id`, `name`, `externalId`, `email`, `commissionRate` (`?float`), `status` (`active` / `inactive`, from the API's `isActive`), `metadata`, `createdAt`, `updatedAt`; helper `isActive()`. Deprecated: `code` (= `externalId`), and `phone`, `ice`, `iban`, `settledAmount`, `pendingAmount`, which the API never returns.

### `$aslan->customers` (`Customers`)

| Method | Returns | Notes |
|---|---|---|
| `create(array $params)` | `Customer` | at least one of `email` / `phone` required; `email` unique per merchant (duplicate: HTTP 409, `CONFLICT`); `phone` `+212XXXXXXXXX`; optional `name` (max 200), `metadata` (string map). No idempotency key (not supported by the API) |
| `list(array $query = [])` | `PaginatedResponse<Customer>` | `search` (matched against the stored columns; encrypted emails and names are not found), `sortBy` (createdAt, email, name), `sortOrder` (asc, desc) |
| `retrieve(string $id)` | `Customer` | includes the transaction totals |

`Customer`: `id`, `email`, `phone`, `name`, `metadata` (internal keys such as `encryptedEmail` removed), `totalSpend` (sum of all the customer's transaction amounts, any status), `transactionCount` (all transactions), `successfulTransactionCount`, `createdAt`, `updatedAt`; helper `displayName()`. The totals come from `retrieve()` only (0 on `create()` / `list()`). Deprecated, always null: `firstName`, `lastName`, `country`.

### `$aslan->apiKeys` (`ApiKeys`)

| Method | Returns | Notes |
|---|---|---|
| `create(array $params, ?string $idempotencyKey = null)` | `ApiKey` | `name` and `type` (`live` or `test`) required. `secretKey` is returned **only here**. The idempotency key is ignored by the API |
| `list(array $query = [])` | `PaginatedResponse<ApiKey>` | returns all keys at once (no pagination or filters) |
| `delete(string $id)` | `bool` | revokes permanently |

`ApiKey`: `id`, `name`, `type` (`live` / `test`), `prefix`, `secretKey` (`?string`, `create()` only), `publishableKey`, `status` (`active` / `revoked`, from the API's `isActive`; `create()` reports `active`), `lastUsedAt`, `revokedAt`, `createdAt`; helpers `isActive()`, `isLive()`, `isTest()`. `environment` is a deprecated alias of `type`.

### `$aslan->webhooksConfig` (`WebhooksConfig`)

| Method | Returns | Notes |
|---|---|---|
| `create(array $params, ?string $idempotencyKey = null)` | `WebhookConfig` | sets the merchant's single endpoint: `url` required (`https://`, public address); optional `events` (non-empty, see section 7; not stored by the API). The idempotency key is ignored by the API |
| `retrieve()` | `WebhookConfig` | `url` is empty when none is configured |

`WebhookConfig`: `id`, `url`, `status`, `events`, `signingSecret` (`?string`, the full secret only on the first configuration, otherwise null), `apiVersion`, `createdAt`, `updatedAt`; helpers `isActive()`, `subscribesTo(string $eventType)`. The API returns only `url`, the secret and `events`; the other properties hold defaults.

### `Aslan::webhooks(string $secret)` (`Resources\Webhooks`)

| Method | Returns |
|---|---|
| `constructEvent(string $rawBody, string $sigHeader, int $tolerance = 300)` | `WebhookEvent` |
| `generateSignatureHeader(string $payload, ?int $timestamp = null)` | `string` (`t=...,v1=...`) |
| constants `SIGNATURE_HEADER` (`X-Webhook-Signature`), `DEFAULT_TOLERANCE` (300) | |

### `$aslan->plans` (`Plans`)

| Method | Returns | Notes |
|---|---|---|
| `create(array $params)` | `Plan` | `name`, `amount` (centimes, >= 1), `currency` (default MAD), `interval`; optional `intervalCount` (1-12), `trialDays` (>= 0), `metadata` |
| `list(array $query = [])` | `PaginatedResponse<Plan>` | filter `isActive` (bool, serialised for you) |
| `retrieve(string $id)` | `Plan` | |
| `update(string $id, array $params)` | `Plan` | only `name`, `isActive`, `metadata` |

### `$aslan->subscriptions` (`Subscriptions`)

| Method | Returns | Notes |
|---|---|---|
| `create(array $params)` | `SubscriptionCheckout` | `planId`, `customerId`, `returnUrl`, `cancelUrl`, `callbackUrl` required (URLs `https://`; `callbackUrl` ignored); optional `priceOverride`, `priceOverrideMonths`, `metadata` |
| `list(array $query = [])` | `PaginatedResponse<Subscription>` | filters `status`, `customerId`, `planId` |
| `retrieve(string $id)` | `Subscription` | includes plan, customer, card |
| `update(string $id, array $params)` | `SubscriptionPriceOverride` | `priceOverride`, `priceOverrideMonths`; props `id`, `priceOverride`, `priceOverrideUntil` |
| `changePlan(string $id, string $planId, string $when = 'immediate', bool $allowDowngrade = false)` | `SubscriptionPlanChange` | `when`: immediate, period_end, immediate_proration; only `active` / `trialing` |
| `cancel(string $id, bool $atPeriodEnd = false)` | `SubscriptionStatusChange` | immediate cancel sends no webhook |
| `uncancel(string $id)` | `SubscriptionStatusChange` | |
| `pause(string $id)` | `SubscriptionStatusChange` | `active` only |
| `resume(string $id)` | `SubscriptionStatusChange` | `paused` only; response status is `resumed` |
| `changeCard(string $id, array $params)` | `SubscriptionPaymentLink` | `returnUrl`, `cancelUrl`, `callbackUrl` (`https://`); not on `cancelled` |
| `repay(string $id, array $params)` | `SubscriptionPaymentLink` | only for `pending_initial` |

`SubscriptionCheckout::autoSubmitFormHtml(): string` renders the auto-submitting Paywall form (all values HTML-escaped).

### `$aslan->billingAttempts` (`BillingAttempts`)

| Method | Returns | Notes |
|---|---|---|
| `list(array $query = [])` | `PaginatedResponse<BillingAttempt>` | filters `subscriptionId`, `status` (pending, succeeded, failed), `type` (initial, renewal, proration) |
| `receipt(string $id)` | `DocumentData` | `issuer`, `customer`, `lines`, `currency`, `totalHt`, `totalTva`, `totalTtc`, `issuedAt`; `succeeded` attempts only, not a trial verification |
| `receiptPdf(string $id)` | `string` | raw PDF bytes |
| `facture(string $id)` | `Invoice` | facture mode only, idempotent |

`BillingAttempt`: `id`, `subscriptionId`, `transactionId`, `type`, `status`, `amount`, `currency`, `providerRef`, `failureReason`, `attemptNumber`, `billingPeriodStart`, `billingPeriodEnd`, `createdAt`, `updatedAt`; helpers `isSucceeded()`, `isFailed()`.

### `$aslan->invoices` (`Invoices`)

| Method | Returns | Notes |
|---|---|---|
| `list(array $query = [])` | `PaginatedResponse<Invoice>` | filters `kind` (facture, avoir), `year` |
| `retrieve(string $id)` | `Invoice` | |
| `pdf(string $id)` | `string` | raw PDF bytes |

`Invoice`: `id`, `merchantId`, `billingAttemptId`, `kind`, `number`, `year`, `status`, `issuerSnapshot`, `customerSnapshot`, `lines`, `currency`, `totalHt`, `totalTva`, `totalTtc`, `issuedAt`. (`InvoiceLine` DTO: `description`, `qty`, `unitHt`, `tvaRateBps`, `totalTtc`.)

### Known gaps in 0.2.0

- `list()` results carry fewer fields than `retrieve()`: transaction list items have no refunds (so `refundedAmount` is 0 and `isRefundable()` assumes nothing was refunded), customer list items have no totals, subscription list items have no `customer` / `card`. Call `retrieve()` when you need those.
- `Transaction::$amount` is an `int`; a transaction whose amount is not known yet (open-amount session before the payer chose it) reads as `0`.
- The deprecated DTO properties listed above (kept for 0.1.0 compatibility) are never filled by the API; do not build on them.

### Custom HTTP client

Implement `Aslan\Payment\HttpClient\HttpClientInterface` and pass it as `httpClient:`. To support PDF downloads also implement `Aslan\Payment\HttpClient\RawHttpClientInterface::requestRaw(string $method, string $url, array $headers = [], array $query = []): string`; otherwise PDF methods throw `UNSUPPORTED_OPERATION`. Implementations must throw `AslanException` on HTTP/network errors (use `AslanException::fromApiResponse($status, $decodedBody)`, which accepts the API's `{"error": {...}}` envelope).

---

## 11. For LLM coding agents

Compact rules for AI assistants generating code with this SDK. A longer copy lives in [`AGENTS.md`](AGENTS.md).

### Invariants (always true)

1. **Amounts are integers in centimes**, currency `MAD`. `14900` = 149.00 MAD. Never floats, never strings.
2. **Never log or commit secrets**: `sk_live_...`, `sk_test_...`, webhook secrets, `$checkout->paywall`. Read them from environment variables.
3. **Verify webhooks against the RAW body** (`php://input` / `$request->getContent()`) with `Aslan::webhooks($secret)->constructEvent($raw, $header)`. Never verify a re-encoded array. The header is `X-Webhook-Signature`.
4. **The Paywall needs a form POST**: output `$checkout->autoSubmitFormHtml()`. Never `header('Location: ' . $checkout->redirectUrl)`.
5. **Do not mark orders paid or subscriptions active from the browser redirect** (`successUrl` / `returnUrl`). Wait for the verified webhook.
6. **Persist `$checkout->subscriptionId`** (against your customer) before redirecting the customer.
7. **Handle duplicate and out-of-order webhooks**: dedupe on `$event->id`, make handlers idempotent, return 2xx fast.
8. Catch `Aslan\Payment\AslanException`; read `$e->getErrorCode()` (string) and `$e->getCode()` (HTTP int). There is no `$e->code`.
9. Event names are `payment.*`, `refund.*`, `subscription.*` only. `checkout_session.completed` and `transaction.captured` do not exist. An immediate `cancel()` sends no webhook; `subscription.cancelled` only comes from period-end, dunning or abandoned cancellations.
10. Test vs live is the key prefix. Use `sk_test_` unless the user explicitly asks for live.
11. Laravel project? Use `aslan/payment-laravel`, not this package directly.
12. Do not downgrade PHP constraints or `composer.json` floors to make an install pass; upgrade PHP instead.
13. Create the customer with `$aslan->customers->create([...])` (email and/or `+212XXXXXXXXX` phone) before `subscriptions->create`; a duplicate email throws `AslanException` with `getCode() === 409`.
14. All `successUrl` / `cancelUrl` / `returnUrl` / `callbackUrl` / webhook URLs must be `https://`.
15. A plan with `trialDays > 0` charges only a refunded verification amount (1.00 MAD by default) at signup; the first real charge is at `trialEndsAt` and arrives as `subscription.renewed`. A `payment.succeeded` whose `data.transaction.metadata` has `__trialVerification` or `__changeCard` set to `true` is a verification charge, not a sale.
16. A payment link is paid once (then `finalized`); create one per invoice.

### Decision table: user intent to SDK call

| User wants | Call |
|---|---|
| One-time payment for an order | `$aslan->checkoutSessions->create([...], idempotencyKey: ...)` then redirect to `$session->url` |
| Reusable pay URL / donation / QR | `$aslan->paymentLinks->create([...])`, `->getQrCode($id)` |
| Check if something was paid | Rely on the webhook; or `$aslan->transactions->retrieve($id)` |
| Full or partial refund | `$aslan->refunds->create(['transactionId' => ..., 'amount' => ...])` |
| Define a recurring price | `$aslan->plans->create([...])` |
| Create a customer | `$aslan->customers->create(['email' => ..., 'name' => ..., 'phone' => ...])` (409 on duplicate email) |
| Start a subscription | `$aslan->customers->create([...])`, then `$aslan->subscriptions->create([...])` then `echo $checkout->autoSubmitFormHtml()` |
| Customer abandoned first payment | `$aslan->subscriptions->repay($id, [...])` then GET redirect to `->redirectUrl` |
| Customer's card expired / renewal failing / `past_due` | `$aslan->subscriptions->changeCard($id, [...])` then GET redirect (used by the next scheduled retry) |
| Upgrade/downgrade | `$aslan->subscriptions->changePlan($id, $planId, when: ..., allowDowngrade: ...)` |
| Cancel now | `$aslan->subscriptions->cancel($id)` |
| Cancel at end of period | `$aslan->subscriptions->cancel($id, atPeriodEnd: true)`; undo with `uncancel($id)` |
| Pause / resume | `pause($id)` / `resume($id)` |
| Temporary discount | `$aslan->subscriptions->update($id, ['priceOverride' => ..., 'priceOverrideMonths' => ...])` |
| Receipt / invoice PDF | `billingAttempts->receiptPdf($attemptId)`, `billingAttempts->facture($attemptId)` then `invoices->pdf($invoiceId)` |
| Receive events | `$aslan->webhooksConfig->create(['url' => ...])`; handler uses `Aslan::webhooks(...)->constructEvent(...)` |

### Canonical snippets

Client (one per request or a shared service):

```php
$aslan = new \Aslan\Payment\Aslan(getenv('ASLAN_SECRET_KEY'));
```

One-time payment:

```php
$session = $aslan->checkoutSessions->create([
    'amount' => 14900, 'currency' => 'MAD', 'metadata' => ['orderId' => '1042'],
    'successUrl' => $successUrl, 'cancelUrl' => $cancelUrl,   // both https://
], idempotencyKey: 'order_1042');
header('Location: ' . $session->url);
exit;
```

Start a subscription:

```php
$customer = $aslan->customers->create(['email' => $email, 'name' => $name]);   // 409 if the email exists
$checkout = $aslan->subscriptions->create([
    'planId' => $planId, 'customerId' => $customer->id,
    'returnUrl' => $returnUrl, 'cancelUrl' => $cancelUrl, 'callbackUrl' => $callbackUrl,
]);
store($customer->id, $checkout->subscriptionId);
echo $checkout->autoSubmitFormHtml();   // form POST to the Paywall
exit;
```

Webhook endpoint:

```php
$raw = file_get_contents('php://input');
try {
    $event = \Aslan\Payment\Aslan::webhooks(getenv('ASLAN_WEBHOOK_SECRET'))
        ->constructEvent($raw, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
} catch (\Aslan\Payment\AslanException $e) {
    http_response_code(400);
    exit;
}
if (alreadyProcessed($event->id)) { http_response_code(200); exit; }
match ($event->type) {
    'payment.succeeded'            => markPaid($event->asTransaction()),
    'subscription.renewed'         => extendAccess($event->asSubscription()),
    'subscription.payment_failed',
    'subscription.past_due'        => askForNewCard($event->asSubscription()),
    'subscription.cancelled'       => revokeAccess($event->asSubscription()),
    default                        => null,
};
http_response_code(200);
```

Error handling:

```php
try { /* SDK call */ }
catch (\Aslan\Payment\AslanException $e) {
    // $e->getErrorCode(): string, $e->getCode(): int HTTP status or 0, $e->requestId
}
```

### Anti-patterns (do not generate)

- `$e->code` (0.1.0 API; use `$e->errorCode` or `$e->getErrorCode()`).
- `'amount' => 149.00` or `'amount' => '149'`.
- `header('Location: ' . $checkout->redirectUrl)` for subscriptions.
- `json_decode($raw)` then `json_encode(...)` before verifying a webhook.
- Reading `$_POST` / framework-parsed JSON for the webhook body.
- Granting access or marking paid in the `successUrl` / `returnUrl` handler.
- Switching on `checkout_session.completed`, `transaction.captured`, or any event not in the table in section 7.
- Header names `Aslan-Signature` (wrong; it is `X-Webhook-Signature`).
- Hard-coding keys; echoing keys, `signingSecret`, or Paywall `payload`/`signature` into logs.
- Passing `$idempotencyKey` to `plans->create` / `subscriptions->create` / `customers->create` (not supported), or relying on it for `vendors` / `apiKeys` / `webhooksConfig` (ignored by the API).
- Expecting `subscription.cancelled` after your own immediate `cancel()` call (none is sent), or `payment.succeeded` for renewals (they send `subscription.renewed`).
- Calling `repay()` on anything but a `pending_initial` subscription.
- Booking a `payment.succeeded` whose metadata has `__trialVerification` / `__changeCard` = `true` as revenue (it is a refunded card check).
- Looking a customer up by email with `customers->list(['search' => ...])` (encrypted; store the customer id instead).
- Treating `Subscription::$status` from a webhook-derived `asSubscription()` as authoritative (it can be null); call `retrieve()`.
- Installing on an unpatched PHP or relaxing the PHP constraint.

---

## 12. Security checklist

- [ ] PHP is patched (see `php -v` against the table in section 2) and 8.2 users have a plan for its 2026-12-31 end of life.
- [ ] `composer audit` runs in CI.
- [ ] Secret keys and webhook secrets come from environment variables / a secret manager, are never committed, never logged, and are rotated if leaked.
- [ ] Live and test keys are separated per environment.
- [ ] Webhook endpoint is HTTPS, verifies the signature against the **raw body**, keeps the 300 s tolerance, and returns 400 on failure.
- [ ] Webhook handling is idempotent (event ids stored) and does the heavy work asynchronously.
- [ ] Orders and subscriptions are activated from webhooks, not from redirects.
- [ ] Amounts are validated server-side; never trust an amount sent from the browser.
- [ ] No card data on your servers (use the hosted pages / Paywall).
- [ ] `AslanException` messages are logged (with `requestId`) but not shown raw to end users.
- [ ] Dependencies are on maintained lines (`symfony/http-client` 6.4 LTS, 7.4 or 8.1+).

---

## 13. Upgrading from 0.1.0

```bash
composer update aslan/payment-php
```

**Why you must upgrade:** in 0.1.0, `AslanException` declared `public readonly string $code`, which redeclares the parent `Exception::$code` and makes PHP fail with a fatal error as soon as the class is loaded. Every error path (bad key, API error, bad webhook signature) crashed with a fatal error instead of throwing a catchable exception.

**Breaking change:**

```diff
- $e->code          // API error code, 0.1.0
+ $e->errorCode     // API error code (string), or $e->getErrorCode()
```

`getCode()` now returns the HTTP status as an int (`0` when there is no HTTP response), as PHP intends.

Other changes that may affect you:

- Webhook header is `X-Webhook-Signature` (docs previously said `Aslan-Signature`). The scheme `t=<unix>,v1=<hmac_hex>` over `"<t>.<rawBody>"` is unchanged.
- `WebhookEvent::$type` is now read from the payload's `event` field and `createdAt` is parsed from ISO 8601 (it used to be cast to an int, giving the year).
- Body-less `POST`/`DELETE` calls no longer send `Content-Type: application/json`.
- The PHP and dependency floors in section 2 apply: you may need to patch PHP before `composer update` succeeds.
- Replace any handling of `checkout_session.completed` / `transaction.captured` with the real event names (section 7).

---

## 14. Changelog, license, support

- Changelog: [CHANGELOG.md](CHANGELOG.md)
- License: MIT, see [LICENSE](LICENSE)
- Support: <dev@aslanpay.ma> or [aslanpay.ma](https://aslanpay.ma). When reporting a problem, include `$e->requestId`, the SDK version, and `php -v`; never include secret keys.
- Laravel integration: [aslan/payment-laravel](https://github.com/Belkouche/aslan-payment-laravel)
