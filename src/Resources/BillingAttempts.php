<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\BillingAttempt;
use Aslan\Payment\Dto\DocumentData;
use Aslan\Payment\Dto\Invoice;
use Aslan\Payment\Dto\PaginatedResponse;

/**
 * Billing attempts resource — the individual charges made on subscriptions,
 * plus their receipts and factures.
 *
 * @see https://docs.aslanpay.ma/api/billing
 */
final class BillingAttempts
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * List billing attempts.
     *
     * Query params:
     *  - page (int, default 1)
     *  - pageSize (int, default 20, max 100)
     *  - subscriptionId (string, uuid)
     *  - status (string) — pending | succeeded | failed
     *  - type (string) — initial | renewal | proration
     *
     * @param  array<string, mixed> $query
     * @return PaginatedResponse<BillingAttempt>
     */
    public function list(array $query = []): PaginatedResponse
    {
        $response = $this->client->request('GET', '/api/v1/billing/attempts', [], [], $query);

        return PaginatedResponse::fromArray(
            $response,
            static fn (array $item) => BillingAttempt::fromArray($item),
        );
    }

    /**
     * Receipt data for a succeeded attempt (stateless render, any invoicing mode).
     */
    public function receipt(string $id): DocumentData
    {
        $response = $this->client->request('GET', $this->path($id, 'receipt'));

        return DocumentData::fromArray($response['data'] ?? $response);
    }

    /**
     * Receipt for a succeeded attempt rendered as a PDF.
     *
     * @return string Raw PDF bytes
     */
    public function receiptPdf(string $id): string
    {
        return $this->client->requestRaw('GET', $this->path($id, 'receipt.pdf'), ['Accept' => 'application/pdf']);
    }

    /**
     * Issue the immutable facture for a succeeded attempt (facture mode only).
     * Idempotent: repeated calls return the same invoice.
     */
    public function facture(string $id): Invoice
    {
        $response = $this->client->request('POST', $this->path($id, 'facture'));

        return Invoice::fromArray($response['data'] ?? $response);
    }

    private function path(string $id, string $action): string
    {
        return '/api/v1/billing/attempts/' . rawurlencode($id) . '/' . $action;
    }
}
