<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\PaginatedResponse;
use Aslan\Payment\Dto\Refund;

/**
 * Refunds resource.
 *
 * Issue full or partial refunds against captured transactions.
 * The refunded amount must not exceed the transaction's remaining
 * refundable amount.
 *
 * @see https://docs.aslanpay.ma/api/refunds
 */
final class Refunds
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * Issue a refund against a captured transaction.
     *
     * Required params:
     *  - transactionId (string)
     *  - amount (int, centimes) — partial refund; omit for full refund
     *
     * Optional params:
     *  - reason (string) — duplicate | fraudulent | customer_request | other
     *  - note (string) — internal note, not shown to customer
     *  - metadata (array)
     *
     * @param  array<string, mixed> $params
     * @param  string|null          $idempotencyKey Use the same key to safely retry
     */
    public function create(array $params, ?string $idempotencyKey = null): Refund
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->client->request('POST', '/api/v1/refunds', $params, $headers);

        return Refund::fromArray($response['data'] ?? $response);
    }

    /**
     * List refunds with optional filters.
     *
     * Query params:
     *  - page (int, default 1)
     *  - pageSize (int, default 20, max 100)
     *  - transactionId (string)
     *  - status (string) — pending | succeeded | failed
     *  - from (string, ISO 8601)
     *  - to (string, ISO 8601)
     *
     * @param  array<string, mixed>  $query
     * @return PaginatedResponse<Refund>
     */
    public function list(array $query = []): PaginatedResponse
    {
        $response = $this->client->request('GET', '/api/v1/refunds', [], [], $query);

        return PaginatedResponse::fromArray(
            $response,
            static fn (array $item) => Refund::fromArray($item),
        );
    }

    /**
     * Retrieve a single refund by ID.
     */
    public function retrieve(string $id): Refund
    {
        $response = $this->client->request('GET', '/api/v1/refunds/' . $id);

        return Refund::fromArray($response['data'] ?? $response);
    }
}
