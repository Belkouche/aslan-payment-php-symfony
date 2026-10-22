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
     *  - successUrl, cancelUrl (string, https://)
     *
     * Optional params:
     *  - amount (int, centimes, 100 to 100000000) — omit or null for an open amount
     *  - currency (string, "MAD" only, default)
     *  - customer (array: email, name, phone)
     *  - lineItems, billingAddress, shippingAddress (arrays)
     *  - paymentMethods (list: card, cash; default [card])
     *  - vendorId (uuid), require3ds (bool, default true)
     *  - metadata (array<string, string>, max 20 keys)
     *
     * The session expires 30 minutes after creation (48 hours when cash is offered).
     * Unknown fields are dropped by the API.
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
