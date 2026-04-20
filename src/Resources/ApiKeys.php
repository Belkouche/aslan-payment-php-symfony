<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\ApiKey;
use Aslan\Payment\Dto\PaginatedResponse;

/**
 * API Keys resource.
 *
 * Manage the API keys attached to your merchant account. The full secret
 * is returned ONLY at creation time — store it immediately and securely.
 *
 * @see https://docs.aslanpay.ma/api/api-keys
 */
final class ApiKeys
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * Create a new API key.
     *
     * Required params:
     *  - name (string) — human-readable label
     *
     * Optional params:
     *  - environment (string) — "live" | "test" (default: mirrors the current key)
     *
     * @param  array<string, mixed> $params
     * @param  string|null          $idempotencyKey
     */
    public function create(array $params, ?string $idempotencyKey = null): ApiKey
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->client->request('POST', '/api/v1/api-keys', $params, $headers);

        return ApiKey::fromArray($response['data'] ?? $response);
    }

    /**
     * List API keys.
     *
     * Query params:
     *  - page (int, default 1)
     *  - pageSize (int, default 20, max 100)
     *  - environment (string) — live | test
     *  - status (string) — active | revoked
     *
     * @param  array<string, mixed> $query
     * @return PaginatedResponse<ApiKey>
     */
    public function list(array $query = []): PaginatedResponse
    {
        $response = $this->client->request('GET', '/api/v1/api-keys', [], [], $query);

        return PaginatedResponse::fromArray(
            $response,
            static fn (array $item) => ApiKey::fromArray($item),
        );
    }

    /**
     * Permanently revoke an API key.
     *
     * This operation is irreversible. The key will immediately stop
     * authenticating requests.
     */
    public function delete(string $id): bool
    {
        $response = $this->client->request('DELETE', '/api/v1/api-keys/' . $id);

        return ($response['success'] ?? true) === true;
    }
}
