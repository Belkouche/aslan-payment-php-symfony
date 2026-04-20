<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\CheckoutSession;

/**
 * Checkout Sessions resource.
 *
 * A checkout session is a single-use, time-limited payment intent that
 * redirects your customer to Aslan's hosted payment page.
 *
 * @see https://docs.aslanpay.ma/api/checkout-sessions
 */
final class CheckoutSessions
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * Create a new checkout session.
     *
     * Required params:
     *  - amount (int, centimes) — omit or set openAmount=true for open-amount sessions
     *  - currency (string, default "MAD")
     *
     * Optional params:
     *  - openAmount (bool)
     *  - reference (string)
     *  - description (string)
     *  - successUrl (string)
     *  - cancelUrl (string)
     *  - expiresIn (int, seconds, default 3600)
     *  - metadata (array)
     *
     * @param  array<string, mixed> $params
     * @param  string|null          $idempotencyKey Safely replay the same request
     */
    public function create(array $params, ?string $idempotencyKey = null): CheckoutSession
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->client->request('POST', '/api/v1/checkout/sessions', $params, $headers);

        return CheckoutSession::fromArray($response['data'] ?? $response);
    }

    /**
     * Retrieve a checkout session by ID.
     */
    public function retrieve(string $id): CheckoutSession
    {
        $response = $this->client->request('GET', '/api/v1/checkout/sessions/' . $id);

        return CheckoutSession::fromArray($response['data'] ?? $response);
    }
}
