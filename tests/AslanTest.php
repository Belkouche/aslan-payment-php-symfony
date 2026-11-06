<?php

declare(strict_types=1);

namespace Aslan\Payment\Tests;

use Aslan\Payment\Aslan;
use Aslan\Payment\AslanException;
use Aslan\Payment\HttpClient\HttpClientInterface;
use Aslan\Payment\Dto\CheckoutSession;
use Aslan\Payment\Dto\Transaction;
use Aslan\Payment\Dto\PaymentLink;
use Aslan\Payment\Dto\Refund;
use Aslan\Payment\Dto\Vendor;
use Aslan\Payment\Dto\Customer;
use Aslan\Payment\Dto\ApiKey;
use Aslan\Payment\Dto\WebhookConfig;
use Aslan\Payment\Dto\PaginatedResponse;
use Aslan\Payment\Dto\WebhookEvent;
use Aslan\Payment\Resources\Webhooks;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test suite for the Aslan Payment PHP SDK.
 *
 * All HTTP calls are intercepted by a mock HttpClientInterface — no real
 * network requests are made.
 */
final class AslanTest extends TestCase
{
    // ------------------------------------------------------------------
    // Test fixtures
    // ------------------------------------------------------------------

    private const LIVE_KEY = 'sk_live_testPrefix_testSecret';
    private const TEST_KEY = 'sk_test_testPrefix_testSecret';

    private HttpClientInterface&MockObject $mockHttp;
    private Aslan $client;

    protected function setUp(): void
    {
        $this->mockHttp = $this->createMock(HttpClientInterface::class);
        $this->client   = new Aslan(self::LIVE_KEY, httpClient: $this->mockHttp);
    }

    // ------------------------------------------------------------------
    // Constructor validation
    // ------------------------------------------------------------------

    public function test_rejects_invalid_key_format(): void
    {
        $this->expectException(AslanException::class);
        $this->expectExceptionMessage('Invalid secret key format');

        new Aslan('pk_live_something');
    }

    public function test_accepts_live_key(): void
    {
        $client = new Aslan(self::LIVE_KEY, httpClient: $this->mockHttp);

        self::assertTrue($client->isLiveMode());
        self::assertFalse($client->isTestMode());
    }

    public function test_accepts_test_key(): void
    {
        $client = new Aslan(self::TEST_KEY, httpClient: $this->mockHttp);

        self::assertTrue($client->isTestMode());
        self::assertFalse($client->isLiveMode());
    }

    public function test_base_url_defaults(): void
    {
        self::assertSame('https://api.aslanpay.ma/pay', $this->client->getBaseUrl());
    }

    public function test_custom_base_url(): void
    {
        $client = new Aslan(self::LIVE_KEY, baseUrl: 'https://staging.aslanpay.ma/pay', httpClient: $this->mockHttp);

        self::assertSame('https://staging.aslanpay.ma/pay', $client->getBaseUrl());
    }

    // ------------------------------------------------------------------
    // CheckoutSessions
    // ------------------------------------------------------------------

    public function test_checkout_session_create(): void
    {
        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api.aslanpay.ma/pay/api/v1/checkout/sessions',
                ['amount' => 5000, 'currency' => 'MAD'],
                [],
                [],
            )
            ->willReturn(['data' => $this->checkoutSessionPayload()]);

        $session = $this->client->checkoutSessions->create([
            'amount'   => 5000,
            'currency' => 'MAD',
        ]);

        self::assertInstanceOf(CheckoutSession::class, $session);
        self::assertSame('cs_abc123', $session->id);
        self::assertSame(5000, $session->amount);
        self::assertSame('MAD', $session->currency);
        self::assertSame('open', $session->status);
        self::assertTrue($session->isPending());
        self::assertNull($session->transactionId);
        self::assertFalse($session->openAmount);
        self::assertSame('https://pay.aslanpay.ma/checkout/tok_abc', $session->url);
    }

    public function test_provider_account_is_mapped_on_dtos(): void
    {
        $payload = $this->checkoutSessionPayload() + ['providerAccount' => 'foreign'];
        self::assertSame('foreign', CheckoutSession::fromArray($payload)->providerAccount);
        self::assertNull(CheckoutSession::fromArray($this->checkoutSessionPayload())->providerAccount);
        self::assertSame('store-2', \Aslan\Payment\Dto\Transaction::fromArray(['id' => 't1', 'providerAccount' => 'store-2'])->providerAccount);
        self::assertSame('default', \Aslan\Payment\Dto\PaymentLink::fromArray(['id' => 'l1', 'providerAccount' => 'default'])->providerAccount);
        self::assertSame('foreign', \Aslan\Payment\Dto\Subscription::fromArray(['id' => 's1', 'providerAccount' => 'foreign'])->providerAccount);
    }

    public function test_checkout_session_create_with_idempotency_key(): void
    {
        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                self::anything(),
                self::anything(),
                ['Idempotency-Key' => 'my-idem-key'],
            )
            ->willReturn(['data' => $this->checkoutSessionPayload()]);

        $this->client->checkoutSessions->create(['amount' => 5000], 'my-idem-key');
    }

    public function test_checkout_session_retrieve(): void
    {
        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.aslanpay.ma/pay/api/v1/checkout/sessions/cs_abc123', [], [], [])
            ->willReturn(['data' => $this->checkoutSessionPayload()]);

        $session = $this->client->checkoutSessions->retrieve('cs_abc123');

        self::assertSame('cs_abc123', $session->id);
    }

    public function test_checkout_session_status_helpers(): void
    {
        $data    = array_merge($this->checkoutSessionPayload(), ['status' => 'complete']);
        $session = CheckoutSession::fromArray($data);

        self::assertTrue($session->isCompleted());
        self::assertFalse($session->isPending());
        self::assertFalse($session->isExpired());

        foreach (['open', 'awaiting_cash', 'processing'] as $status) {
            $pending = CheckoutSession::fromArray(array_merge($data, ['status' => $status]));
            self::assertTrue($pending->isPending(), $status);
            self::assertFalse($pending->isCompleted(), $status);
        }

        self::assertTrue(CheckoutSession::fromArray(array_merge($data, ['status' => 'expired']))->isExpired());
    }

    public function test_checkout_session_retrieve_reads_nested_transaction(): void
    {
        $session = CheckoutSession::fromArray(array_merge($this->checkoutSessionPayload(), [
            'status'      => 'complete',
            'successUrl'  => 'https://shop.example/thanks',
            'cancelUrl'   => 'https://shop.example/cart',
            'metadata'    => ['orderId' => '1042'],
            'transaction' => [
                'id'          => '11111111-1111-4111-8111-111111111111',
                'status'      => 'succeeded',
                'providerRef' => 'pz_1',
                'paidAt'      => '2026-04-18T11:05:00.000Z',
                'failedAt'    => null,
            ],
        ]));

        self::assertSame('11111111-1111-4111-8111-111111111111', $session->transactionId);
        self::assertSame('succeeded', $session->transactionStatus);
        self::assertSame('https://shop.example/thanks', $session->successUrl);
        self::assertSame(['orderId' => '1042'], $session->metadata);
    }

    public function test_checkout_session_open_amount_derived_from_null_amount(): void
    {
        $data = $this->checkoutSessionPayload();
        unset($data['openAmount']);
        $data['amount'] = null;

        $session = CheckoutSession::fromArray($data);

        self::assertNull($session->amount);
        self::assertTrue($session->openAmount);
    }

    // ------------------------------------------------------------------
    // Transactions
    // ------------------------------------------------------------------

    public function test_transactions_list(): void
    {
        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with('GET', self::stringContains('/transactions'), [], [], ['page' => 1])
            ->willReturn($this->paginatedEnvelope([$this->transactionPayload()]));

        $result = $this->client->transactions->list(['page' => 1]);

        self::assertInstanceOf(PaginatedResponse::class, $result);
        self::assertCount(1, $result->data);
        self::assertInstanceOf(Transaction::class, $result->data[0]);
        self::assertSame('txn_xyz', $result->data[0]->id);
    }

    public function test_transactions_retrieve(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn(['data' => $this->transactionPayload()]);

        $txn = $this->client->transactions->retrieve('txn_xyz');

        self::assertSame('txn_xyz', $txn->id);
        self::assertSame(5000, $txn->amount);
        self::assertTrue($txn->isSuccessful());
        self::assertTrue($txn->isRefundable());
        self::assertSame('pz_ref_1', $txn->providerRef);
        self::assertSame('2026-04-18T11:01:00.000Z', $txn->paidAt);
        self::assertSame('cs_abc123', $txn->checkoutSessionId);
    }

    public function test_transaction_refundable_amount_from_refunds(): void
    {
        $txn = Transaction::fromArray(array_merge($this->transactionPayload(), [
            'status'  => 'partially_refunded',
            'amount'  => 5000,
            'refunds' => [
                ['id' => 'r1', 'amount' => 2000, 'currency' => 'MAD', 'status' => 'succeeded'],
                ['id' => 'r2', 'amount' => 500,  'currency' => 'MAD', 'status' => 'pending'],
                ['id' => 'r3', 'amount' => 900,  'currency' => 'MAD', 'status' => 'failed'],
                ['id' => 'r4', 'amount' => 900,  'currency' => 'MAD', 'status' => 'rejected'],
            ],
        ]));

        self::assertSame(2500, $txn->refundedAmount);
        self::assertSame(2500, $txn->refundableAmount());
        self::assertTrue($txn->isRefundable());
        self::assertFalse($txn->isSuccessful());
        self::assertCount(4, $txn->refunds);
    }

    public function test_transaction_status_helpers_match_api_statuses(): void
    {
        foreach (['pending', 'pending_cash', 'cash_late_confirmation', 'processing', 'failed', 'expired', 'refunded'] as $status) {
            $txn = Transaction::fromArray(array_merge($this->transactionPayload(), ['status' => $status]));
            self::assertFalse($txn->isSuccessful(), $status);
            self::assertFalse($txn->isRefundable(), $status);
        }

        $fullyRefunded = Transaction::fromArray(array_merge($this->transactionPayload(), [
            'status'  => 'partially_refunded',
            'refunds' => [['amount' => 5000, 'status' => 'succeeded']],
        ]));
        self::assertFalse($fullyRefunded->isRefundable());
    }

    public function test_transaction_list_item_with_null_amount(): void
    {
        $txn = Transaction::fromArray([
            'id'          => 'txn_open',
            'amount'      => null,
            'currency'    => 'MAD',
            'status'      => 'pending',
            'providerRef' => null,
            'customer'    => ['email' => 'jane@example.ma'],
            'metadata'    => [],
            'paidAt'      => null,
            'createdAt'   => '2026-04-18T11:00:00.000Z',
        ]);

        self::assertSame(0, $txn->amount);
        self::assertSame(0, $txn->refundedAmount);
        self::assertSame('', $txn->updatedAt);
    }

    // ------------------------------------------------------------------
    // PaymentLinks
    // ------------------------------------------------------------------

    public function test_payment_link_create(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn(['data' => $this->paymentLinkPayload()]);

        $link = $this->client->paymentLinks->create(['description' => 'Donation']);

        self::assertInstanceOf(PaymentLink::class, $link);
        self::assertSame('pl_def456', $link->id);
        self::assertTrue($link->isActive());
        self::assertSame('Donation', $link->description);
        self::assertSame('aBc123', $link->shortCode);
        self::assertSame(1000, $link->minAmount);
        self::assertNull($link->maxPayments);
        self::assertSame(3, $link->paymentCount);
        self::assertTrue($link->openAmount);
        self::assertSame('payment', $link->purpose);
        // deprecated aliases stay populated
        self::assertSame('Donation', $link->title);
        self::assertSame('aBc123', $link->slug);
        self::assertSame(3, $link->usageCount);
    }

    public function test_payment_link_list(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn($this->paginatedEnvelope([$this->paymentLinkPayload()]));

        $result = $this->client->paymentLinks->list();

        self::assertCount(1, $result->data);
        self::assertInstanceOf(PaymentLink::class, $result->data[0]);
    }

    public function test_payment_link_update(): void
    {
        $updated = array_merge($this->paymentLinkPayload(), ['description' => 'Updated']);

        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with('PATCH', self::stringContains('/pl_def456'))
            ->willReturn(['data' => $updated]);

        $link = $this->client->paymentLinks->update('pl_def456', ['description' => 'Updated']);

        self::assertSame('Updated', $link->description);
    }

    public function test_payment_link_delete(): void
    {
        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with('DELETE', self::stringContains('/pl_def456'))
            ->willReturn(['success' => true]);

        self::assertTrue($this->client->paymentLinks->delete('pl_def456'));
    }

    public function test_payment_link_qr_code(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn(['data' => ['dataUri' => 'data:image/png;base64,AAAA', 'mimeType' => 'image/png']]);

        $qr = $this->client->paymentLinks->getQrCode('pl_def456');

        self::assertSame('image/png', $qr['mimeType']);
    }

    public function test_payment_link_max_uses_check(): void
    {
        $link = PaymentLink::fromArray(array_merge($this->paymentLinkPayload(), [
            'maxPayments'  => 10,
            'paymentCount' => 10,
        ]));

        self::assertTrue($link->hasReachedMaxUses());
        self::assertSame(10, $link->maxUses);

        $paid = PaymentLink::fromArray(array_merge($this->paymentLinkPayload(), ['status' => 'finalized']));
        self::assertTrue($paid->isFinalized());
        self::assertFalse($paid->isActive());
        self::assertFalse(PaymentLink::fromArray($this->paymentLinkPayload())->hasReachedMaxUses());
    }

    // ------------------------------------------------------------------
    // Refunds
    // ------------------------------------------------------------------

    public function test_refund_create(): void
    {
        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with('POST', self::stringContains('/refunds'))
            ->willReturn(['data' => $this->refundPayload()]);

        $refund = $this->client->refunds->create([
            'transactionId' => 'txn_xyz',
            'amount'        => 5000,
        ]);

        self::assertInstanceOf(Refund::class, $refund);
        self::assertSame('ref_ghi789', $refund->id);
        self::assertTrue($refund->isSucceeded());
    }

    public function test_refunds_list(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn($this->paginatedEnvelope([$this->refundPayload()]));

        $result = $this->client->refunds->list();

        self::assertCount(1, $result->data);
    }

    // ------------------------------------------------------------------
    // Vendors
    // ------------------------------------------------------------------

    public function test_vendor_create(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn(['data' => $this->vendorPayload()]);

        $vendor = $this->client->vendors->create(['name' => 'Acme', 'externalId' => 'ACME']);

        self::assertInstanceOf(Vendor::class, $vendor);
        self::assertSame('ven_jkl012', $vendor->id);
        self::assertTrue($vendor->isActive());
        self::assertSame('active', $vendor->status);
        self::assertSame('ACME', $vendor->externalId);
        self::assertSame('ACME', $vendor->code);
        self::assertSame(0.1, $vendor->commissionRate);
    }

    public function test_vendor_inactive_from_is_active_flag(): void
    {
        $vendor = Vendor::fromArray(array_merge($this->vendorPayload(), ['isActive' => false, 'commissionRate' => null]));

        self::assertFalse($vendor->isActive());
        self::assertSame('inactive', $vendor->status);
        self::assertNull($vendor->commissionRate);
    }

    public function test_vendor_list_reads_top_level_pagination(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn([
                'data'       => [$this->vendorPayload()],
                'total'      => 45,
                'page'       => 2,
                'limit'      => 20,
                'totalPages' => 3,
            ]);

        $result = $this->client->vendors->list(['page' => 2, 'limit' => 20]);

        self::assertSame(2, $result->page);
        self::assertSame(20, $result->pageSize);
        self::assertSame(45, $result->total);
        self::assertSame(3, $result->totalPages);
        self::assertTrue($result->hasNextPage());
    }

    public function test_vendor_update(): void
    {
        $updated = array_merge($this->vendorPayload(), ['name' => 'Acme Updated']);

        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with('PATCH', self::stringContains('/ven_jkl012'))
            ->willReturn(['data' => $updated]);

        $vendor = $this->client->vendors->update('ven_jkl012', ['name' => 'Acme Updated']);

        self::assertSame('Acme Updated', $vendor->name);
    }

    public function test_vendor_delete(): void
    {
        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with('DELETE')
            ->willReturn(['success' => true]);

        self::assertTrue($this->client->vendors->delete('ven_jkl012'));
    }

    // ------------------------------------------------------------------
    // Customers
    // ------------------------------------------------------------------

    public function test_customers_list(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn($this->paginatedEnvelope([$this->customerPayload()]));

        $result = $this->client->customers->list();

        self::assertCount(1, $result->data);
        self::assertInstanceOf(Customer::class, $result->data[0]);
    }

    public function test_customer_retrieve(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn(['data' => $this->customerPayload()]);

        $customer = $this->client->customers->retrieve('cus_mno345');

        self::assertSame('cus_mno345', $customer->id);
        self::assertSame('Jane Doe', $customer->displayName());
        self::assertSame(15000, $customer->totalSpend);
        self::assertSame(3, $customer->transactionCount);
        self::assertSame(2, $customer->successfulTransactionCount);
        self::assertNull($customer->firstName);
    }

    public function test_customer_list_drops_internal_encrypted_email_metadata(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn([
                'data' => [[
                    'id'        => 'cus_1',
                    'email'     => 'jane@example.ma',
                    'name'      => null,
                    'phone'     => null,
                    'metadata'  => ['encryptedEmail' => 'enc:v1:iv:ct:tag', 'crmId' => '42'],
                    'createdAt' => '2026-04-01T08:00:00.000Z',
                    'updatedAt' => '2026-04-01T08:00:00.000Z',
                ]],
                'pagination' => ['total' => 1, 'page' => 1, 'pageSize' => 20, 'totalPages' => 1],
            ]);

        $customer = $this->client->customers->list()->data[0];

        self::assertSame(['crmId' => '42'], $customer->metadata);
        self::assertSame(0, $customer->totalSpend);
    }

    public function test_customer_create_sends_params_and_parses_response(): void
    {
        $params = [
            'email'    => 'jane@example.com',
            'phone'    => '+212612345678',
            'name'     => 'Jane Doe',
            'metadata' => ['crmId' => '42'],
        ];

        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with('POST', 'https://api.aslanpay.ma/pay/api/v1/customers', $params, [], [])
            ->willReturn(['data' => [
                'id'        => '33333333-3333-4333-8333-333333333333',
                'email'     => 'jane@example.com',
                'name'      => 'Jane Doe',
                'phone'     => '+212612345678',
                'metadata'  => ['crmId' => '42'],
                'createdAt' => '2026-10-22T09:00:00.000Z',
                'updatedAt' => '2026-10-22T09:00:00.000Z',
            ]]);

        $customer = $this->client->customers->create($params);

        self::assertInstanceOf(Customer::class, $customer);
        self::assertSame('33333333-3333-4333-8333-333333333333', $customer->id);
        self::assertSame('jane@example.com', $customer->email);
        self::assertSame('+212612345678', $customer->phone);
        self::assertSame('Jane Doe', $customer->name);
        self::assertSame(['crmId' => '42'], $customer->metadata);
        self::assertSame('2026-10-22T09:00:00.000Z', $customer->createdAt);
        self::assertSame(0, $customer->transactionCount);
        self::assertSame('Jane Doe', $customer->displayName());
    }

    public function test_customer_create_phone_only(): void
    {
        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with('POST', 'https://api.aslanpay.ma/pay/api/v1/customers', ['phone' => '+212612345678'], [], [])
            ->willReturn(['data' => ['id' => 'c1', 'email' => null, 'name' => null, 'phone' => '+212612345678', 'metadata' => []]]);

        $customer = $this->client->customers->create(['phone' => '+212612345678']);

        self::assertNull($customer->email);
        self::assertSame('+212612345678', $customer->displayName());
    }

    // ------------------------------------------------------------------
    // ApiKeys
    // ------------------------------------------------------------------

    public function test_api_key_create(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn(['data' => $this->apiKeyPayload(withSecret: true)]);

        $key = $this->client->apiKeys->create(['name' => 'Production key', 'type' => 'live']);

        self::assertInstanceOf(ApiKey::class, $key);
        self::assertNotNull($key->secretKey);
        self::assertSame('live', $key->type);
        self::assertTrue($key->isLive());
        self::assertFalse($key->isTest());
        self::assertTrue($key->isActive());
    }

    public function test_api_key_list_reads_type_and_is_active(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn(['data' => [
                array_merge($this->apiKeyPayload(), ['type' => 'test', 'isActive' => false, 'revokedAt' => '2026-04-19T08:00:00.000Z']),
            ]]);

        $key = $this->client->apiKeys->list()->data[0];

        self::assertSame('test', $key->type);
        self::assertSame('test', $key->environment);
        self::assertTrue($key->isTest());
        self::assertFalse($key->isLive());
        self::assertFalse($key->isActive());
        self::assertSame('revoked', $key->status);
        self::assertSame('2026-04-19T08:00:00.000Z', $key->revokedAt);
    }

    public function test_api_key_list_does_not_expose_secret(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn($this->paginatedEnvelope([$this->apiKeyPayload(withSecret: false)]));

        $result = $this->client->apiKeys->list();
        $key    = $result->data[0];

        self::assertNull($key->secretKey);
    }

    public function test_api_key_delete(): void
    {
        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with('DELETE')
            ->willReturn(['success' => true]);

        self::assertTrue($this->client->apiKeys->delete('key_pqr678'));
    }

    // ------------------------------------------------------------------
    // WebhooksConfig
    // ------------------------------------------------------------------

    public function test_webhook_config_create(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn(['data' => $this->webhookConfigPayload(withSecret: true)]);

        $config = $this->client->webhooksConfig->create(['url' => 'https://example.com/webhook']);

        self::assertInstanceOf(WebhookConfig::class, $config);
        self::assertNotNull($config->signingSecret);
        self::assertTrue($config->isActive());
        self::assertTrue($config->subscribesTo('checkout_session.completed'));
    }

    public function test_webhook_config_retrieve(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn(['data' => $this->webhookConfigPayload(withSecret: false)]);

        $config = $this->client->webhooksConfig->retrieve();

        self::assertNull($config->signingSecret);
    }

    public function test_webhook_config_empty_events_subscribes_to_all(): void
    {
        $config = WebhookConfig::fromArray(array_merge($this->webhookConfigPayload(), ['events' => []]));

        self::assertTrue($config->subscribesTo('any_event.type'));
    }

    public function test_webhook_config_reads_api_secret_field(): void
    {
        // Shape actually returned by POST /api/v1/webhooks/config on first configuration.
        $config = WebhookConfig::fromArray([
            'url'    => 'https://example.com/webhook',
            'secret' => 'whsec_0123abcd',
            'events' => ['payment.succeeded'],
        ]);

        self::assertSame('whsec_0123abcd', $config->signingSecret);
        self::assertSame('', $config->id);

        // Later calls return the secret masked: never expose that as the signing secret.
        $masked = WebhookConfig::fromArray(['url' => 'https://example.com/webhook', 'secret' => '**********abcd']);
        self::assertNull($masked->signingSecret);
    }

    // ------------------------------------------------------------------
    // Webhook verification
    // ------------------------------------------------------------------

    public function test_webhook_construct_event_valid_signature(): void
    {
        $secret  = 'whsec_supersecret';
        $handler = Aslan::webhooks($secret);

        $payload    = json_encode(['id' => 'evt_001', 'type' => 'checkout_session.completed', 'data' => ['amount' => 5000], 'apiVersion' => '2024-01', 'createdAt' => time()]);
        $sigHeader  = $handler->generateSignatureHeader($payload);

        $event = $handler->constructEvent($payload, $sigHeader);

        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertSame('evt_001', $event->id);
        self::assertSame('checkout_session.completed', $event->type);
    }

    public function test_webhook_construct_event_rejects_bad_signature(): void
    {
        $handler   = Aslan::webhooks('correct_secret');
        $payload   = json_encode(['id' => 'evt_002', 'type' => 'transaction.captured', 'data' => [], 'apiVersion' => '2024-01', 'createdAt' => time()]);

        // Use a different secret to generate the header
        $badSig = Aslan::webhooks('wrong_secret')->generateSignatureHeader($payload);

        $this->expectException(AslanException::class);
        $this->expectExceptionMessage('signature mismatch');

        $handler->constructEvent($payload, $badSig);
    }

    public function test_webhook_construct_event_rejects_expired_timestamp(): void
    {
        $secret  = 'whsec_test';
        $handler = Aslan::webhooks($secret);

        $payload = json_encode(['id' => 'evt_003', 'type' => 'refund.succeeded', 'data' => [], 'apiVersion' => '2024-01', 'createdAt' => time()]);

        // Generate a signature with a timestamp 10 minutes in the past
        $oldTimestamp = time() - 601;
        $signedPayload = $oldTimestamp . '.' . $payload;
        $sig    = hash_hmac('sha256', $signedPayload, $secret);
        $header = 't=' . $oldTimestamp . ',v1=' . $sig;

        $this->expectException(AslanException::class);
        $this->expectExceptionMessage('too old');

        $handler->constructEvent($payload, $header, tolerance: 300);
    }

    public function test_webhook_construct_event_skips_tolerance_check_when_zero(): void
    {
        $secret  = 'whsec_test';
        $handler = Aslan::webhooks($secret);

        $payload = json_encode(['id' => 'evt_004', 'type' => 'refund.succeeded', 'data' => [], 'apiVersion' => '2024-01', 'createdAt' => time()]);

        $oldTimestamp  = time() - 99_999;
        $signedPayload = $oldTimestamp . '.' . $payload;
        $sig    = hash_hmac('sha256', $signedPayload, $secret);
        $header = 't=' . $oldTimestamp . ',v1=' . $sig;

        // tolerance=0 disables the check — should not throw
        $event = $handler->constructEvent($payload, $header, tolerance: 0);

        self::assertSame('evt_004', $event->id);
    }

    public function test_webhook_rejects_empty_header(): void
    {
        $this->expectException(AslanException::class);
        $this->expectExceptionMessage('empty or missing');

        Aslan::webhooks('secret')->constructEvent('{}', '');
    }

    public function test_webhook_event_typed_accessors(): void
    {
        $payload = [
            'id'         => 'evt_005',
            'type'       => 'checkout_session.completed',
            'apiVersion' => '2024-01',
            'createdAt'  => time(),
            'data'       => ['object' => $this->checkoutSessionPayload()],
        ];

        $event   = WebhookEvent::fromArray($payload);
        $session = $event->asCheckoutSession();

        self::assertInstanceOf(CheckoutSession::class, $session);
        self::assertSame('cs_abc123', $session->id);
    }

    public function test_webhook_event_as_refund_reads_data_refund(): void
    {
        $event = WebhookEvent::fromArray([
            'id'        => 'evt_r1',
            'event'     => 'refund.updated',
            'createdAt' => '2026-10-22T09:00:00.000Z',
            'data'      => ['refund' => $this->refundPayload()],
        ]);

        $refund = $event->asRefund();

        self::assertSame($this->refundPayload()['id'], $refund->id);
    }

    // ------------------------------------------------------------------
    // AslanException
    // ------------------------------------------------------------------

    public function test_exception_factory_methods(): void
    {
        $authEx = AslanException::authenticationError('Bad key', 401, 'req_001');

        self::assertSame('AUTHENTICATION_ERROR', $authEx->errorCode);
        self::assertSame(401, $authEx->httpStatus);
        self::assertSame('req_001', $authEx->requestId);

        $validEx = AslanException::validationError('Invalid amount', ['amount' => 'must be positive']);
        self::assertSame('VALIDATION_ERROR', $validEx->errorCode);
        self::assertNotEmpty($validEx->details);
    }

    public function test_exception_from_api_response_unwraps_error_envelope(): void
    {
        $ex = AslanException::fromApiResponse(400, [
            'error' => [
                'code'    => 'VALIDATION_ERROR',
                'message' => 'Invalid customer data',
                'details' => [['field' => 'phone', 'message' => 'phone must match +212XXXXXXXXX']],
            ],
        ]);

        self::assertSame('VALIDATION_ERROR', $ex->errorCode);
        self::assertSame('Invalid customer data', $ex->getMessage());
        self::assertSame(400, $ex->getCode());
        self::assertSame([['field' => 'phone', 'message' => 'phone must match +212XXXXXXXXX']], $ex->details);
    }

    public function test_exception_from_api_response(): void
    {
        $ex = AslanException::fromApiResponse(422, [
            'code'    => 'VALIDATION_ERROR',
            'message' => 'amount must be positive',
            'details' => ['amount' => 'must be a positive integer'],
        ], 'req_xyz');

        self::assertSame('VALIDATION_ERROR', $ex->errorCode);
        self::assertSame('amount must be positive', $ex->getMessage());
        self::assertSame(422, $ex->httpStatus);
        self::assertSame('req_xyz', $ex->requestId);
    }

    // ------------------------------------------------------------------
    // PaginatedResponse
    // ------------------------------------------------------------------

    public function test_paginated_response_navigation(): void
    {
        $paginated = PaginatedResponse::fromArray(
            [
                'data' => [['id' => 'a'], ['id' => 'b']],
                'meta' => ['page' => 2, 'pageSize' => 2, 'total' => 6, 'totalPages' => 3],
            ],
            static fn (array $item) => $item,
        );

        self::assertTrue($paginated->hasNextPage());
        self::assertTrue($paginated->hasPreviousPage());
        self::assertSame(2, $paginated->page);
        self::assertSame(6, $paginated->total);
    }

    // ------------------------------------------------------------------
    // Private fixture helpers
    // ------------------------------------------------------------------

    private function checkoutSessionPayload(): array
    {
        return [
            'id'         => 'cs_abc123',
            'token'      => 'tok_abc',
            'url'        => 'https://pay.aslanpay.ma/checkout/tok_abc',
            'amount'     => 5000,
            'currency'   => 'MAD',
            'status'       => 'open',
            'openAmount'   => false,
            'expiresAt'    => '2026-04-18T12:00:00Z',
            'createdAt'    => '2026-04-18T11:00:00Z',
            'cryptoScheme' => 'rsa-oaep-aes-gcm',
        ];
    }

    private function transactionPayload(): array
    {
        return [
            'id'                => 'txn_xyz',
            'amount'            => 5000,
            'currency'          => 'MAD',
            'status'            => 'succeeded',
            'type'              => 'payment',
            'providerRef'       => 'pz_ref_1',
            'providerStatus'    => 'succeeded',
            'checkoutSessionId' => 'cs_abc123',
            'metadata'          => [],
            'errorCode'         => null,
            'errorMessage'      => null,
            'paidAt'            => '2026-04-18T11:01:00.000Z',
            'failedAt'          => null,
            'expiresAt'         => null,
            'createdAt'         => '2026-04-18T11:00:00Z',
            'updatedAt'         => '2026-04-18T11:01:00Z',
            'customer'          => ['email' => 'jane@example.ma', 'name' => 'Jane Doe'],
            'refunds'           => [],
        ];
    }

    private function paymentLinkPayload(): array
    {
        return [
            'id'             => 'pl_def456',
            'merchantId'     => '99999999-9999-4999-8999-999999999999',
            'shortCode'      => 'aBc123',
            'amount'         => null,
            'minAmount'      => 1000,
            'maxAmount'      => null,
            'currency'       => 'MAD',
            'description'    => 'Donation',
            'status'         => 'active',
            'purpose'        => 'payment',
            'subscriptionId' => null,
            'maxPayments'    => null,
            'paymentCount'   => 3,
            'expiresAt'      => null,
            'vendorId'       => null,
            'metadata'       => [],
            'createdAt'      => '2026-04-18T10:00:00Z',
            'updatedAt'      => '2026-04-18T10:00:00Z',
            'url'            => 'https://pay.aslan.ma/pl/aBc123',
        ];
    }

    private function refundPayload(): array
    {
        return [
            'id'            => 'ref_ghi789',
            'transactionId' => 'txn_xyz',
            'amount'        => 5000,
            'currency'      => 'MAD',
            'status'        => 'succeeded',
            'metadata'      => [],
            'createdAt'     => '2026-04-18T12:30:00Z',
            'updatedAt'     => '2026-04-18T12:30:00Z',
        ];
    }

    private function vendorPayload(): array
    {
        return [
            'id'             => 'ven_jkl012',
            'merchantId'     => '99999999-9999-4999-8999-999999999999',
            'externalId'     => 'ACME',
            'name'           => 'Acme',
            'email'          => null,
            'commissionRate' => '0.1',
            'isActive'       => true,
            'metadata'       => [],
            'createdAt'      => '2026-04-18T09:00:00Z',
            'updatedAt'      => '2026-04-18T09:00:00Z',
        ];
    }

    private function customerPayload(): array
    {
        return [
            'id'           => 'cus_mno345',
            'email'        => 'jane@example.ma',
            'name'         => 'Jane Doe',
            'phone'        => '+212600000004',
            'metadata'     => [],
            'createdAt'    => '2026-04-01T08:00:00Z',
            'updatedAt'    => '2026-04-18T11:00:00Z',
            'transactions' => [
                'count'        => 3,
                'totalAmount'  => 15000,
                'currency'     => 'MAD',
                'successCount' => 2,
                'failedCount'  => 1,
            ],
            'subscriptions' => [],
            'cards'         => [],
        ];
    }

    private function apiKeyPayload(bool $withSecret = false): array
    {
        return [
            'id'             => 'key_pqr678',
            'name'           => 'Production key',
            'type'           => 'live',
            'prefix'         => 'testPrefix',
            'publishableKey' => 'pk_live_testPrefix',
            'createdAt'      => '2026-04-18T08:00:00Z',
        ] + ($withSecret
            ? ['secretKey' => 'sk_live_testPrefix_fullsecret']
            : ['isActive' => true, 'lastUsedAt' => null, 'revokedAt' => null]);
    }

    private function webhookConfigPayload(bool $withSecret = false): array
    {
        return [
            'id'            => 'wh_stu901',
            'url'           => 'https://example.com/webhook',
            'status'        => 'active',
            'events'        => ['checkout_session.completed', 'transaction.captured'],
            'signingSecret' => $withSecret ? 'whsec_abc123' : null,
            'apiVersion'    => '2024-01',
            'createdAt'     => '2026-04-18T08:00:00Z',
            'updatedAt'     => '2026-04-18T08:00:00Z',
        ];
    }

    /** Wrap items in the standard list envelope */
    private function paginatedEnvelope(array $items): array
    {
        return [
            'data' => $items,
            'meta' => [
                'page'       => 1,
                'pageSize'   => 20,
                'total'      => count($items),
                'totalPages' => 1,
            ],
        ];
    }
}
