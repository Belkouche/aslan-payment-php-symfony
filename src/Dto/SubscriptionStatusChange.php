<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Result of cancel / uncancel / pause / resume.
 *
 * $status is what the API reports for the action: e.g. "cancelled" for an
 * immediate cancel, the unchanged status for a period-end cancel, "paused",
 * or "resumed" for resume (call retrieve() for the authoritative status).
 */
final readonly class SubscriptionStatusChange
{
    public function __construct(
        public string $subscriptionId,
        public string $status,

        /** True when the subscription is scheduled to cancel at period end */
        public bool   $cancelAtPeriodEnd,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            subscriptionId:    (string) ($data['subscriptionId'] ?? ''),
            status:            (string) ($data['status']         ?? ''),
            cancelAtPeriodEnd: (bool)   ($data['cancelAtPeriodEnd'] ?? false),
        );
    }
}
