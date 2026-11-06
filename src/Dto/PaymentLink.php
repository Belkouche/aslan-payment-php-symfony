<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a payment link.
 *
 * Amounts are in centimes (100 = 1.00 MAD). A link is paid at most once: after a successful
 * payment the API sets its status to `finalized` and refuses further payments.
 */
final readonly class PaymentLink
{
    public function __construct(
        public string  $id,

        /** @deprecated The API has no title; holds `description` (or ''). */
        public string  $title,

        /** Full URL to share with customers (https://<domain>/pl/<shortCode>) */
        public string  $url,

        /** @deprecated The API has no slug; holds `shortCode`. */
        public string  $slug,

        /**
         * Link status.
         * Possible values: active, inactive, expired, finalized (paid)
         */
        public string  $status,

        /** Fixed amount in centimes, or null for open-amount links */
        public ?int    $amount,

        public string  $currency,

        /** True when `amount` is null (the payer chooses the amount) */
        public bool    $openAmount,

        public ?string $description,

        /** @deprecated Use $maxPayments. */
        public ?int    $maxUses,

        /** @deprecated Use $paymentCount. */
        public int     $usageCount,

        /** @deprecated The API does not return it; always null. */
        public ?string $successUrl,

        /** @deprecated The API does not return it; always null. */
        public ?string $cancelUrl,

        /** ISO 8601 expiry timestamp, or null if it never expires */
        public ?string $expiresAt,

        public array   $metadata,

        public string  $createdAt,
        public string  $updatedAt,

        /** Short code used in the URL path */
        public string  $shortCode = '',

        /** Maximum number of payment attempts the link accepts (null = unlimited) */
        public ?int    $maxPayments = null,

        /** Number of payment attempts started from this link (counted by the API) */
        public int     $paymentCount = 0,

        /** Minimum amount in centimes for open-amount links */
        public ?int    $minAmount = null,

        /** Maximum amount in centimes for open-amount links */
        public ?int    $maxAmount = null,

        /** payment (yours), or change_card / repay (links minted for a subscription) */
        public string  $purpose = 'payment',

        public ?string $vendorId = null,

        /**
         * `default`, or the alias of the PSP account used for this link's payments.
         * Null when the API does not return it (e.g. a webhook payload where it could not be determined).
         */
        public ?string $providerAccount = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $shortCode    = (string) ($data['shortCode'] ?? $data['slug'] ?? '');
        $maxPayments  = $data['maxPayments'] ?? $data['maxUses'] ?? null;
        $paymentCount = (int) ($data['paymentCount'] ?? $data['usageCount'] ?? 0);
        $description  = isset($data['description']) ? (string) $data['description'] : null;

        return new self(
            id:           (string) $data['id'],
            title:        (string) ($data['title'] ?? $description ?? ''),
            url:          (string) ($data['url']     ?? ''),
            slug:         $shortCode,
            status:       (string) ($data['status']  ?? 'active'),
            amount:       isset($data['amount'])     ? (int) $data['amount'] : null,
            currency:     (string) ($data['currency'] ?? 'MAD'),
            openAmount:   (bool)   ($data['openAmount'] ?? !isset($data['amount'])),
            description:  $description,
            maxUses:      $maxPayments !== null ? (int) $maxPayments : null,
            usageCount:   $paymentCount,
            successUrl:   isset($data['successUrl']) ? (string) $data['successUrl'] : null,
            cancelUrl:    isset($data['cancelUrl'])  ? (string) $data['cancelUrl']  : null,
            expiresAt:    isset($data['expiresAt'])  ? (string) $data['expiresAt']  : null,
            metadata:     (array) ($data['metadata']   ?? []),
            createdAt:    (string) ($data['createdAt'] ?? ''),
            updatedAt:    (string) ($data['updatedAt'] ?? ''),
            shortCode:    $shortCode,
            maxPayments:  $maxPayments !== null ? (int) $maxPayments : null,
            paymentCount: $paymentCount,
            minAmount:    isset($data['minAmount']) ? (int) $data['minAmount'] : null,
            maxAmount:    isset($data['maxAmount']) ? (int) $data['maxAmount'] : null,
            purpose:      (string) ($data['purpose'] ?? 'payment'),
            vendorId:     isset($data['vendorId']) ? (string) $data['vendorId'] : null,
            providerAccount: isset($data['providerAccount']) ? (string) $data['providerAccount'] : null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** The link has been paid (status `finalized`). */
    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    /** True when `paymentCount` has reached `maxPayments`. */
    public function hasReachedMaxUses(): bool
    {
        return $this->maxPayments !== null && $this->paymentCount >= $this->maxPayments;
    }
}
