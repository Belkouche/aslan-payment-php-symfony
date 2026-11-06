<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a payment transaction.
 *
 * Amounts are in centimes (100 = 1.00 MAD).
 */
final readonly class Transaction
{
    public function __construct(
        public string  $id,

        /** Amount in centimes (0 when the API has no amount yet, e.g. an open-amount session not yet set) */
        public int     $amount,
        public string  $currency,

        /**
         * Transaction status.
         * Possible values: pending, pending_cash, cash_late_confirmation, processing, succeeded,
         * failed, expired, refunded, partially_refunded
         */
        public string  $status,

        /** @deprecated The API does not return it; always null. */
        public ?string $paymentMethod,

        /** @deprecated The API does not return it; always null. */
        public ?string $cardLast4,

        /** @deprecated The API does not return it; always null. */
        public ?string $cardBrand,

        /**
         * Amount refunded so far in centimes: the sum of the transaction's refunds that are not
         * `failed` or `rejected` (the same rule the API uses to cap new refunds). Only `retrieve()`
         * returns the refunds; 0 for list items.
         */
        public int     $refundedAmount,

        /** @deprecated The API has no transaction reference; always null. Use metadata. */
        public ?string $reference,

        /** @deprecated The API does not return it; always null. */
        public ?string $description,

        /** ID of the checkout session that originated this transaction (`retrieve()` only) */
        public ?string $checkoutSessionId,

        /** @deprecated The API does not return it; always null. */
        public ?string $paymentLinkId,

        public array   $metadata,

        public string  $createdAt,

        /** ISO 8601 last update (`retrieve()` only; empty for list items) */
        public string  $updatedAt,

        /** Payment-provider reference */
        public ?string $providerRef = null,

        /** ISO 8601 time the payment succeeded */
        public ?string $paidAt = null,

        /** ISO 8601 time the payment failed (`retrieve()` only) */
        public ?string $failedAt = null,

        /** Failure message from the payment provider (`retrieve()` only) */
        public ?string $errorMessage = null,

        /**
         * Refunds of this transaction as returned by `retrieve()` (raw arrays: id, amount,
         * currency, status, reason, providerRef, approvedAt, createdAt). Empty for list items.
         *
         * @var list<array<string, mixed>>
         */
        public array   $refunds = [],

        /**
         * `default`, or the alias of the PSP account that processed this payment.
         * Null when the API does not return it (e.g. a webhook payload where it could not be determined).
         */
        public ?string $providerAccount = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $refunds = array_values(array_filter(
            (array) ($data['refunds'] ?? []),
            static fn ($r): bool => is_array($r),
        ));

        $refunded = 0;
        foreach ($refunds as $refund) {
            if (!in_array($refund['status'] ?? null, ['failed', 'rejected'], true)) {
                $refunded += (int) ($refund['amount'] ?? 0);
            }
        }

        return new self(
            id:                (string) $data['id'],
            amount:            (int)    ($data['amount'] ?? 0),
            currency:          (string) ($data['currency']      ?? 'MAD'),
            status:            (string) ($data['status']        ?? 'pending'),
            paymentMethod:     isset($data['paymentMethod'])    ? (string) $data['paymentMethod']    : null,
            cardLast4:         isset($data['cardLast4'])         ? (string) $data['cardLast4']        : null,
            cardBrand:         isset($data['cardBrand'])         ? (string) $data['cardBrand']        : null,
            refundedAmount:    isset($data['refundedAmount']) ? (int) $data['refundedAmount'] : $refunded,
            reference:         isset($data['reference'])         ? (string) $data['reference']        : null,
            description:       isset($data['description'])       ? (string) $data['description']      : null,
            checkoutSessionId: isset($data['checkoutSessionId']) ? (string) $data['checkoutSessionId']: null,
            paymentLinkId:     isset($data['paymentLinkId'])     ? (string) $data['paymentLinkId']    : null,
            metadata:          (array)  ($data['metadata']       ?? []),
            createdAt:         (string) ($data['createdAt']      ?? ''),
            updatedAt:         (string) ($data['updatedAt']      ?? ''),
            providerRef:       isset($data['providerRef'])  ? (string) $data['providerRef']  : null,
            paidAt:            isset($data['paidAt'])       ? (string) $data['paidAt']       : null,
            failedAt:          isset($data['failedAt'])     ? (string) $data['failedAt']     : null,
            errorMessage:      isset($data['errorMessage']) ? (string) $data['errorMessage'] : null,
            refunds:           $refunds,
            providerAccount:   isset($data['providerAccount']) ? (string) $data['providerAccount'] : null,
        );
    }

    /** The payment went through and nothing has been refunded (status `succeeded`). */
    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }

    /**
     * The API accepts a refund (status `succeeded` or `partially_refunded`) and some amount is
     * left. Reliable on `retrieve()` results; list items carry no refunds, so the remaining
     * amount is assumed to be the full amount there.
     */
    public function isRefundable(): bool
    {
        return in_array($this->status, ['succeeded', 'partially_refunded'], true)
            && $this->refundableAmount() > 0;
    }

    /** Returns the remaining refundable amount in centimes */
    public function refundableAmount(): int
    {
        return max(0, $this->amount - $this->refundedAmount);
    }
}
