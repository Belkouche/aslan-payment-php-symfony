<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * A single charge attempt on a subscription (initial, renewal or proration).
 *
 * Amounts are in centimes (100 = 1.00 MAD).
 */
final readonly class BillingAttempt
{
    public function __construct(
        public string  $id,
        public string  $subscriptionId,

        /** Transaction created for this attempt, if any */
        public ?string $transactionId,

        /** initial | renewal | proration */
        public string  $type,

        /** pending | succeeded | failed */
        public string  $status,

        public int     $amount,
        public string  $currency,
        public ?string $providerRef,
        public ?string $failureReason,

        /** 1 for the first try, incremented by dunning retries */
        public int     $attemptNumber,

        public ?string $billingPeriodStart,
        public ?string $billingPeriodEnd,

        public string  $createdAt,
        public string  $updatedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:                 (string) $data['id'],
            subscriptionId:     (string) ($data['subscriptionId'] ?? ''),
            transactionId:      isset($data['transactionId'])      ? (string) $data['transactionId']      : null,
            type:               (string) ($data['type']           ?? ''),
            status:             (string) ($data['status']         ?? 'pending'),
            amount:             (int)    ($data['amount']         ?? 0),
            currency:           (string) ($data['currency']       ?? 'MAD'),
            providerRef:        isset($data['providerRef'])        ? (string) $data['providerRef']        : null,
            failureReason:      isset($data['failureReason'])      ? (string) $data['failureReason']      : null,
            attemptNumber:      (int)    ($data['attemptNumber']  ?? 1),
            billingPeriodStart: isset($data['billingPeriodStart']) ? (string) $data['billingPeriodStart'] : null,
            billingPeriodEnd:   isset($data['billingPeriodEnd'])   ? (string) $data['billingPeriodEnd']   : null,
            createdAt:          (string) ($data['createdAt'] ?? $data['created_at'] ?? ''),
            updatedAt:          (string) ($data['updatedAt'] ?? $data['updated_at'] ?? ''),
        );
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
