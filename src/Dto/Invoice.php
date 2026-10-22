<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * An immutable legal document: a facture (invoice) or an avoir (credit note).
 *
 * Amounts are in centimes (100 = 1.00 MAD).
 */
final readonly class Invoice
{
    /**
     * @param array<string, mixed> $issuerSnapshot   Issuer identity frozen at issuance
     * @param array<string, mixed> $customerSnapshot Customer identity frozen at issuance
     * @param InvoiceLine[]        $lines
     */
    public function __construct(
        public string  $id,
        public string  $merchantId,
        public ?string $billingAttemptId,

        /** facture | avoir */
        public string  $kind,

        /** Sequential number, e.g. "FAC-2026-00001" */
        public string  $number,

        public int     $year,

        /** issued | cancelled */
        public string  $status,

        public array   $issuerSnapshot,
        public array   $customerSnapshot,
        public array   $lines,
        public string  $currency,
        public int     $totalHt,
        public int     $totalTva,
        public int     $totalTtc,
        public string  $issuedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:               (string) $data['id'],
            merchantId:       (string) ($data['merchantId'] ?? ''),
            billingAttemptId: isset($data['billingAttemptId']) ? (string) $data['billingAttemptId'] : null,
            kind:             (string) ($data['kind']   ?? 'facture'),
            number:           (string) ($data['number'] ?? ''),
            year:             (int)    ($data['year']   ?? 0),
            status:           (string) ($data['status'] ?? 'issued'),
            issuerSnapshot:   (array)  ($data['issuerSnapshot']   ?? []),
            customerSnapshot: (array)  ($data['customerSnapshot'] ?? []),
            lines:            array_map(
                static fn (array $line) => InvoiceLine::fromArray($line),
                array_values(array_filter((array) ($data['lines'] ?? []), 'is_array')),
            ),
            currency:         (string) ($data['currency'] ?? 'MAD'),
            totalHt:          (int)    ($data['totalHt']  ?? 0),
            totalTva:         (int)    ($data['totalTva'] ?? 0),
            totalTtc:         (int)    ($data['totalTtc'] ?? 0),
            issuedAt:         (string) ($data['issuedAt'] ?? ''),
        );
    }
}
