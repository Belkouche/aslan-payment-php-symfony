<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a merchant customer (created via the API or from a payment).
 */
final readonly class Customer
{
    /** Internal API metadata keys that are never exposed in $metadata. */
    private const INTERNAL_METADATA_KEYS = ['encryptedEmail'];

    public function __construct(
        public string  $id,

        public ?string $email,

        /** Moroccan phone in +212XXXXXXXXX format */
        public ?string $phone,

        /** @deprecated The API does not return it; always null. Use $name. */
        public ?string $firstName,

        /** @deprecated The API does not return it; always null. Use $name. */
        public ?string $lastName,

        /** Full name */
        public ?string $name,

        /** @deprecated The API does not return it; always null. */
        public ?string $country,

        /**
         * Sum in centimes of the amounts of all the customer's transactions, whatever their
         * status (the API's `transactions.totalAmount`). Only `retrieve()` returns it; 0 otherwise.
         */
        public int     $totalSpend,

        /**
         * Number of the customer's transactions, whatever their status (the API's
         * `transactions.count`). Only `retrieve()` returns it; 0 otherwise.
         */
        public int     $transactionCount,

        /** Your metadata (internal API keys such as `encryptedEmail` are removed) */
        public array   $metadata,

        public string  $createdAt,
        public string  $updatedAt,

        /** Number of succeeded transactions (`retrieve()` only; 0 otherwise) */
        public int     $successfulTransactionCount = 0,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $summary  = is_array($data['transactions'] ?? null) ? $data['transactions'] : [];
        $metadata = array_diff_key(
            (array) ($data['metadata'] ?? []),
            array_flip(self::INTERNAL_METADATA_KEYS),
        );

        return new self(
            id:               (string) $data['id'],
            email:            isset($data['email'])     ? (string) $data['email']     : null,
            phone:            isset($data['phone'])     ? (string) $data['phone']     : null,
            firstName:        isset($data['firstName']) ? (string) $data['firstName'] : null,
            lastName:         isset($data['lastName'])  ? (string) $data['lastName']  : null,
            name:             isset($data['name'])      ? (string) $data['name']      : null,
            country:          isset($data['country'])   ? (string) $data['country']   : null,
            totalSpend:       (int) ($summary['totalAmount'] ?? $data['totalSpend'] ?? 0),
            transactionCount: (int) ($summary['count'] ?? $data['transactionCount'] ?? 0),
            metadata:         $metadata,
            createdAt:        (string) ($data['createdAt'] ?? ''),
            updatedAt:        (string) ($data['updatedAt'] ?? ''),
            successfulTransactionCount: (int) ($summary['successCount'] ?? 0),
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
