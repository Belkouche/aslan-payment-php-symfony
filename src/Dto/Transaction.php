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
        public int     $amount,
        public string  $currency,

        /**
         * Transaction status.
         * Possible values: pending, authorized, captured, failed, refunded, partially_refunded, voided
         */
        public string  $status,

        /** Payment method used: card, wallet, qr_code, etc. */
        public ?string $paymentMethod,

        /** Last 4 digits of the card (when paymentMethod = card) */
        public ?string $cardLast4,

        /** Card brand: visa, mastercard, etc. */
        public ?string $cardBrand,

        /** Amount already refunded in centimes */
        public int     $refundedAmount,

        /** Merchant-supplied reference / order identifier */
        public ?string $reference,

        public ?string $description,

        /** ID of the checkout session that originated this transaction */
        public ?string $checkoutSessionId,

        /** ID of the payment link that originated this transaction */
        public ?string $paymentLinkId,

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
            id:                (string) $data['id'],
            amount:            (int)    $data['amount'],
            currency:          (string) ($data['currency']      ?? 'MAD'),
            status:            (string) ($data['status']        ?? 'pending'),
            paymentMethod:     isset($data['paymentMethod'])    ? (string) $data['paymentMethod']    : null,
            cardLast4:         isset($data['cardLast4'])         ? (string) $data['cardLast4']        : null,
            cardBrand:         isset($data['cardBrand'])         ? (string) $data['cardBrand']        : null,
            refundedAmount:    (int)    ($data['refundedAmount'] ?? 0),
            reference:         isset($data['reference'])         ? (string) $data['reference']        : null,
            description:       isset($data['description'])       ? (string) $data['description']      : null,
            checkoutSessionId: isset($data['checkoutSessionId']) ? (string) $data['checkoutSessionId']: null,
            paymentLinkId:     isset($data['paymentLinkId'])     ? (string) $data['paymentLinkId']    : null,
            metadata:          (array)  ($data['metadata']       ?? []),
            createdAt:         (string) ($data['createdAt']      ?? $data['created_at'] ?? ''),
            updatedAt:         (string) ($data['updatedAt']      ?? $data['updated_at'] ?? ''),
        );
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, ['authorized', 'captured'], true);
    }

    public function isRefundable(): bool
    {
        return in_array($this->status, ['captured', 'partially_refunded'], true)
            && $this->refundableAmount() > 0;
    }

    /** Returns the remaining refundable amount in centimes */
    public function refundableAmount(): int
    {
        return max(0, $this->amount - $this->refundedAmount);
    }
}
