<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * The customer inlined on a subscription (GET /billing/subscriptions/{id}).
 */
final readonly class SubscriptionCustomer
{
    public function __construct(
        public string  $id,
        public ?string $email,
        public ?string $name,

        /** Moroccan phone in +212XXXXXXXXX format */
        public ?string $phone,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:    (string) $data['id'],
            email: isset($data['email']) ? (string) $data['email'] : null,
            name:  isset($data['name'])  ? (string) $data['name']  : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
        );
    }
}
