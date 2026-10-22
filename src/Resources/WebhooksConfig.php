<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\WebhookConfig;

/**
 * Webhook Configuration resource.
 *
 * Configure the HTTPS endpoint that Aslan delivers event notifications to.
 * Only one endpoint per merchant is supported.
 *
 * The signing secret is returned ONLY at creation or rotation time — save it
 * immediately and use it with Aslan::webhooks($secret)->constructEvent(…).
 *
 * @see https://docs.aslanpay.ma/api/webhooks
 */
final class WebhooksConfig
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * Create or replace the webhook configuration.
     *
     * Required params:
     *  - url (string) — your HTTPS endpoint
     *
     * Optional params:
     *  - events (string[]) — event types to subscribe to (empty = all events)
     *    Common values:
     *      payment.succeeded, payment.failed, payment.expired,
     *      payment.pending_cash, payment.cash_late_confirmation,
     *      refund.created, refund.updated,
     *      subscription.renewed, subscription.payment_failed,
     *      subscription.past_due, subscription.cancelled,
     *      subscription.plan_changed, subscription.plan_change_failed
     *  - apiVersion (string) — pin the event payload format
     *
     * @param  array<string, mixed> $params
     * @param  string|null          $idempotencyKey
     */
    public function create(array $params, ?string $idempotencyKey = null): WebhookConfig
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->client->request('POST', '/api/v1/webhooks/config', $params, $headers);

        return WebhookConfig::fromArray($response['data'] ?? $response);
    }

    /**
     * Retrieve the current webhook configuration.
     */
    public function retrieve(): WebhookConfig
    {
        $response = $this->client->request('GET', '/api/v1/webhooks/config');

        return WebhookConfig::fromArray($response['data'] ?? $response);
    }
}
