# Aslan Payment PHP SDK

Official PHP SDK for the [Aslan Payment API](https://aslanpay.ma). Accepts payments, manages payment links, issues refunds, and handles webhook events — all in a clean, type-safe PHP 8.1+ API.

## Requirements

- PHP 8.1 or higher
- [Symfony HttpClient](https://symfony.com/doc/current/http_client.html) (`symfony/http-client ^6.0|^7.0`)

The SDK is designed to work seamlessly inside a Symfony application but is **not coupled to the Symfony framework**. You can use any PSR-18 compatible HTTP client by implementing the lightweight `HttpClientInterface`.

## Installation

```bash
composer require aslan/payment-php
```

## Quick Start

### 1. Create a checkout session

```php
use Aslan\Payment\Aslan;

$aslan = new Aslan($_ENV['ASLAN_SECRET_KEY']);

$session = $aslan->checkoutSessions->create([
    'amount'     => 5000,   // 50.00 MAD (amounts are always in centimes)
    'currency'   => 'MAD',
    'reference'  => 'order_' . $orderId,
    'description'=> 'Your order from Acme Store',
    'successUrl' => 'https://example.com/thanks',
    'cancelUrl'  => 'https://example.com/cart',
    'metadata'   => ['userId' => 42],
]);

// Redirect the customer
header('Location: ' . $session->url);
```

### 2. Create a reusable payment link

```php
$link = $aslan->paymentLinks->create([
    'title'      => 'Donate to our cause',
    'openAmount' => true,                  // customer enters the amount
    'successUrl' => 'https://example.com/thanks',
]);

echo $link->url; // https://pay.aslanpay.ma/l/abc123
```

### 3. Verify a webhook event

```php
// In your webhook endpoint (e.g. a Symfony controller):
$rawBody   = $request->getContent();
$sigHeader = $request->headers->get('Aslan-Signature', '');

try {
    $event = Aslan::webhooks($_ENV['ASLAN_WEBHOOK_SECRET'])
        ->constructEvent($rawBody, $sigHeader);

    match ($event->type) {
        'checkout_session.completed' => handleCheckoutCompleted($event->asCheckoutSession()),
        'transaction.captured'       => handleTransactionCaptured($event->asTransaction()),
        'refund.succeeded'           => handleRefundSucceeded($event->asRefund()),
        default                      => null,
    };

    return new Response('OK');
} catch (\Aslan\Payment\AslanException $e) {
    // Signature verification failed — reject the request
    return new Response('Forbidden', 403);
}
```

---

## Configuration

```php
$aslan = new Aslan(
    secretKey:  $_ENV['ASLAN_SECRET_KEY'],   // sk_live_xxx or sk_test_xxx
    baseUrl:    'https://api.aslanpay.ma/pay', // default — override for staging
    timeout:    30,                            // seconds
    maxRetries: 2,                             // retries on 5xx / 429 / timeout
);
```

### Test mode

Pass a `sk_test_` key and all API calls will hit the sandbox environment. No real payments are processed.

```php
$aslan = new Aslan('sk_test_yourTestKey');

echo $aslan->isTestMode() ? 'sandbox' : 'live'; // sandbox
```

---

## Resources

All amounts are in **centimes** (integer). 100 centimes = 1.00 MAD.

### CheckoutSessions

```php
// Create
$session = $aslan->checkoutSessions->create([
    'amount'     => 15000,          // 150.00 MAD
    'currency'   => 'MAD',
    'reference'  => 'ORDER-42',
    'successUrl' => 'https://example.com/success',
    'cancelUrl'  => 'https://example.com/cancel',
    'expiresIn'  => 3600,           // seconds, default 3600
    'metadata'   => ['key' => 'value'],
]);

echo $session->id;          // cs_xxxxxxxxxxxxxxxx
echo $session->url;         // https://pay.aslanpay.ma/checkout/tok_xxx
echo $session->status;      // pending | completed | expired | cancelled
echo $session->isCompleted(); // bool

// Retrieve
$session = $aslan->checkoutSessions->retrieve('cs_xxxxxxxxxxxxxxxx');
```

**Open-amount session** (customer decides the amount):

```php
$session = $aslan->checkoutSessions->create([
    'openAmount' => true,
    'successUrl' => 'https://example.com/thanks',
]);
```

**Idempotency** — safe to retry:

```php
$session = $aslan->checkoutSessions->create($params, idempotencyKey: 'order_42_attempt_1');
```

---

### Transactions

```php
// List (paginated)
$page = $aslan->transactions->list([
    'page'      => 1,
    'pageSize'  => 20,
    'status'    => 'captured',
    'from'      => '2024-01-01T00:00:00Z',
    'to'        => '2024-12-31T23:59:59Z',
]);

foreach ($page->data as $txn) {
    echo $txn->id . ' — ' . ($txn->amount / 100) . ' MAD' . PHP_EOL;
}

echo $page->total;       // total across all pages
echo $page->totalPages;
echo $page->hasNextPage() ? 'more pages' : 'last page';

// Retrieve
$txn = $aslan->transactions->retrieve('txn_xxxxxxxxxxxxxxxx');
echo $txn->status;           // pending | authorized | captured | failed | …
echo $txn->cardLast4;        // "4242"
echo $txn->refundableAmount(); // remaining refundable centimes
```

---

### PaymentLinks

```php
// Create
$link = $aslan->paymentLinks->create([
    'title'      => 'Invoice #123',
    'amount'     => 75000,          // 750.00 MAD
    'currency'   => 'MAD',
    'maxUses'    => 1,              // single-use invoice link
    'expiresAt'  => '2024-12-31T23:59:59Z',
    'successUrl' => 'https://example.com/paid',
]);

// List
$page = $aslan->paymentLinks->list(['status' => 'active']);

// Retrieve
$link = $aslan->paymentLinks->retrieve('pl_xxxxxxxxxxxxxxxx');

// Update
$link = $aslan->paymentLinks->update('pl_xxxxxxxxxxxxxxxx', [
    'title'  => 'Updated title',
    'status' => 'inactive',        // deactivate without deleting
]);

// Delete
$aslan->paymentLinks->delete('pl_xxxxxxxxxxxxxxxx');

// QR code
$qr = $aslan->paymentLinks->getQrCode('pl_xxxxxxxxxxxxxxxx');
echo $qr['qrCodeUrl'];        // PNG image URL
echo $qr['qrCodeDataUrl'];    // base64 data URL for inline <img>
```

---

### Refunds

```php
// Full refund
$refund = $aslan->refunds->create([
    'transactionId' => 'txn_xxxxxxxxxxxxxxxx',
    // omit 'amount' for a full refund
    'reason'        => 'customer_request',
]);

// Partial refund
$refund = $aslan->refunds->create([
    'transactionId' => 'txn_xxxxxxxxxxxxxxxx',
    'amount'        => 2500,   // 25.00 MAD partial refund
    'note'          => 'Partial refund for returned item',
], idempotencyKey: 'refund_order_42');

echo $refund->status;          // pending | succeeded | failed

// List
$page = $aslan->refunds->list(['transactionId' => 'txn_xxxxxxxxxxxxxxxx']);

// Retrieve
$refund = $aslan->refunds->retrieve('ref_xxxxxxxxxxxxxxxx');
```

---

### Vendors

Vendors are sub-merchants in marketplace setups.

```php
// Create
$vendor = $aslan->vendors->create([
    'name'  => 'Acme Store',
    'code'  => 'ACME_01',
    'email' => 'accounting@acme.ma',
    'phone' => '+212661234567',
    'iban'  => 'MA64011519000001205000534921',
]);

// List
$page = $aslan->vendors->list(['status' => 'active', 'search' => 'acme']);

// Retrieve
$vendor = $aslan->vendors->retrieve('ven_xxxxxxxxxxxxxxxx');

// Update
$vendor = $aslan->vendors->update('ven_xxxxxxxxxxxxxxxx', [
    'email' => 'new@acme.ma',
]);

// Delete (must have no pending settlements)
$aslan->vendors->delete('ven_xxxxxxxxxxxxxxxx');
```

---

### Customers

Customers are read-only — created automatically when a payment is completed.

```php
// List
$page = $aslan->customers->list([
    'search' => 'jane@',
    'page'   => 1,
]);

// Retrieve
$customer = $aslan->customers->retrieve('cus_xxxxxxxxxxxxxxxx');

echo $customer->displayName();  // "Jane Doe" or email/phone as fallback
echo $customer->totalSpend;     // lifetime spend in centimes
echo $customer->transactionCount;
```

---

### ApiKeys

```php
// Create a new key (secret is returned ONLY here — store it immediately)
$key = $aslan->apiKeys->create([
    'name'        => 'Mobile app integration',
    'environment' => 'live',
]);

echo $key->secretKey;  // sk_live_prefix_secret — save this now!

// List (secretKey is null on list)
$page = $aslan->apiKeys->list(['environment' => 'live']);

// Revoke
$aslan->apiKeys->delete('key_xxxxxxxxxxxxxxxx');
```

---

### Webhooks Configuration

```php
// Create / replace endpoint (signing secret returned ONLY here)
$config = $aslan->webhooksConfig->create([
    'url'    => 'https://example.com/webhooks/aslan',
    'events' => [
        'checkout_session.completed',
        'checkout_session.expired',
        'transaction.captured',
        'transaction.failed',
        'refund.succeeded',
        'refund.failed',
    ],
]);

echo $config->signingSecret;  // whsec_xxxxxxxx — save this now!

// Retrieve current config
$config = $aslan->webhooksConfig->retrieve();
echo $config->url;
echo $config->isActive() ? 'active' : 'inactive';
```

---

### Webhook Verification

```php
use Aslan\Payment\Aslan;
use Aslan\Payment\AslanException;

// Symfony controller example
#[Route('/webhooks/aslan', methods: ['POST'])]
public function handle(Request $request): Response
{
    $rawBody   = $request->getContent();
    $sigHeader = $request->headers->get('Aslan-Signature', '');

    try {
        $event = Aslan::webhooks($_ENV['ASLAN_WEBHOOK_SECRET'])
            ->constructEvent($rawBody, $sigHeader);
    } catch (AslanException $e) {
        return new Response('Bad signature', Response::HTTP_FORBIDDEN);
    }

    switch ($event->type) {
        case 'checkout_session.completed':
            $session = $event->asCheckoutSession();
            // $session->transactionId, $session->reference, etc.
            $this->orderService->markPaid($session->reference);
            break;

        case 'transaction.captured':
            $txn = $event->asTransaction();
            $this->notificationService->sendReceipt($txn->id, $txn->amount);
            break;

        case 'refund.succeeded':
            $refund = $event->asRefund();
            $this->orderService->processRefund($refund->transactionId, $refund->amount);
            break;
    }

    return new Response('OK');
}
```

**Testing your webhook handler** (generate a valid signature without hitting the API):

```php
$webhooks   = Aslan::webhooks('whsec_test_secret');
$payload    = json_encode(['id' => 'evt_001', 'type' => 'checkout_session.completed', ...]);
$sigHeader  = $webhooks->generateSignatureHeader($payload);

// Now call your handler with $payload and $sigHeader
```

---

## Error Handling

All errors are thrown as `Aslan\Payment\AslanException`:

```php
use Aslan\Payment\AslanException;

try {
    $session = $aslan->checkoutSessions->create(['amount' => -100]);
} catch (AslanException $e) {
    echo $e->code;       // VALIDATION_ERROR
    echo $e->getMessage(); // "amount must be a positive integer"
    echo $e->httpStatus; // 422
    echo $e->requestId;  // req_xxxxxxxx (for support tickets)
    print_r($e->details); // field-level validation errors
}
```

**Error codes:**

| Code | HTTP Status | Meaning |
|---|---|---|
| `AUTHENTICATION_ERROR` | 401 | Invalid or missing API key |
| `AUTHORIZATION_ERROR` | 403 | Authenticated but not permitted |
| `VALIDATION_ERROR` | 422 | Request payload failed validation |
| `NOT_FOUND` | 404 | Resource does not exist |
| `CONFLICT` | 409 | Idempotency key reused with different params |
| `RATE_LIMIT_ERROR` | 429 | Too many requests |
| `GATEWAY_ERROR` | 502 | Upstream payment provider error |
| `SERVER_ERROR` | 500 | Unexpected server error |
| `NETWORK_ERROR` | 0 | Connection or timeout failure |
| `WEBHOOK_SIGNATURE_ERROR` | 0 | Webhook signature verification failed |

The SDK automatically retries `408`, `429`, `500`, `502`, `503`, and `504` responses with exponential back-off (500ms → 1s → 2s, capped at 5s) before throwing.

---

## Symfony Integration

### Service configuration

```yaml
# config/services.yaml
services:
    Aslan\Payment\Aslan:
        arguments:
            $secretKey:  '%env(ASLAN_SECRET_KEY)%'
            $baseUrl:    '%env(default:https\://api.aslanpay.ma/pay:ASLAN_BASE_URL)%'
            $timeout:    30
            $maxRetries: 2
        public: true
```

```dotenv
# .env
ASLAN_SECRET_KEY=sk_live_xxxxxxxxxxxxxxxxxxxxxx
ASLAN_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxxxxxx
```

### Injecting into a controller

```php
use Aslan\Payment\Aslan;

class CheckoutController extends AbstractController
{
    public function __construct(private readonly Aslan $aslan) {}

    #[Route('/checkout', methods: ['POST'])]
    public function checkout(Request $request): Response
    {
        $session = $this->aslan->checkoutSessions->create([
            'amount'     => $request->request->getInt('amount'),
            'currency'   => 'MAD',
            'reference'  => $request->request->get('orderId'),
            'successUrl' => $this->generateUrl('checkout_success', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'cancelUrl'  => $this->generateUrl('checkout_cancel',  [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        return $this->redirect($session->url);
    }
}
```

---

## Using a Custom HTTP Client

Implement `Aslan\Payment\HttpClient\HttpClientInterface` to swap in any PSR-18 client or a test double:

```php
use Aslan\Payment\HttpClient\HttpClientInterface;
use Aslan\Payment\AslanException;

final class GuzzleAdapter implements HttpClientInterface
{
    public function __construct(private \GuzzleHttp\Client $guzzle) {}

    public function request(
        string $method,
        string $url,
        array $body = [],
        array $headers = [],
        array $query = [],
    ): array {
        try {
            $options = ['headers' => $headers, 'query' => $query];
            if ($body && $method !== 'GET') {
                $options['json'] = $body;
            }

            $response = $this->guzzle->request($method, $url, $options);
            return json_decode((string) $response->getBody(), true);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $decoded = json_decode((string) $e->getResponse()->getBody(), true) ?? [];
            throw AslanException::fromApiResponse($e->getCode(), $decoded);
        }
    }
}

// Pass it to the client
$aslan = new Aslan('sk_live_xxx', httpClient: new GuzzleAdapter($guzzle));
```

---

## Running Tests

```bash
composer install
vendor/bin/phpunit
```

With coverage (requires Xdebug or PCOV):

```bash
vendor/bin/phpunit --coverage-html coverage/
```

---

## Changelog

### 0.1.0 (2024-01)

- Initial release
- CheckoutSessions, Transactions, PaymentLinks, Refunds, Vendors, Customers, ApiKeys, WebhooksConfig
- Webhook signature verification with replay-attack protection
- Symfony HttpClient adapter with exponential back-off retry
- PHP 8.1 readonly DTOs

---

## License

MIT — see [LICENSE](LICENSE).
