<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * A single-use, time-bounded Aslan payment link minted for a subscription
 * (change-card or repay). Redirect the customer to $redirectUrl (plain GET).
 */
final readonly class SubscriptionPaymentLink
{
    public function __construct(
        public string $subscriptionId,
        public string $paymentLinkId,

        /** Aslan https://<domain>/pl/<shortCode> URL */
        public string $redirectUrl,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            subscriptionId: (string) ($data['subscriptionId'] ?? ''),
            paymentLinkId:  (string) ($data['paymentLinkId']  ?? ''),
            redirectUrl:    (string) ($data['redirectUrl']    ?? ''),
        );
    }
}
