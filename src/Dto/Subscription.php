<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a recurring subscription of a customer to a plan.
 *
 * Amounts are in centimes (100 = 1.00 MAD).
 *
 * `customer` and `card` are only returned by retrieve(); the list endpoint
 * omits them. `subscription.*` webhook events carry a partial subscription
 * (id, planId, period dates, metadata…) — fields absent from the payload are
 * null / defaults.
 */
final readonly class Subscription
{
    public function __construct(
        public string                $id,
        public ?string               $customerId,
        public ?SubscriptionCustomer $customer,
        public ?string               $planId,
        public ?Plan                 $plan,

        /** PSP provider backing this subscription; null until assigned */
        public ?string               $providerId,

        /**
         * Subscription status.
         * Possible values: pending_initial, trialing, active, past_due, paused, cancelled
         */
        public ?string               $status,

        /** Consecutive failed billing attempts (dunning counter); 0 when healthy */
        public int                   $failureCount,

        public ?string               $currentPeriodStart,

        /** End of the current period (= the next renewal) */
        public ?string               $currentPeriodEnd,

        public ?string               $nextBillingAt,
        public ?string               $trialEndsAt,

        /** True when scheduled to cancel at the end of the current period */
        public bool                  $cancelAtPeriodEnd,

        public ?string               $cancelledAt,

        /** Discounted price in centimes charged instead of the plan amount, or null */
        public ?int                  $priceOverride,

        /** When a time-boxed price override expires (inclusive), or null */
        public ?string               $priceOverrideUntil,

        /** @var array<string, mixed> */
        public array                 $metadata,

        public ?SubscriptionCard     $card,

        public string                $createdAt,
        public string                $updatedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:                 (string) $data['id'],
            customerId:         isset($data['customerId'])         ? (string) $data['customerId']         : null,
            customer:           is_array($data['customer'] ?? null) ? SubscriptionCustomer::fromArray($data['customer']) : null,
            planId:             isset($data['planId'])             ? (string) $data['planId']             : null,
            plan:               is_array($data['plan'] ?? null)     ? Plan::fromArray($data['plan'])     : null,
            providerId:         isset($data['providerId'])         ? (string) $data['providerId']         : null,
            status:             isset($data['status'])             ? (string) $data['status']             : null,
            failureCount:       (int)  ($data['failureCount']      ?? 0),
            currentPeriodStart: isset($data['currentPeriodStart']) ? (string) $data['currentPeriodStart'] : null,
            currentPeriodEnd:   isset($data['currentPeriodEnd'])   ? (string) $data['currentPeriodEnd']   : null,
            nextBillingAt:      isset($data['nextBillingAt'])      ? (string) $data['nextBillingAt']      : null,
            trialEndsAt:        isset($data['trialEndsAt'])        ? (string) $data['trialEndsAt']        : null,
            cancelAtPeriodEnd:  (bool) ($data['cancelAtPeriodEnd'] ?? false),
            cancelledAt:        isset($data['cancelledAt'])        ? (string) $data['cancelledAt']        : null,
            priceOverride:      isset($data['priceOverride'])      ? (int)    $data['priceOverride']      : null,
            priceOverrideUntil: isset($data['priceOverrideUntil']) ? (string) $data['priceOverrideUntil'] : null,
            metadata:           (array) ($data['metadata']         ?? []),
            card:               is_array($data['card'] ?? null)     ? SubscriptionCard::fromArray($data['card']) : null,
            createdAt:          (string) ($data['createdAt'] ?? $data['created_at'] ?? ''),
            updatedAt:          (string) ($data['updatedAt'] ?? $data['updated_at'] ?? ''),
        );
    }

    /** True while the customer is entitled to the service (active or trialing). */
    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }
}
