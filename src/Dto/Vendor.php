<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a vendor (sub-merchant) in a marketplace or split-payment setup.
 */
final readonly class Vendor
{
    public function __construct(
        public string  $id,

        /** Display name */
        public string  $name,

        /** @deprecated The API has no vendor code; holds `externalId` (or ''). */
        public string  $code,

        /**
         * Derived from the API's `isActive` flag.
         * Possible values: active, inactive
         */
        public string  $status,

        public ?string $email,

        /** @deprecated The API does not return it; always null. */
        public ?string $phone,

        /** @deprecated The API does not return it; always null. */
        public ?string $ice,

        /** @deprecated The API does not return it; always null. */
        public ?string $iban,

        /** @deprecated The API does not return it; always 0. */
        public int     $settledAmount,

        /** @deprecated The API does not return it; always 0. */
        public int     $pendingAmount,

        public array   $metadata,

        public string  $createdAt,
        public string  $updatedAt,

        /** Your own identifier for the vendor (unique per merchant) */
        public ?string $externalId = null,

        /** Commission rate between 0 and 1 (e.g. 0.1 = 10 %), or null */
        public ?float  $commissionRate = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $externalId = isset($data['externalId']) ? (string) $data['externalId'] : null;
        $active     = array_key_exists('isActive', $data)
            ? (bool) $data['isActive']
            : (($data['status'] ?? 'active') === 'active');

        return new self(
            id:             (string) $data['id'],
            name:           (string) ($data['name'] ?? ''),
            code:           (string) ($data['code'] ?? $externalId ?? ''),
            status:         $active ? 'active' : 'inactive',
            email:          isset($data['email']) ? (string) $data['email'] : null,
            phone:          isset($data['phone']) ? (string) $data['phone'] : null,
            ice:            isset($data['ice'])   ? (string) $data['ice']   : null,
            iban:           isset($data['iban'])  ? (string) $data['iban']  : null,
            settledAmount:  (int) ($data['settledAmount'] ?? 0),
            pendingAmount:  (int) ($data['pendingAmount'] ?? 0),
            metadata:       (array) ($data['metadata'] ?? []),
            createdAt:      (string) ($data['createdAt'] ?? ''),
            updatedAt:      (string) ($data['updatedAt'] ?? ''),
            externalId:     $externalId,
            // Decimal column: the API serialises it as a string such as "0.1".
            commissionRate: isset($data['commissionRate']) ? (float) $data['commissionRate'] : null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
