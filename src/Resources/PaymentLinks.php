<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\PaginatedResponse;
use Aslan\Payment\Dto\PaymentLink;

/**
 * Payment Links resource.
 *
 * Payment links are reusable, shareable URLs that allow customers to pay
 * without requiring a checkout session per transaction. Useful for invoices,
 * recurring donations, or social-media payment links.
 *
 * @see https://docs.aslanpay.ma/api/payment-links
 */
final class PaymentLinks
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * Create a new payment link.
     *
     * Required params:
     *  - title (string)
     *
     * Optional params:
     *  - amount (int, centimes) — omit or set openAmount=true for open-amount
     *  - openAmount (bool)
     *  - currency (string, default "MAD")
     *  - description (string)
     *  - slug (string) — custom URL slug (auto-generated if omitted)
     *  - maxUses (int) — limit total number of payments
     *  - successUrl (string)
     *  - cancelUrl (string)
     *  - expiresAt (string, ISO 8601)
     *  - metadata (array)
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
     *  - status (string) — active | inactive | expired
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
     * Updatable fields:
     *  - title, description, amount, status, maxUses, successUrl, cancelUrl,
     *    expiresAt, metadata
     *
     * Note: slug, currency, and openAmount cannot be changed after creation.
     *
     * @param  array<string, mixed> $params
     */
    public function update(string $id, array $params): PaymentLink
    {
        $response = $this->client->request('PATCH', '/api/v1/payment-links/' . $id, $params);

        return PaymentLink::fromArray($response['data'] ?? $response);
    }

    /**
     * Delete (deactivate) a payment link permanently.
     */
    public function delete(string $id): bool
    {
        $response = $this->client->request('DELETE', '/api/v1/payment-links/' . $id);

        return ($response['success'] ?? true) === true;
    }

    /**
     * Generate a QR code for the payment link.
     *
     * Returns the raw response array which typically contains:
     *  - qrCodeUrl (string) — URL to the QR code image
     *  - qrCodeDataUrl (string) — base64 data URL for inline embedding
     *  - paymentUrl (string) — the link URL encoded in the QR
     *
     * @return array<string, mixed>
     */
    public function getQrCode(string $id): array
    {
        $response = $this->client->request('GET', '/api/v1/payment-links/' . $id . '/qr');

        return $response['data'] ?? $response;
    }
}
