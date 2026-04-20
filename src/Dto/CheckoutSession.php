<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a checkout session returned by the Aslan Payment API.
 *
 * Amounts are in centimes (100 = 1.00 MAD).
 */
final readonly class CheckoutSession
{
    public function __construct(
        /** Unique session identifier */
        public string $id,

        /** Short-lived token used to render the hosted checkout page */
        public string $token,

        /** Full URL to redirect the customer to for payment */
        public string $url,

        /** Amount in centimes. Null when openAmount = true */
        public ?int $amount,

        /** ISO 4217 currency code (e.g. "MAD") */
        public string $currency,

        /**
         * Session status.
         * Possible values: pending, completed, expired, cancelled
         */
        public string $status,

        /** When true the customer can enter any amount on the hosted page */
        public bool $openAmount,

        /** Transaction ID populated once payment is completed */
        public ?string $transactionId,

        /** Merchant-supplied reference / order identifier */
        public ?string $reference,

        /** Description shown to the customer */
        public ?string $description,

        /** URL Aslan redirects to on success */
        public ?string $successUrl,

        /** URL Aslan redirects to on failure or cancellation */
        public ?string $cancelUrl,

        /** Arbitrary key-value metadata supplied at creation */
        public array $metadata,

        /** ISO 8601 expiry timestamp */
        public string $expiresAt,

        /** ISO 8601 creation timestamp */
        public string $createdAt,

        /** ISO 8601 last-updated timestamp */
        public string $updatedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:            (string) $data['id'],
            token:         (string) $data['token'],
            url:           (string) $data['url'],
            amount:        isset($data['amount']) ? (int) $data['amount'] : null,
            currency:      (string) ($data['currency'] ?? 'MAD'),
            status:        (string) ($data['status'] ?? 'pending'),
            openAmount:    (bool)   ($data['openAmount']    ?? $data['open_amount']    ?? false),
            transactionId: isset($data['transactionId']) ? (string) $data['transactionId'] : null,
            reference:     isset($data['reference'])     ? (string) $data['reference']     : null,
            description:   isset($data['description'])   ? (string) $data['description']   : null,
            successUrl:    isset($data['successUrl'])     ? (string) $data['successUrl']    : null,
            cancelUrl:     isset($data['cancelUrl'])      ? (string) $data['cancelUrl']     : null,
            metadata:      (array)  ($data['metadata']   ?? []),
            expiresAt:     (string) ($data['expiresAt']  ?? $data['expires_at']  ?? ''),
            createdAt:     (string) ($data['createdAt']  ?? $data['created_at']  ?? ''),
            updatedAt:     (string) ($data['updatedAt']  ?? $data['updated_at']  ?? ''),
        );
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }
}
