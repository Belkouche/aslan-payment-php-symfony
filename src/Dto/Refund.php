<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a refund issued against a transaction.
 *
 * Amounts are in centimes (100 = 1.00 MAD).
 */
final readonly class Refund
{
    public function __construct(
        public string  $id,

        /** ID of the original transaction being refunded */
        public string  $transactionId,

        /** Amount refunded in centimes */
        public int     $amount,

        public string  $currency,

        /**
         * Refund status.
         * Possible values: pending, succeeded, failed
         */
        public string  $status,

        /** Internal reason code for the refund */
        public ?string $reason,

        /** Human-readable note */
        public ?string $note,

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
            transactionId: (string) ($data['transactionId'] ?? $data['transaction_id'] ?? ''),
            amount:        (int)    $data['amount'],
            currency:      (string) ($data['currency'] ?? 'MAD'),
            status:        (string) ($data['status']   ?? 'pending'),
            reason:        isset($data['reason']) ? (string) $data['reason'] : null,
            note:          isset($data['note'])   ? (string) $data['note']   : null,
            metadata:      (array)  ($data['metadata'] ?? []),
            createdAt:     (string) ($data['createdAt'] ?? $data['created_at'] ?? ''),
            updatedAt:     (string) ($data['updatedAt'] ?? $data['updated_at'] ?? ''),
        );
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
