<?php

declare(strict_types=1);

namespace Aslan\Payment\Tests;

use Aslan\Payment\Aslan;
use Aslan\Payment\AslanException;
use Aslan\Payment\Dto\BillingAttempt;
use Aslan\Payment\Dto\DocumentData;
use Aslan\Payment\Dto\Invoice;
use Aslan\Payment\Dto\InvoiceLine;
use Aslan\Payment\Dto\PaginatedResponse;
use Aslan\Payment\Dto\Plan;
use Aslan\Payment\Dto\Subscription;
use Aslan\Payment\Dto\SubscriptionCard;
use Aslan\Payment\Dto\SubscriptionCheckout;
use Aslan\Payment\Dto\SubscriptionCustomer;
use Aslan\Payment\Dto\SubscriptionPaymentLink;
use Aslan\Payment\Dto\SubscriptionPlanChange;
use Aslan\Payment\Dto\SubscriptionPriceOverride;
use Aslan\Payment\Dto\SubscriptionStatusChange;
use Aslan\Payment\HttpClient\HttpClientInterface;
use Aslan\Payment\HttpClient\RawHttpClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Billing resources: Plans, Subscriptions, BillingAttempts, Invoices.
 */
final class BillingTest extends TestCase
{
    private const KEY  = 'sk_live_testPrefix_testSecret';
    private const BASE = 'https://api.aslanpay.ma/pay/api/v1/billing';

    private const PLAN_ID = '11111111-1111-4111-8111-111111111111';
    private const SUB_ID  = '22222222-2222-4222-8222-222222222222';
    private const CUS_ID  = '33333333-3333-4333-8333-333333333333';
    private const ATT_ID  = '44444444-4444-4444-8444-444444444444';
    private const INV_ID  = '55555555-5555-4555-8555-555555555555';

    /** @var (HttpClientInterface&RawHttpClientInterface)&MockObject */
    private MockObject $http;
    private Aslan $client;

    protected function setUp(): void
    {
        $this->http = $this->createMockForIntersectionOfInterfaces([
            HttpClientInterface::class,
            RawHttpClientInterface::class,
        ]);
        $this->client = new Aslan(self::KEY, httpClient: $this->http);
    }

    private function expectRequest(string $method, string $url, array $body, array $query, array $response): void
    {
        $this->http
            ->expects(self::once())
            ->method('request')
            ->with($method, $url, $body, [], $query)
            ->willReturn($response);
    }

    // ------------------------------------------------------------------
    // Plans
    // ------------------------------------------------------------------

    public function test_plan_create(): void
    {
        $params = [
            'name'      => 'Pro',
            'amount'    => 9900,
            'currency'  => 'MAD',
            'interval'  => 'monthly',
            'trialDays' => 14,
            'metadata'  => ['tier' => 'pro'],
        ];
        $this->expectRequest('POST', self::BASE . '/plans', $params, [], ['data' => $this->planPayload()]);

        $plan = $this->client->plans->create($params);

        self::assertInstanceOf(Plan::class, $plan);
        self::assertSame(self::PLAN_ID, $plan->id);
        self::assertSame('Pro', $plan->name);
        self::assertSame(9900, $plan->amount);
        self::assertSame('monthly', $plan->interval);
        self::assertSame(1, $plan->intervalCount);
        self::assertSame(14, $plan->trialDays);
        self::assertTrue($plan->hasTrial());
        self::assertTrue($plan->isActive);
        self::assertNull($plan->tvaRateBps);
        self::assertSame(['tier' => 'pro'], $plan->metadata);
    }

    public function test_plan_list_with_filters_serializes_booleans(): void
    {
        $this->expectRequest(
            'GET',
            self::BASE . '/plans',
            [],
            ['page' => 2, 'pageSize' => 10, 'isActive' => 'true'],
            ['data' => [$this->planPayload()], 'meta' => ['total' => 11, 'page' => 2, 'pageSize' => 10]],
        );

        $page = $this->client->plans->list(['page' => 2, 'pageSize' => 10, 'isActive' => true]);

        self::assertInstanceOf(PaginatedResponse::class, $page);
        self::assertInstanceOf(Plan::class, $page->data[0]);
        self::assertSame(11, $page->total);
        // Billing meta has no totalPages — derived from total / pageSize.
        self::assertSame(2, $page->totalPages);
        self::assertFalse($page->hasNextPage());
    }

    public function test_plan_retrieve(): void
    {
        $this->expectRequest('GET', self::BASE . '/plans/' . self::PLAN_ID, [], [], ['data' => $this->planPayload()]);

        self::assertSame(self::PLAN_ID, $this->client->plans->retrieve(self::PLAN_ID)->id);
    }

    public function test_plan_update_archive(): void
    {
        $this->expectRequest(
            'PATCH',
            self::BASE . '/plans/' . self::PLAN_ID,
            ['isActive' => false],
            [],
            ['data' => array_merge($this->planPayload(), ['isActive' => false, 'tvaRateBps' => 2000])],
        );

        $plan = $this->client->plans->update(self::PLAN_ID, ['isActive' => false]);

        self::assertFalse($plan->isActive);
        self::assertSame(2000, $plan->tvaRateBps);
    }

    // ------------------------------------------------------------------
    // Subscriptions
    // ------------------------------------------------------------------

    public function test_subscription_create_returns_paywall(): void
    {
        $params = [
            'planId'              => self::PLAN_ID,
            'customerId'          => self::CUS_ID,
            'returnUrl'           => 'https://shop.ma/ok',
            'cancelUrl'           => 'https://shop.ma/ko',
            'callbackUrl'         => 'https://shop.ma/cb',
            'priceOverride'       => 7900,
            'priceOverrideMonths' => 3,
            'metadata'            => ['invoice_id' => 'INV-1'],
        ];
        $this->expectRequest('POST', self::BASE . '/subscriptions', $params, [], ['data' => [
            'subscriptionId' => self::SUB_ID,
            'transactionId'  => 'txn-1',
            'redirectUrl'    => 'https://paywall.example/pay',
            'paywall'        => ['payload' => '{"a":"<b>"}', 'signature' => 'abc123'],
        ]]);

        $checkout = $this->client->subscriptions->create($params);

        self::assertInstanceOf(SubscriptionCheckout::class, $checkout);
        self::assertSame(self::SUB_ID, $checkout->subscriptionId);
        self::assertSame('txn-1', $checkout->transactionId);
        self::assertSame('https://paywall.example/pay', $checkout->redirectUrl);
        self::assertSame('abc123', $checkout->paywall['signature']);

        $html = $checkout->autoSubmitFormHtml();
        self::assertStringContainsString('method="post" action="https://paywall.example/pay"', $html);
        self::assertStringContainsString('name="payload" value="{&quot;a&quot;:&quot;&lt;b&gt;&quot;}"', $html);
        self::assertStringContainsString('name="signature" value="abc123"', $html);
    }

    public function test_subscription_list_with_filters(): void
    {
        $query = ['status' => 'past_due', 'customerId' => self::CUS_ID, 'planId' => self::PLAN_ID];
        $item  = $this->subscriptionPayload();
        unset($item['customer'], $item['card']); // list omits these

        $this->expectRequest('GET', self::BASE . '/subscriptions', [], $query, [
            'data' => [$item],
            'meta' => ['total' => 1, 'page' => 1, 'pageSize' => 20],
        ]);

        $page = $this->client->subscriptions->list($query);

        self::assertCount(1, $page->data);
        self::assertInstanceOf(Subscription::class, $page->data[0]);
        self::assertNull($page->data[0]->customer);
        self::assertNull($page->data[0]->card);
        self::assertInstanceOf(Plan::class, $page->data[0]->plan);
    }

    public function test_subscription_retrieve_full(): void
    {
        $this->expectRequest('GET', self::BASE . '/subscriptions/' . self::SUB_ID, [], [], [
            'data' => $this->subscriptionPayload(),
        ]);

        $sub = $this->client->subscriptions->retrieve(self::SUB_ID);

        self::assertSame(self::SUB_ID, $sub->id);
        self::assertSame('active', $sub->status);
        self::assertTrue($sub->isActive());
        self::assertFalse($sub->isCancelled());
        self::assertSame(self::CUS_ID, $sub->customerId);
        self::assertInstanceOf(SubscriptionCustomer::class, $sub->customer);
        self::assertSame('jane@example.ma', $sub->customer->email);
        self::assertNull($sub->customer->phone);
        self::assertSame(self::PLAN_ID, $sub->plan?->id);
        self::assertInstanceOf(SubscriptionCard::class, $sub->card);
        self::assertSame('VISA', $sub->card->brand);
        self::assertNull($sub->card->last4);
        self::assertNull($sub->card->expiryMonth);
        self::assertSame(2028, $sub->card->expiryYear);
        self::assertSame(0, $sub->failureCount);
        self::assertSame('2026-11-01T00:00:00.000Z', $sub->currentPeriodEnd);
        self::assertNull($sub->trialEndsAt);
        self::assertNull($sub->cancelledAt);
        self::assertNull($sub->priceOverride);
        self::assertNull($sub->priceOverrideUntil);
        self::assertSame(['invoice_id' => 'INV-1'], $sub->metadata);
    }

    public function test_subscription_nullable_fields_and_unknown_keys(): void
    {
        $sub = Subscription::fromArray([
            'id'                 => self::SUB_ID,
            'customerId'         => self::CUS_ID,
            'customer'           => null,
            'planId'             => self::PLAN_ID,
            'providerId'         => null,
            'status'             => 'pending_initial',
            'currentPeriodStart' => null,
            'currentPeriodEnd'   => null,
            'nextBillingAt'      => null,
            'card'               => null,
            'priceOverride'      => 5000,
            'priceOverrideUntil' => '2027-01-01T00:00:00.000Z',
            'someFutureField'    => ['x' => 1],
        ]);

        self::assertNull($sub->customer);
        self::assertNull($sub->providerId);
        self::assertNull($sub->plan);
        self::assertNull($sub->card);
        self::assertNull($sub->currentPeriodStart);
        self::assertSame(5000, $sub->priceOverride);
        self::assertSame('2027-01-01T00:00:00.000Z', $sub->priceOverrideUntil);
        self::assertFalse($sub->isActive());
        self::assertFalse($sub->cancelAtPeriodEnd);
    }

    public function test_subscription_update_price_override(): void
    {
        $this->expectRequest('PATCH', self::BASE . '/subscriptions/' . self::SUB_ID, ['priceOverride' => null], [], [
            'data' => ['id' => self::SUB_ID, 'priceOverride' => null, 'priceOverrideUntil' => null],
        ]);

        $result = $this->client->subscriptions->update(self::SUB_ID, ['priceOverride' => null]);

        self::assertInstanceOf(SubscriptionPriceOverride::class, $result);
        self::assertNull($result->priceOverride);
        self::assertNull($result->priceOverrideUntil);
    }

    public function test_subscription_change_plan_with_proration(): void
    {
        $newPlan = '66666666-6666-4666-8666-666666666666';
        $this->expectRequest(
            'POST',
            self::BASE . '/subscriptions/' . self::SUB_ID . '/change-plan',
            ['planId' => $newPlan, 'when' => 'immediate_proration', 'allowDowngrade' => false],
            [],
            ['data' => [
                'subscriptionId'        => self::SUB_ID,
                'planId'                => $newPlan,
                'previousPlanId'        => self::PLAN_ID,
                'when'                  => 'immediate_proration',
                'proratedTransactionId' => 'txn-pro',
                'proratedAmount'        => 3000,
                'proratedCurrency'      => 'MAD',
            ]],
        );

        $change = $this->client->subscriptions->changePlan(self::SUB_ID, $newPlan, 'immediate_proration');

        self::assertInstanceOf(SubscriptionPlanChange::class, $change);
        self::assertSame(self::PLAN_ID, $change->previousPlanId);
        self::assertSame('txn-pro', $change->proratedTransactionId);
        self::assertSame(3000, $change->proratedAmount);
        self::assertSame('MAD', $change->proratedCurrency);
    }

    public function test_subscription_change_plan_defaults_without_proration(): void
    {
        $this->expectRequest(
            'POST',
            self::BASE . '/subscriptions/' . self::SUB_ID . '/change-plan',
            ['planId' => self::PLAN_ID, 'when' => 'immediate', 'allowDowngrade' => true],
            [],
            ['data' => ['subscriptionId' => self::SUB_ID, 'planId' => self::PLAN_ID, 'previousPlanId' => 'p0', 'when' => 'immediate']],
        );

        $change = $this->client->subscriptions->changePlan(self::SUB_ID, self::PLAN_ID, allowDowngrade: true);

        self::assertSame('immediate', $change->when);
        self::assertNull($change->proratedTransactionId);
        self::assertNull($change->proratedAmount);
        self::assertNull($change->proratedCurrency);
    }

    public function test_subscription_cancel_immediately(): void
    {
        $this->expectRequest('POST', self::BASE . '/subscriptions/' . self::SUB_ID . '/cancel', ['atPeriodEnd' => false], [], [
            'data' => ['subscriptionId' => self::SUB_ID, 'status' => 'cancelled'],
        ]);

        $result = $this->client->subscriptions->cancel(self::SUB_ID);

        self::assertInstanceOf(SubscriptionStatusChange::class, $result);
        self::assertSame('cancelled', $result->status);
        self::assertFalse($result->cancelAtPeriodEnd);
    }

    public function test_subscription_cancel_at_period_end(): void
    {
        $this->expectRequest('POST', self::BASE . '/subscriptions/' . self::SUB_ID . '/cancel', ['atPeriodEnd' => true], [], [
            'data' => ['subscriptionId' => self::SUB_ID, 'status' => 'active', 'cancelAtPeriodEnd' => true],
        ]);

        $result = $this->client->subscriptions->cancel(self::SUB_ID, atPeriodEnd: true);

        self::assertSame('active', $result->status);
        self::assertTrue($result->cancelAtPeriodEnd);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodylessActions(): iterable
    {
        yield 'pause'    => ['pause', 'paused'];
        yield 'resume'   => ['resume', 'resumed'];
        yield 'uncancel' => ['uncancel', 'active'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bodylessActions')]
    public function test_subscription_bodyless_actions(string $action, string $status): void
    {
        $this->expectRequest('POST', self::BASE . '/subscriptions/' . self::SUB_ID . '/' . $action, [], [], [
            'data' => ['subscriptionId' => self::SUB_ID, 'status' => $status, 'cancelAtPeriodEnd' => false],
        ]);

        $result = $this->client->subscriptions->{$action}(self::SUB_ID);

        self::assertSame(self::SUB_ID, $result->subscriptionId);
        self::assertSame($status, $result->status);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function linkActions(): iterable
    {
        yield 'changeCard' => ['changeCard', 'change-card'];
        yield 'repay'      => ['repay', 'repay'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('linkActions')]
    public function test_subscription_payment_link_actions(string $method, string $segment): void
    {
        $params = [
            'returnUrl'   => 'https://shop.ma/ok',
            'cancelUrl'   => 'https://shop.ma/ko',
            'callbackUrl' => 'https://shop.ma/cb',
        ];
        $this->expectRequest('POST', self::BASE . '/subscriptions/' . self::SUB_ID . '/' . $segment, $params, [], [
            'data' => ['subscriptionId' => self::SUB_ID, 'paymentLinkId' => 'pl_1', 'redirectUrl' => 'https://pay.aslanpay.ma/pl/abc'],
        ]);

        $link = $this->client->subscriptions->{$method}(self::SUB_ID, $params);

        self::assertInstanceOf(SubscriptionPaymentLink::class, $link);
        self::assertSame('pl_1', $link->paymentLinkId);
        self::assertSame('https://pay.aslanpay.ma/pl/abc', $link->redirectUrl);
    }

    // ------------------------------------------------------------------
    // Billing attempts
    // ------------------------------------------------------------------

    public function test_attempts_list_with_filters(): void
    {
        $query = ['subscriptionId' => self::SUB_ID, 'status' => 'failed', 'type' => 'renewal'];
        $this->expectRequest('GET', self::BASE . '/attempts', [], $query, [
            'data' => [$this->attemptPayload()],
            'meta' => ['total' => 1, 'page' => 1, 'pageSize' => 20],
        ]);

        $page    = $this->client->billingAttempts->list($query);
        $attempt = $page->data[0];

        self::assertInstanceOf(BillingAttempt::class, $attempt);
        self::assertSame(self::ATT_ID, $attempt->id);
        self::assertSame('renewal', $attempt->type);
        self::assertTrue($attempt->isFailed());
        self::assertSame(9900, $attempt->amount);
        self::assertSame(2, $attempt->attemptNumber);
        self::assertSame('card_declined', $attempt->failureReason);
        self::assertNull($attempt->transactionId);
        self::assertNull($attempt->providerRef);
    }

    public function test_attempt_receipt(): void
    {
        $this->expectRequest('GET', self::BASE . '/attempts/' . self::ATT_ID . '/receipt', [], [], [
            'data' => [
                'issuer'   => ['legalName' => 'Test Merchant SARL', 'ice' => null],
                'customer' => ['name' => 'Jane', 'email' => null],
                'lines'    => [['description' => 'Abonnement Pro', 'qty' => 1, 'unitHt' => 8250, 'tvaRateBps' => 2000, 'totalTtc' => 9900]],
                'currency' => 'MAD',
                'totalHt'  => 8250,
                'totalTva' => 1650,
                'totalTtc' => 9900,
                'issuedAt' => '2026-10-01T00:00:00.000Z',
            ],
        ]);

        $doc = $this->client->billingAttempts->receipt(self::ATT_ID);

        self::assertInstanceOf(DocumentData::class, $doc);
        self::assertSame('Test Merchant SARL', $doc->issuer['legalName']);
        self::assertNull($doc->issuer['ice']);
        self::assertInstanceOf(InvoiceLine::class, $doc->lines[0]);
        self::assertSame(2000, $doc->lines[0]->tvaRateBps);
        self::assertSame(9900, $doc->totalTtc);
    }

    public function test_attempt_receipt_pdf_returns_raw_bytes(): void
    {
        $pdf = "%PDF-1.7\n\x00\x01binary";
        $this->http
            ->expects(self::once())
            ->method('requestRaw')
            ->with('GET', self::BASE . '/attempts/' . self::ATT_ID . '/receipt.pdf', ['Accept' => 'application/pdf'], [])
            ->willReturn($pdf);
        $this->http->expects(self::never())->method('request');

        self::assertSame($pdf, $this->client->billingAttempts->receiptPdf(self::ATT_ID));
    }

    public function test_attempt_facture(): void
    {
        $this->expectRequest('POST', self::BASE . '/attempts/' . self::ATT_ID . '/facture', [], [], [
            'data' => $this->invoicePayload(),
        ]);

        $invoice = $this->client->billingAttempts->facture(self::ATT_ID);

        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame('FAC-2026-00001', $invoice->number);
        self::assertSame(self::ATT_ID, $invoice->billingAttemptId);
    }

    public function test_raw_download_requires_raw_capable_client(): void
    {
        $plain  = $this->createMock(HttpClientInterface::class);
        $client = new Aslan(self::KEY, httpClient: $plain);

        try {
            $client->invoices->pdf(self::INV_ID);
            self::fail('Expected AslanException');
        } catch (AslanException $e) {
            self::assertSame('UNSUPPORTED_OPERATION', $e->errorCode);
        }
    }

    // ------------------------------------------------------------------
    // Invoices
    // ------------------------------------------------------------------

    public function test_invoices_list_with_filters(): void
    {
        $this->expectRequest('GET', self::BASE . '/invoices', [], ['kind' => 'avoir', 'year' => 2026], [
            'data' => [array_merge($this->invoicePayload(), ['kind' => 'avoir', 'billingAttemptId' => null])],
            'meta' => ['page' => 1, 'pageSize' => 20, 'total' => 1, 'totalPages' => 1],
        ]);

        $page = $this->client->invoices->list(['kind' => 'avoir', 'year' => 2026]);

        self::assertSame('avoir', $page->data[0]->kind);
        self::assertNull($page->data[0]->billingAttemptId);
    }

    public function test_invoice_retrieve(): void
    {
        $this->expectRequest('GET', self::BASE . '/invoices/' . self::INV_ID, [], [], ['data' => $this->invoicePayload()]);

        $invoice = $this->client->invoices->retrieve(self::INV_ID);

        self::assertSame(self::INV_ID, $invoice->id);
        self::assertSame('issued', $invoice->status);
        self::assertSame(2026, $invoice->year);
        self::assertSame(1650, $invoice->totalTva);
        self::assertSame('Test Merchant SARL', $invoice->issuerSnapshot['legalName']);
        self::assertCount(1, $invoice->lines);
        self::assertSame(8250, $invoice->lines[0]->unitHt);
    }

    public function test_invoice_pdf_returns_raw_bytes(): void
    {
        $this->http
            ->expects(self::once())
            ->method('requestRaw')
            ->with('GET', self::BASE . '/invoices/' . self::INV_ID . '/facture.pdf', ['Accept' => 'application/pdf'], [])
            ->willReturn('%PDF-1.7 facture');

        self::assertSame('%PDF-1.7 facture', $this->client->invoices->pdf(self::INV_ID));
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function planPayload(): array
    {
        return [
            'id'            => self::PLAN_ID,
            'merchantId'    => 'm-1',
            'name'          => 'Pro',
            'amount'        => 9900,
            'currency'      => 'MAD',
            'interval'      => 'monthly',
            'intervalCount' => 1,
            'trialDays'     => 14,
            'isActive'      => true,
            'metadata'      => ['tier' => 'pro'],
            'tvaRateBps'    => null,
            'createdAt'     => '2026-10-01T00:00:00.000Z',
            'updatedAt'     => '2026-10-01T00:00:00.000Z',
        ];
    }

    private function subscriptionPayload(): array
    {
        return [
            'id'                 => self::SUB_ID,
            'customerId'         => self::CUS_ID,
            'customer'           => ['id' => self::CUS_ID, 'email' => 'jane@example.ma', 'name' => 'Jane', 'phone' => null],
            'planId'             => self::PLAN_ID,
            'plan'               => $this->planPayload(),
            'providerId'         => 'prov-1',
            'status'             => 'active',
            'failureCount'       => 0,
            'currentPeriodStart' => '2026-10-01T00:00:00.000Z',
            'currentPeriodEnd'   => '2026-11-01T00:00:00.000Z',
            'nextBillingAt'      => '2026-11-01T00:00:00.000Z',
            'trialEndsAt'        => null,
            'cancelAtPeriodEnd'  => false,
            'cancelledAt'        => null,
            'priceOverride'      => null,
            'priceOverrideUntil' => null,
            'metadata'           => ['invoice_id' => 'INV-1'],
            'createdAt'          => '2026-10-01T00:00:00.000Z',
            'updatedAt'          => '2026-10-01T00:00:00.000Z',
            'card'               => ['brand' => 'VISA', 'last4' => null, 'expiryMonth' => null, 'expiryYear' => 2028],
        ];
    }

    private function attemptPayload(): array
    {
        return [
            'id'                 => self::ATT_ID,
            'subscriptionId'     => self::SUB_ID,
            'merchantId'         => 'm-1',
            'transactionId'      => null,
            'type'               => 'renewal',
            'status'             => 'failed',
            'amount'             => 9900,
            'currency'           => 'MAD',
            'providerRef'        => null,
            'failureReason'      => 'card_declined',
            'attemptNumber'      => 2,
            'billingPeriodStart' => '2026-10-01T00:00:00.000Z',
            'billingPeriodEnd'   => '2026-11-01T00:00:00.000Z',
            'createdAt'          => '2026-10-01T00:00:00.000Z',
            'updatedAt'          => '2026-10-01T00:00:00.000Z',
        ];
    }

    private function invoicePayload(): array
    {
        return [
            'id'               => self::INV_ID,
            'merchantId'       => 'm-1',
            'billingAttemptId' => self::ATT_ID,
            'kind'             => 'facture',
            'number'           => 'FAC-2026-00001',
            'year'             => 2026,
            'status'           => 'issued',
            'issuerSnapshot'   => ['legalName' => 'Test Merchant SARL'],
            'customerSnapshot' => ['name' => 'Jane'],
            'lines'            => [['description' => 'Abonnement Pro', 'qty' => 1, 'unitHt' => 8250, 'tvaRateBps' => 2000, 'totalTtc' => 9900]],
            'currency'         => 'MAD',
            'totalHt'          => 8250,
            'totalTva'         => 1650,
            'totalTtc'         => 9900,
            'issuedAt'         => '2026-10-01T00:00:00.000Z',
        ];
    }
}
