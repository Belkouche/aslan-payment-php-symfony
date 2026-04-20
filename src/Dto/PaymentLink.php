<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a reusable payment link.
 *
 * Amounts are in centimes (100 = 1.00 MAD).
 * A payment link can be used multiple times until disabled or expired.
 */
final readonly class PaymentLink
{
    public function __construct(
        public string  $id,

        /** Human-readable title shown on the hosted page */
        public string  $title,

        /** Full short URL to share with customers */
        public string  $url,

        /** Slug used in the URL path */
        public string  $slug,

        /**
         * Link status.
         * Possible values: active, inactive, expired
         */
        public string  $status,

        /** Fixed amount in centimes, or null for open-amount links */
        public ?int    $amount,

        public string  $currency,

        /** When true the customer can enter any amount */
        public bool    $openAmount,

        public ?string $description,

        /** Maximum number of times this link can be paid (null = unlimited) */
        public ?int    $maxUses,

        /** Number of successful payments recorded on this link */
        public int     $usageCount,

        /** URL Aslan redirects to on success */
        public ?string $successUrl,

        /** URL Aslan redirects to on failure or cancellation */
        public ?string $cancelUrl,

        /** ISO 8601 expiry timestamp, or null if it never expires */
        public ?string $expiresAt,

        public array   $metadata,

        public string  $createdAt,
        public string  $updatedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:          (string) $data['id'],
            title:       (string) ($data['title']   ?? ''),
            url:         (string) ($data['url']     ?? ''),
            slug:        (string) ($data['slug']    ?? ''),
            status:      (string) ($data['status']  ?? 'active'),
            amount:      isset($data['amount'])     ? (int) $data['amount']      : null,
            currency:    (string) ($data['currency'] ?? 'MAD'),
            openAmount:  (bool)   ($data['openAmount'] ?? $data['open_amount'] ?? false),
            description: isset($data['description']) ? (string) $data['description'] : null,
            maxUses:     isset($data['maxUses'])      ? (int)    $data['maxUses']     : null,
            usageCount:  (int)   ($data['usageCount'] ?? 0),
            successUrl:  isset($data['successUrl'])   ? (string) $data['successUrl']  : null,
            cancelUrl:   isset($data['cancelUrl'])     ? (string) $data['cancelUrl']   : null,
            expiresAt:   isset($data['expiresAt'])     ? (string) $data['expiresAt']   : null,
            metadata:    (array) ($data['metadata']    ?? []),
            createdAt:   (string) ($data['createdAt']  ?? $data['created_at'] ?? ''),
            updatedAt:   (string) ($data['updatedAt']  ?? $data['updated_at'] ?? ''),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasReachedMaxUses(): bool
    {
        return $this->maxUses !== null && $this->usageCount >= $this->maxUses;
    }
}
