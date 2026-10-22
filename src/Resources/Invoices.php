<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\Invoice;
use Aslan\Payment\Dto\PaginatedResponse;

/**
 * Invoices resource — factures and avoirs issued for billing attempts.
 *
 * @see https://docs.aslanpay.ma/api/billing
 */
final class Invoices
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * List invoices.
     *
     * Query params:
     *  - page (int, default 1)
     *  - pageSize (int, default 20, max 100)
     *  - kind (string) — facture | avoir
     *  - year (int)
     *
     * @param  array<string, mixed> $query
     * @return PaginatedResponse<Invoice>
     */
    public function list(array $query = []): PaginatedResponse
    {
        $response = $this->client->request('GET', '/api/v1/billing/invoices', [], [], $query);

        return PaginatedResponse::fromArray(
            $response,
            static fn (array $item) => Invoice::fromArray($item),
        );
    }

    /**
     * Retrieve an invoice by ID.
     */
    public function retrieve(string $id): Invoice
    {
        $response = $this->client->request('GET', '/api/v1/billing/invoices/' . rawurlencode($id));

        return Invoice::fromArray($response['data'] ?? $response);
    }

    /**
     * The invoice rendered as a PDF.
     *
     * @return string Raw PDF bytes
     */
    public function pdf(string $id): string
    {
        return $this->client->requestRaw(
            'GET',
            '/api/v1/billing/invoices/' . rawurlencode($id) . '/facture.pdf',
            ['Accept' => 'application/pdf'],
        );
    }
}
