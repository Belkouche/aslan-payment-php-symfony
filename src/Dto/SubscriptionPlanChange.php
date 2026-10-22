<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Result of changing a subscription's plan.
 */
final readonly class SubscriptionPlanChange
{
    public function __construct(
        public string  $subscriptionId,
        public string  $planId,
        public ?string $previousPlanId,

        /** immediate | period_end | immediate_proration */
        public string  $when,

        /** Set only for immediate_proration when a proration charge was made */
        public ?string $proratedTransactionId,

        /** Proration amount actually charged, in centimes */
        public ?int    $proratedAmount,

        public ?string $proratedCurrency,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            subscriptionId:        (string) ($data['subscriptionId'] ?? ''),
            planId:                (string) ($data['planId']         ?? ''),
            previousPlanId:        isset($data['previousPlanId'])        ? (string) $data['previousPlanId']        : null,
            when:                  (string) ($data['when']           ?? 'immediate'),
            proratedTransactionId: isset($data['proratedTransactionId']) ? (string) $data['proratedTransactionId'] : null,
            proratedAmount:        isset($data['proratedAmount'])        ? (int)    $data['proratedAmount']        : null,
            proratedCurrency:      isset($data['proratedCurrency'])      ? (string) $data['proratedCurrency']      : null,
        );
    }
}
