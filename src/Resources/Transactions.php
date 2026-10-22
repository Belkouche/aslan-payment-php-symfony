<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\PaginatedResponse;
use Aslan\Payment\Dto\Transaction;

/**
 * Transactions resource.
 *
 * Transactions are read-only from the merchant side; they are created
 * automatically when a customer completes a checkout session or payment link.
 *
 * @see https://docs.aslanpay.ma/api/transactions
 */
final class Transactions
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * List transactions with optional filters and pagination.
     *
     * Query params:
     *  - page (int, default 1)
     *  - pageSize (int, default 20, max 100)
     *  - status (string) — pending | processing | succeeded | failed | expired | refunded | partially_refunded
     *  - from (string) — ISO 8601 start date
     *  - to (string) — ISO 8601 end date
     *  - sortBy (string) — createdAt | amount | status (default createdAt)
     *  - sortOrder (string) — asc | desc (default desc)
     *
     * @param  array<string, mixed> $query
     * @return PaginatedResponse<Transaction>
     */
    public function list(array $query = []): PaginatedResponse
    {
        $response = $this->client->request('GET', '/api/v1/transactions', [], [], $query);

        return PaginatedResponse::fromArray(
            $response,
            static fn (array $item) => Transaction::fromArray($item),
        );
    }

    /**
     * Retrieve a single transaction by ID.
     */
    public function retrieve(string $id): Transaction
    {
        $response = $this->client->request('GET', '/api/v1/transactions/' . $id);

        return Transaction::fromArray($response['data'] ?? $response);
    }
}
