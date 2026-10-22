<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a billing plan (the price + interval a subscription renews on).
 *
 * Amounts are in centimes (100 = 1.00 MAD).
 */
final readonly class Plan
{
    public function __construct(
        public string  $id,
        public string  $name,

        /** Price per period in centimes */
        public int     $amount,

        public string  $currency,

        /**
         * Billing interval.
         * Possible values: daily, weekly, monthly, quarterly, yearly
         */
        public string  $interval,

        /** Number of intervals per billing period (1–12) */
        public int     $intervalCount,

        /** Trial length in days (0 = no trial) */
        public int     $trialDays,

        /** Inactive plans cannot be used for new subscriptions or plan changes */
        public bool    $isActive,

        /** VAT rate in basis points (2000 = 20.00%), or null to use the merchant default */
        public ?int    $tvaRateBps,

        /** @var array<string, string> */
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
            id:            (string) $data['id'],
            name:          (string) ($data['name']          ?? ''),
            amount:        (int)    ($data['amount']        ?? 0),
            currency:      (string) ($data['currency']      ?? 'MAD'),
            interval:      (string) ($data['interval']      ?? 'monthly'),
            intervalCount: (int)    ($data['intervalCount'] ?? 1),
            trialDays:     (int)    ($data['trialDays']     ?? 0),
            isActive:      (bool)   ($data['isActive']      ?? true),
            tvaRateBps:    isset($data['tvaRateBps']) ? (int) $data['tvaRateBps'] : null,
            metadata:      (array)  ($data['metadata']      ?? []),
            createdAt:     (string) ($data['createdAt'] ?? $data['created_at'] ?? ''),
            updatedAt:     (string) ($data['updatedAt'] ?? $data['updated_at'] ?? ''),
        );
    }

    public function hasTrial(): bool
    {
        return $this->trialDays > 0;
    }
}
