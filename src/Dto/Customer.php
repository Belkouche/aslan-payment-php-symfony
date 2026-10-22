<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a merchant customer (created via the API or from a payment).
 */
final readonly class Customer
{
    public function __construct(
        public string  $id,

        public ?string $email,

        /** Moroccan phone in +212XXXXXXXXX format */
        public ?string $phone,

        public ?string $firstName,
        public ?string $lastName,

        /** Full name as provided by the customer (may differ from first+last) */
        public ?string $name,

        /** ISO 3166-1 alpha-2 country code */
        public ?string $country,

        /** Total lifetime spend in centimes */
        public int     $totalSpend,

        /** Number of successful transactions */
        public int     $transactionCount,

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
            id:               (string) $data['id'],
            email:            isset($data['email'])     ? (string) $data['email']     : null,
            phone:            isset($data['phone'])     ? (string) $data['phone']     : null,
            firstName:        isset($data['firstName']) ? (string) $data['firstName'] : null,
            lastName:         isset($data['lastName'])  ? (string) $data['lastName']  : null,
            name:             isset($data['name'])      ? (string) $data['name']      : null,
            country:          isset($data['country'])   ? (string) $data['country']   : null,
            totalSpend:       (int)   ($data['totalSpend']       ?? 0),
            transactionCount: (int)   ($data['transactionCount'] ?? 0),
            metadata:         (array) ($data['metadata']         ?? []),
            createdAt:        (string) ($data['createdAt'] ?? $data['created_at'] ?? ''),
            updatedAt:        (string) ($data['updatedAt'] ?? $data['updated_at'] ?? ''),
        );
    }

    /**
     * Returns the best available display name for the customer.
     */
    public function displayName(): string
    {
        if ($this->firstName || $this->lastName) {
            return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
        }

        return $this->name ?? $this->email ?? $this->phone ?? $this->id;
    }
}
