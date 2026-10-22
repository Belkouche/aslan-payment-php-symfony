<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * The saved card backing a subscription.
 *
 * Only brand, last-4 and expiry are ever exposed — never the PAN. The brand is
 * reliable; last4 and expiry are commonly null.
 */
final readonly class SubscriptionCard
{
    public function __construct(
        /** Card brand, e.g. "VISA" */
        public ?string $brand,
        public ?string $last4,
        public ?int    $expiryMonth,
        public ?int    $expiryYear,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            brand:       isset($data['brand'])       ? (string) $data['brand']       : null,
            last4:       isset($data['last4'])       ? (string) $data['last4']       : null,
            expiryMonth: isset($data['expiryMonth']) ? (int)    $data['expiryMonth'] : null,
            expiryYear:  isset($data['expiryYear'])  ? (int)    $data['expiryYear']  : null,
        );
    }
}
