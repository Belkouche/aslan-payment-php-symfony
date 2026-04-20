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
        self::assertSame('pending', $session->status);
        self::assertFalse($session->openAmount);
        self::assertSame('https://pay.aslanpay.ma/checkout/tok_abc', $session->url);
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
        $data    = array_merge($this->checkoutSessionPayload(), ['status' => 'completed']);
        $session = CheckoutSession::fromArray($data);

        self::assertTrue($session->isCompleted());
        self::assertFalse($session->isPending());
        self::assertFalse($session->isExpired());
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
    }

    public function test_transaction_refundable_amount(): void
    {
        $txn = Transaction::fromArray(array_merge($this->transactionPayload(), [
            'status'         => 'partially_refunded',
            'amount'         => 5000,
            'refundedAmount' => 2000,
        ]));

        self::assertSame(3000, $txn->refundableAmount());
        self::assertTrue($txn->isRefundable());
    }

    // ------------------------------------------------------------------
    // PaymentLinks
    // ------------------------------------------------------------------

    public function test_payment_link_create(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn(['data' => $this->paymentLinkPayload()]);

        $link = $this->client->paymentLinks->create(['title' => 'Donation']);

        self::assertInstanceOf(PaymentLink::class, $link);
        self::assertSame('pl_def456', $link->id);
        self::assertTrue($link->isActive());
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
        $updated = array_merge($this->paymentLinkPayload(), ['title' => 'Updated Title']);

        $this->mockHttp
            ->expects(self::once())
            ->method('request')
            ->with('PATCH', self::stringContains('/pl_def456'))
            ->willReturn(['data' => $updated]);

        $link = $this->client->paymentLinks->update('pl_def456', ['title' => 'Updated Title']);

        self::assertSame('Updated Title', $link->title);
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
            ->willReturn(['data' => ['qrCodeUrl' => 'https://cdn.aslanpay.ma/qr/pl_def456.png']]);

        $qr = $this->client->paymentLinks->getQrCode('pl_def456');

        self::assertArrayHasKey('qrCodeUrl', $qr);
    }

    public function test_payment_link_max_uses_check(): void
    {
        $link = PaymentLink::fromArray(array_merge($this->paymentLinkPayload(), [
            'maxUses'    => 10,
            'usageCount' => 10,
        ]));

        self::assertTrue($link->hasReachedMaxUses());
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

        $vendor = $this->client->vendors->create(['name' => 'Acme', 'code' => 'ACME']);

        self::assertInstanceOf(Vendor::class, $vendor);
        self::assertSame('ven_jkl012', $vendor->id);
        self::assertTrue($vendor->isActive());
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
    }

    // ------------------------------------------------------------------
    // ApiKeys
    // ------------------------------------------------------------------

    public function test_api_key_create(): void
    {
        $this->mockHttp
            ->method('request')
            ->willReturn(['data' => $this->apiKeyPayload(withSecret: true)]);

        $key = $this->client->apiKeys->create(['name' => 'Production key']);

        self::assertInstanceOf(ApiKey::class, $key);
        self::assertNotNull($key->secretKey);
        self::assertTrue($key->isLive());
        self::assertTrue($key->isActive());
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

    // ------------------------------------------------------------------
    // AslanException
    // ------------------------------------------------------------------

    public function test_exception_factory_methods(): void
    {
        $authEx = AslanException::authenticationError('Bad key', 401, 'req_001');

        self::assertSame('AUTHENTICATION_ERROR', $authEx->code);
        self::assertSame(401, $authEx->httpStatus);
        self::assertSame('req_001', $authEx->requestId);

        $validEx = AslanException::validationError('Invalid amount', ['amount' => 'must be positive']);
        self::assertSame('VALIDATION_ERROR', $validEx->code);
        self::assertNotEmpty($validEx->details);
    }

    public function test_exception_from_api_response(): void
    {
        $ex = AslanException::fromApiResponse(422, [
            'code'    => 'VALIDATION_ERROR',
            'message' => 'amount must be positive',
            'details' => ['amount' => 'must be a positive integer'],
        ], 'req_xyz');

        self::assertSame('VALIDATION_ERROR', $ex->code);
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
            'status'     => 'pending',
            'openAmount' => false,
            'metadata'   => [],
            'expiresAt'  => '2026-04-18T12:00:00Z',
            'createdAt'  => '2026-04-18T11:00:00Z',
            'updatedAt'  => '2026-04-18T11:00:00Z',
        ];
    }

    private function transactionPayload(): array
    {
        return [
            'id'             => 'txn_xyz',
            'amount'         => 5000,
            'currency'       => 'MAD',
            'status'         => 'captured',
            'paymentMethod'  => 'card',
            'cardLast4'      => '4242',
            'cardBrand'      => 'visa',
            'refundedAmount' => 0,
            'metadata'       => [],
            'createdAt'      => '2026-04-18T11:00:00Z',
            'updatedAt'      => '2026-04-18T11:00:00Z',
        ];
    }

    private function paymentLinkPayload(): array
    {
        return [
            'id'         => 'pl_def456',
            'title'      => 'Donation',
            'url'        => 'https://pay.aslanpay.ma/l/donation',
            'slug'       => 'donation',
            'status'     => 'active',
            'amount'     => null,
            'currency'   => 'MAD',
            'openAmount' => true,
            'usageCount' => 3,
            'metadata'   => [],
            'createdAt'  => '2026-04-18T10:00:00Z',
            'updatedAt'  => '2026-04-18T10:00:00Z',
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
            'id'            => 'ven_jkl012',
            'name'          => 'Acme',
            'code'          => 'ACME',
            'status'        => 'active',
            'settledAmount' => 0,
            'pendingAmount' => 0,
            'metadata'      => [],
            'createdAt'     => '2026-04-18T09:00:00Z',
            'updatedAt'     => '2026-04-18T09:00:00Z',
        ];
    }

    private function customerPayload(): array
    {
        return [
            'id'               => 'cus_mno345',
            'email'            => 'jane@example.ma',
            'phone'            => '+212600000004',
            'firstName'        => 'Jane',
            'lastName'         => 'Doe',
            'totalSpend'       => 15000,
            'transactionCount' => 3,
            'metadata'         => [],
            'createdAt'        => '2026-04-01T08:00:00Z',
            'updatedAt'        => '2026-04-18T11:00:00Z',
        ];
    }

    private function apiKeyPayload(bool $withSecret = false): array
    {
        return [
            'id'          => 'key_pqr678',
            'name'        => 'Production key',
            'environment' => 'live',
            'prefix'      => 'testPrefix',
            'secretKey'   => $withSecret ? 'sk_live_testPrefix_fullsecret' : null,
            'status'      => 'active',
            'createdAt'   => '2026-04-18T08:00:00Z',
        ];
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
