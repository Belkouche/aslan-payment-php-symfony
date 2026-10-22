<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\PaginatedResponse;
use Aslan\Payment\Dto\Vendor;

/**
 * Vendors resource.
 *
 * Vendors are sub-merchants in a marketplace scenario. Each vendor can
 * receive a split of incoming payments and be settled independently.
 *
 * @see https://docs.aslanpay.ma/api/vendors
 */
final class Vendors
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * Create a new vendor.
     *
     * Required params:
     *  - name (string)
     *
     * Optional params:
     *  - externalId (string) — your own identifier, unique per merchant
     *  - email (string)
     *  - commissionRate (float, 0 to 1)
     *  - metadata (array<string, string>)
     *
     * The merchant must be configured as a marketplace. The API ignores the
     * idempotency key on this route.
     *
     * @param  array<string, mixed> $params
     * @param  string|null          $idempotencyKey
     */
    public function create(array $params, ?string $idempotencyKey = null): Vendor
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->client->request('POST', '/api/v1/vendors', $params, $headers);

        return Vendor::fromArray($response['data'] ?? $response);
    }

    /**
     * List vendors with optional filters.
     *
     * Query params:
     *  - page (int, default 1)
     *  - limit (int, default 20, max 100) — not pageSize
     *  - search (string) — name or externalId
     *  - isActive (string) — "true" | "false"
     *
     * @param  array<string, mixed> $query
     * @return PaginatedResponse<Vendor>
     */
    public function list(array $query = []): PaginatedResponse
    {
        $response = $this->client->request('GET', '/api/v1/vendors', [], [], $query);

        return PaginatedResponse::fromArray(
            $response,
            static fn (array $item) => Vendor::fromArray($item),
        );
    }

    /**
     * Retrieve a single vendor by ID.
     */
    public function retrieve(string $id): Vendor
    {
        $response = $this->client->request('GET', '/api/v1/vendors/' . $id);

        return Vendor::fromArray($response['data'] ?? $response);
    }

    /**
     * Update a vendor's details.
     *
     * Updatable fields: name, email, commissionRate, externalId, isActive, metadata
     *
     * @param  array<string, mixed> $params
     */
    public function update(string $id, array $params): Vendor
    {
        $response = $this->client->request('PATCH', '/api/v1/vendors/' . $id, $params);

        return Vendor::fromArray($response['data'] ?? $response);
    }

    /**
     * Deactivate a vendor (the API sets isActive to false).
     */
    public function delete(string $id): bool
    {
        $response = $this->client->request('DELETE', '/api/v1/vendors/' . $id);

        return ($response['success'] ?? true) === true;
    }
}
