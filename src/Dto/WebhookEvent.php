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
 * Common event types:
 *  - checkout_session.completed
 *  - checkout_session.expired
 *  - transaction.captured
 *  - transaction.failed
 *  - refund.created
 *  - refund.succeeded
 *  - refund.failed
 *  - payment_link.paid
 */
final readonly class WebhookEvent
{
    public function __construct(
        /** Unique event ID (idempotency key for your handler) */
        public string $id,

        /**
         * Dot-separated event type, e.g. "checkout_session.completed"
         */
        public string $type,

        /**
         * API version used to format this event.
         */
        public string $apiVersion,

        /**
         * Unix timestamp when the event was generated on the server.
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
            type:       (string) ($payload['type']       ?? ''),
            apiVersion: (string) ($payload['apiVersion'] ?? $payload['api_version'] ?? ''),
            createdAt:  (int)    ($payload['createdAt']  ?? $payload['created_at']  ?? time()),
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
        return Transaction::fromArray($this->data['object'] ?? $this->data);
    }

    public function asRefund(): Refund
    {
        return Refund::fromArray($this->data['object'] ?? $this->data);
    }

    public function asPaymentLink(): PaymentLink
    {
        return PaymentLink::fromArray($this->data['object'] ?? $this->data);
    }
}
