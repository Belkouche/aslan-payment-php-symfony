<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a verified, parsed webhook event payload.
 *
 * After calling Aslan::webhooks($secret)->constructEvent(…) this is the
 * object you work with. The $data property contains the raw event data
 * exactly as delivered by the API.
 *
 * Event types (delivered in the body's `event` field):
 *  - payment.succeeded, payment.failed, payment.expired,
 *    payment.pending_cash, payment.cash_late_confirmation
 *  - refund.created, refund.updated
 *  - subscription.renewed, subscription.payment_failed, subscription.past_due,
 *    subscription.cancelled, subscription.plan_changed,
 *    subscription.plan_change_failed
 *
 * `subscription.*` events carry `{ subscription, transaction? }` in $data —
 * use asSubscription() and $data['transaction'].
 */
final readonly class WebhookEvent
{
    public function __construct(
        /** Unique event ID (idempotency key for your handler) */
        public string $id,

        /**
         * Dot-separated event type, e.g. "subscription.renewed"
         * (the payload's `event` field).
         */
        public string $type,

        /**
         * API version used to format this event.
         */
        public string $apiVersion,

        /**
         * Unix timestamp when the event was generated on the server
         * (parsed from the payload's ISO 8601 `createdAt`).
         */
        public int $createdAt,

        /**
         * The primary resource embedded in the event.
         * Raw array — cast to the relevant DTO as needed.
         * @var array<string, mixed>
         */
        public array $data,

        /**
         * The full original payload array (for advanced use / debugging).
         * @var array<string, mixed>
         */
        public array $rawPayload,
    ) {}

    /**
     * Build from a fully verified, decoded payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            id:         (string) ($payload['id']         ?? ''),
            type:       (string) ($payload['event']      ?? $payload['type']        ?? ''),
            apiVersion: (string) ($payload['apiVersion'] ?? $payload['api_version'] ?? ''),
            createdAt:  self::parseTimestamp($payload['createdAt'] ?? $payload['created_at'] ?? null),
            data:       (array)  ($payload['data']       ?? []),
            rawPayload: $payload,
        );
    }

    // ------------------------------------------------------------------
    // Convenience helpers to hydrate the embedded resource as a typed DTO
    // ------------------------------------------------------------------

    public function asCheckoutSession(): CheckoutSession
    {
        return CheckoutSession::fromArray($this->data['object'] ?? $this->data);
    }

    public function asTransaction(): Transaction
    {
        return Transaction::fromArray($this->data['transaction'] ?? $this->data['object'] ?? $this->data);
    }

    public function asRefund(): Refund
    {
        return Refund::fromArray($this->data['object'] ?? $this->data);
    }

    public function asPaymentLink(): PaymentLink
    {
        return PaymentLink::fromArray($this->data['object'] ?? $this->data);
    }

    /** True for subscription.* events. */
    public function isSubscriptionEvent(): bool
    {
        return str_starts_with($this->type, 'subscription.');
    }

    /**
     * The (partial) subscription carried by a subscription.* event.
     * Fields not present in the event payload are null / defaults.
     */
    public function asSubscription(): Subscription
    {
        return Subscription::fromArray($this->data['subscription'] ?? $this->data['object'] ?? $this->data);
    }

    /**
     * Accepts a unix timestamp or an ISO 8601 string; falls back to now.
     */
    private static function parseTimestamp(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        if (is_string($value)) {
            $ts = strtotime($value);
            if ($ts !== false) {
                return $ts;
            }
        }

        return time();
    }
}
