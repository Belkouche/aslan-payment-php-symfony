<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Rendered content of a receipt (GET /billing/attempts/{id}/receipt).
 *
 * Amounts are in centimes (100 = 1.00 MAD).
 */
final readonly class DocumentData
{
    /**
     * @param array<string, mixed> $issuer   legalName, ice, identifiantFiscal, rc, address, logoBase64
     * @param array<string, mixed> $customer name, email
     * @param InvoiceLine[]        $lines
     */
    public function __construct(
        public array  $issuer,
        public array  $customer,
        public array  $lines,
        public string $currency,
        public int    $totalHt,
        public int    $totalTva,
        public int    $totalTtc,
        public string $issuedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            issuer:   (array) ($data['issuer']   ?? []),
            customer: (array) ($data['customer'] ?? []),
            lines:    array_map(
                static fn (array $line) => InvoiceLine::fromArray($line),
                array_values(array_filter((array) ($data['lines'] ?? []), 'is_array')),
            ),
            currency: (string) ($data['currency'] ?? 'MAD'),
            totalHt:  (int)    ($data['totalHt']  ?? 0),
            totalTva: (int)    ($data['totalTva'] ?? 0),
            totalTtc: (int)    ($data['totalTtc'] ?? 0),
            issuedAt: (string) ($data['issuedAt'] ?? ''),
        );
    }
}
