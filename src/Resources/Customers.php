<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\Customer;
use Aslan\Payment\Dto\PaginatedResponse;

/**
 * Customers resource.
 *
 * Customers are read-only from the merchant API. They are automatically
 * created or matched when a payment is completed.
 *
 * @see https://docs.aslanpay.ma/api/customers
 */
final class Customers
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * List customers with optional filters.
     *
     * Query params:
     *  - page (int, default 1)
     *  - pageSize (int, default 20, max 100)
     *  - search (string) — search by email, phone, or name
     *  - from (string, ISO 8601) — filter by createdAt
     *  - to (string, ISO 8601)
     *
     * @param  array<string, mixed>   $query
     * @return PaginatedResponse<Customer>
     */
    public function list(array $query = []): PaginatedResponse
    {
        $response = $this->client->request('GET', '/api/v1/customers', [], [], $query);

        return PaginatedResponse::fromArray(
            $response,
            static fn (array $item) => Customer::fromArray($item),
        );
    }

    /**
     * Retrieve a single customer by ID.
     */
    public function retrieve(string $id): Customer
    {
        $response = $this->client->request('GET', '/api/v1/customers/' . $id);

        return Customer::fromArray($response['data'] ?? $response);
    }
}
