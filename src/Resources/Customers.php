<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\Customer;
use Aslan\Payment\Dto\PaginatedResponse;

/**
 * Customers resource.
 *
 * Create a customer explicitly (e.g. before starting a subscription), or let
 * the API create/match one from the customer email given to a checkout session.
 *
 * @see https://docs.aslanpay.ma/api/customers
 */
final class Customers
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * Create a customer for the authenticated merchant.
     *
     * Params (at least one of email or phone is required):
     *  - email (string) — unique per merchant; a duplicate returns HTTP 409
     *    (AslanException, errorCode CONFLICT)
     *  - phone (string) — Moroccan format +212XXXXXXXXX
     *  - name (string, max 200)
     *  - metadata (array<string, string>)
     *
     * The API does not support an Idempotency-Key on this route.
     *
     * @param  array<string, mixed> $params
     */
    public function create(array $params): Customer
    {
        $response = $this->client->request('POST', '/api/v1/customers', $params);

        return Customer::fromArray($response['data'] ?? $response);
    }

    /**
     * List customers with optional filters.
     *
     * Query params:
     *  - page (int, default 1)
     *  - pageSize (int, default 20, max 100)
     *  - search (string, max 255) — search by email, phone, or name
     *  - sortBy (string) — createdAt | email | name (default createdAt)
     *  - sortOrder (string) — asc | desc (default desc)
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
