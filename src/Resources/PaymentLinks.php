<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\PaginatedResponse;
use Aslan\Payment\Dto\PaymentLink;

/**
 * Payment Links resource.
 *
 * Payment links are shareable URLs (with a QR code) that start a hosted payment.
 * A link is paid at most once: after a successful payment the API marks it
 * `finalized` and refuses further payments.
 *
 * @see https://docs.aslanpay.ma/api/payment-links
 */
final class PaymentLinks
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * Create a new payment link.
     *
     * All params are optional:
     *  - amount (int, centimes) — omit or null for an open amount
     *  - minAmount, maxAmount (int, centimes) — bounds for an open amount
     *  - currency (string, "MAD" only, default)
     *  - description (string, max 500)
     *  - maxPayments (int, >= 1) — limit the number of payment attempts
     *  - vendorId (uuid)
     *  - providerAccount (string, ^[a-z0-9][a-z0-9-]{0,31}$) — alias of one of your additional PSP accounts;
     *    omit or "default" for the default account. Errors: PROVIDER_ACCOUNT_NOT_FOUND / _INACTIVE / _UNSUPPORTED
     *  - expiresAt (string, ISO 8601)
     *  - metadata (array<string, string>)
     *
     * @param  array<string, mixed> $params
     * @param  string|null          $idempotencyKey
     */
    public function create(array $params, ?string $idempotencyKey = null): PaymentLink
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->client->request('POST', '/api/v1/payment-links', $params, $headers);

        return PaymentLink::fromArray($response['data'] ?? $response);
    }

    /**
     * List payment links with optional filters.
     *
     * Query params:
     *  - page (int, default 1)
     *  - pageSize (int, default 20, max 100)
     *  - status (string) — active | inactive | expired | finalized
     *
     * @param  array<string, mixed>    $query
     * @return PaginatedResponse<PaymentLink>
     */
    public function list(array $query = []): PaginatedResponse
    {
        $response = $this->client->request('GET', '/api/v1/payment-links', [], [], $query);

        return PaginatedResponse::fromArray(
            $response,
            static fn (array $item) => PaymentLink::fromArray($item),
        );
    }

    /**
     * Retrieve a single payment link by ID.
     */
    public function retrieve(string $id): PaymentLink
    {
        $response = $this->client->request('GET', '/api/v1/payment-links/' . $id);

        return PaymentLink::fromArray($response['data'] ?? $response);
    }

    /**
     * Update an existing payment link.
     *
     * Updatable fields: description, status (active | inactive), maxPayments,
     * expiresAt, metadata. An expired link cannot be updated (HTTP 409).
     *
     * @param  array<string, mixed> $params
     */
    public function update(string $id, array $params): PaymentLink
    {
        $response = $this->client->request('PATCH', '/api/v1/payment-links/' . $id, $params);

        return PaymentLink::fromArray($response['data'] ?? $response);
    }

    /**
     * Soft-delete a payment link (the API sets its status to `inactive`).
     */
    public function delete(string $id): bool
    {
        $response = $this->client->request('DELETE', '/api/v1/payment-links/' . $id);

        return ($response['success'] ?? true) === true;
    }

    /**
     * Generate a QR code for the payment link.
     *
     * Returns the raw response array:
     *  - dataUri (string) — base64 PNG data URI for inline embedding
     *  - mimeType (string) — image/png
     *
     * @return array<string, mixed>
     */
    public function getQrCode(string $id): array
    {
        $response = $this->client->request('GET', '/api/v1/payment-links/' . $id . '/qr');

        return $response['data'] ?? $response;
    }
}
