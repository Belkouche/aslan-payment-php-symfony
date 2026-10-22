<?php

declare(strict_types=1);

namespace Aslan\Payment\Tests;

use Aslan\Payment\Aslan;
use Aslan\Payment\AslanException;
use Aslan\Payment\Dto\Subscription;
use Aslan\Payment\Resources\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the API's outbound scheme exactly:
 *   X-Webhook-Signature: t=<unix_seconds>,v1=<hex(HMAC-SHA256("<t>.<rawBody>", secret))>
 * with the event type in the body's `event` field.
 */
final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_merchant_secret';

    /** Sign exactly like the API does, independently of the SDK helper. */
    private static function sign(string $rawBody, int $t, string $secret = self::SECRET): string
    {
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $rawBody, $secret);
    }

    private static function renewedBody(): string
    {
        return json_encode([
            'id'        => 'evt_01HXY_sr001',
            'event'     => 'subscription.renewed',
            'createdAt' => '2026-10-22T10:00:00.000Z',
            'data'      => [
                'subscription' => [
                    'id'                 => 'sub-1',
                    'planId'             => 'plan-1',
                    'currentPeriodStart' => '2026-10-22T10:00:00.000Z',
                    'currentPeriodEnd'   => '2026-11-22T10:00:00.000Z',
                    'nextBillingAt'      => '2026-11-22T10:00:00.000Z',
                    'metadata'           => ['invoice_id' => 'INV-42'],
                ],
                'transaction' => ['id' => 'txn-9', 'amount' => 9900, 'currency' => 'MAD', 'providerRef' => 'ref-9'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function test_header_constant_matches_api(): void
    {
        self::assertSame('X-Webhook-Signature', Webhooks::SIGNATURE_HEADER);
    }

    public function test_valid_signature_parses_subscription_renewed(): void
    {
        $body  = self::renewedBody();
        $event = Aslan::webhooks(self::SECRET)->constructEvent($body, self::sign($body, time()));

        self::assertSame('evt_01HXY_sr001', $event->id);
        self::assertSame('subscription.renewed', $event->type);
        self::assertTrue($event->isSubscriptionEvent());
        self::assertSame(strtotime('2026-10-22T10:00:00.000Z'), $event->createdAt);

        $sub = $event->asSubscription();
        self::assertInstanceOf(Subscription::class, $sub);
        self::assertSame('sub-1', $sub->id);
        self::assertSame('plan-1', $sub->planId);
        self::assertSame('2026-11-22T10:00:00.000Z', $sub->nextBillingAt);
        self::assertSame(['invoice_id' => 'INV-42'], $sub->metadata);
        self::assertNull($sub->status);      // not present in the event payload
        self::assertNull($sub->customerId);

        self::assertSame(9900, $event->data['transaction']['amount']);
        self::assertSame('txn-9', $event->asTransaction()->id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function subscriptionEvents(): iterable
    {
        foreach ([
            'subscription.renewed',
            'subscription.payment_failed',
            'subscription.past_due',
            'subscription.cancelled',
            'subscription.plan_changed',
            'subscription.plan_change_failed',
        ] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('subscriptionEvents')]
    public function test_every_subscription_event_type_parses(string $type): void
    {
        $body = json_encode([
            'id'        => 'evt_x',
            'event'     => $type,
            'createdAt' => '2026-10-22T10:00:00.000Z',
            'data'      => ['subscription' => ['id' => 'sub-1', 'status' => 'past_due']],
        ], JSON_THROW_ON_ERROR);

        $event = Aslan::webhooks(self::SECRET)->constructEvent($body, self::sign($body, time()));

        self::assertSame($type, $event->type);
        self::assertSame('sub-1', $event->asSubscription()->id);
    }

    public function test_sdk_generated_header_matches_api_format(): void
    {
        $body = self::renewedBody();
        $t    = 1_761_127_200;

        self::assertSame(self::sign($body, $t), Aslan::webhooks(self::SECRET)->generateSignatureHeader($body, $t));
    }

    public function test_tampered_body_is_rejected(): void
    {
        $body     = self::renewedBody();
        $header   = self::sign($body, time());
        $tampered = str_replace('9900', '1', $body);

        try {
            Aslan::webhooks(self::SECRET)->constructEvent($tampered, $header);
            self::fail('Expected AslanException');
        } catch (AslanException $e) {
            self::assertSame('WEBHOOK_SIGNATURE_ERROR', $e->errorCode);
            self::assertStringContainsString('signature mismatch', $e->getMessage());
        }
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $body = self::renewedBody();

        $this->expectException(AslanException::class);
        $this->expectExceptionMessage('signature mismatch');

        Aslan::webhooks(self::SECRET)->constructEvent($body, self::sign($body, time(), 'whsec_other'));
    }

    public function test_tampered_timestamp_is_rejected(): void
    {
        $body = self::renewedBody();
        $t    = time();
        // Signature computed for t, header claims t-1 (still within tolerance).
        $header = 't=' . ($t - 1) . ',v1=' . hash_hmac('sha256', $t . '.' . $body, self::SECRET);

        $this->expectException(AslanException::class);
        $this->expectExceptionMessage('signature mismatch');

        Aslan::webhooks(self::SECRET)->constructEvent($body, $header);
    }

    public function test_expired_timestamp_is_rejected(): void
    {
        $body = self::renewedBody();

        $this->expectException(AslanException::class);
        $this->expectExceptionMessage('too old');

        Aslan::webhooks(self::SECRET)->constructEvent($body, self::sign($body, time() - 301), tolerance: 300);
    }

    public function test_future_timestamp_beyond_tolerance_is_rejected(): void
    {
        $body = self::renewedBody();

        $this->expectException(AslanException::class);

        Aslan::webhooks(self::SECRET)->constructEvent($body, self::sign($body, time() + 3600));
    }

    public function test_missing_v1_is_rejected(): void
    {
        $this->expectException(AslanException::class);
        $this->expectExceptionMessage('No v1 signatures');

        Aslan::webhooks(self::SECRET)->constructEvent('{}', 't=' . time());
    }

    public function test_missing_timestamp_is_rejected(): void
    {
        $this->expectException(AslanException::class);
        $this->expectExceptionMessage('missing the timestamp');

        Aslan::webhooks(self::SECRET)->constructEvent('{}', 'v1=abc');
    }
}
