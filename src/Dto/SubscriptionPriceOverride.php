<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Result of updating a subscription's price override (applies from the next renewal).
 */
final readonly class SubscriptionPriceOverride
{
    public function __construct(
        public string  $id,

        /** Discounted price in centimes, or null when cleared */
        public ?int    $priceOverride,

        /** Expiry of a time-boxed override, or null when permanent / cleared */
        public ?string $priceOverrideUntil,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:                 (string) ($data['id'] ?? ''),
            priceOverride:      isset($data['priceOverride'])      ? (int)    $data['priceOverride']      : null,
            priceOverrideUntil: isset($data['priceOverrideUntil']) ? (string) $data['priceOverrideUntil'] : null,
        );
    }
}
