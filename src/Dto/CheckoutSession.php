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
         * Possible values: open, awaiting_cash, processing, complete, expired
         */
        public string $status,

        /** When true the customer can enter any amount on the hosted page */
        public bool $openAmount,

        /**
         * ID of the transaction linked to the session (read from the API's `transaction.id`).
         * Only `retrieve()` returns it; null on `create()` and before a payment is started.
         */
        public ?string $transactionId,

        /** @deprecated The API has no session reference; always null. Use metadata. */
        public ?string $reference,

        /** @deprecated The API has no session description; always null. */
        public ?string $description,

        /** URL Aslan redirects to on success (returned by `retrieve()` only) */
        public ?string $successUrl,

        /** URL Aslan redirects to on failure or cancellation (returned by `retrieve()` only) */
        public ?string $cancelUrl,

        /** Arbitrary key-value metadata supplied at creation (returned by `retrieve()` only) */
        public array $metadata,

        /** ISO 8601 expiry timestamp */
        public string $expiresAt,

        /** ISO 8601 creation timestamp */
        public string $createdAt,

        /** @deprecated The API does not return it; empty string. */
        public string $updatedAt,

        /**
         * Status of the linked transaction (e.g. pending, succeeded, failed), from the API's
         * `transaction.status`. Only `retrieve()` returns it.
         */
        public ?string $transactionStatus = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
        $transactionId = $transaction['id'] ?? $data['transactionId'] ?? null;

        return new self(
            id:            (string) $data['id'],
            token:         (string) $data['token'],
            url:           (string) $data['url'],
            amount:        isset($data['amount']) ? (int) $data['amount'] : null,
            currency:      (string) ($data['currency'] ?? 'MAD'),
            status:        (string) ($data['status'] ?? 'open'),
            openAmount:    (bool)   ($data['openAmount'] ?? !isset($data['amount'])),
            transactionId: $transactionId !== null ? (string) $transactionId : null,
            reference:     isset($data['reference'])   ? (string) $data['reference']   : null,
            description:   isset($data['description']) ? (string) $data['description'] : null,
            successUrl:    isset($data['successUrl'])  ? (string) $data['successUrl']  : null,
            cancelUrl:     isset($data['cancelUrl'])   ? (string) $data['cancelUrl']   : null,
            metadata:      (array)  ($data['metadata']  ?? []),
            expiresAt:     (string) ($data['expiresAt'] ?? ''),
            createdAt:     (string) ($data['createdAt'] ?? ''),
            updatedAt:     (string) ($data['updatedAt'] ?? ''),
            transactionStatus: isset($transaction['status']) ? (string) $transaction['status'] : null,
        );
    }

    /** The payment went through (status `complete`). */
    public function isCompleted(): bool
    {
        return $this->status === 'complete';
    }

    /** Not finished yet: `open`, `awaiting_cash` (cash code issued) or `processing`. */
    public function isPending(): bool
    {
        return in_array($this->status, ['open', 'awaiting_cash', 'processing'], true);
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }
}
