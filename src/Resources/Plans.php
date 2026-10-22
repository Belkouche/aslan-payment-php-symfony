<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\PaginatedResponse;
use Aslan\Payment\Dto\Plan;

/**
 * Billing plans resource.
 *
 * A plan defines the price and interval a subscription renews on. Plans
 * cannot be deleted; archive one with update($id, ['isActive' => false]).
 *
 * @see https://docs.aslanpay.ma/api/billing
 */
final class Plans
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * Create a plan.
     *
     * Required params:
     *  - name (string, 1–200 chars)
     *  - amount (int, centimes, >= 1)
     *  - currency (string, 3 letters, default "MAD")
     *  - interval (string) — daily | weekly | monthly | quarterly | yearly
     *
     * Optional params:
     *  - intervalCount (int, 1–12, default 1)
     *  - trialDays (int, >= 0, default 0)
     *  - metadata (array<string, string>)
     *
     * @param  array<string, mixed> $params
     */
    public function create(array $params): Plan
    {
        $response = $this->client->request('POST', '/api/v1/billing/plans', $params);

        return Plan::fromArray($response['data'] ?? $response);
    }

    /**
     * List plans.
     *
     * Query params:
     *  - page (int, default 1)
     *  - pageSize (int, default 20, max 100)
     *  - isActive (bool)
     *
     * @param  array<string, mixed> $query
     * @return PaginatedResponse<Plan>
     */
    public function list(array $query = []): PaginatedResponse
    {
        $response = $this->client->request('GET', '/api/v1/billing/plans', [], [], self::normalizeQuery($query));

        return PaginatedResponse::fromArray(
            $response,
            static fn (array $item) => Plan::fromArray($item),
        );
    }

    /**
     * Retrieve a plan by ID.
     */
    public function retrieve(string $id): Plan
    {
        $response = $this->client->request('GET', '/api/v1/billing/plans/' . rawurlencode($id));

        return Plan::fromArray($response['data'] ?? $response);
    }

    /**
     * Update a plan. Only name, isActive and metadata can change; amount and
     * interval are immutable.
     *
     * @param  array<string, mixed> $params
     */
    public function update(string $id, array $params): Plan
    {
        $response = $this->client->request('PATCH', '/api/v1/billing/plans/' . rawurlencode($id), $params);

        return Plan::fromArray($response['data'] ?? $response);
    }

    /**
     * The API expects booleans in the query string as "true"/"false".
     *
     * @param  array<string, mixed> $query
     * @return array<string, mixed>
     */
    private static function normalizeQuery(array $query): array
    {
        return array_map(
            static fn (mixed $v) => is_bool($v) ? ($v ? 'true' : 'false') : $v,
            $query,
        );
    }
}
