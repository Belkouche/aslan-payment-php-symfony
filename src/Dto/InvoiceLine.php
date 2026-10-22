<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * A line on a receipt or facture. Amounts in centimes, VAT in basis points.
 */
final readonly class InvoiceLine
{
    public function __construct(
        public string $description,
        public int    $qty,

        /** Unit price excl. VAT, in centimes */
        public int    $unitHt,

        /** VAT rate in basis points (2000 = 20.00%) */
        public int    $tvaRateBps,

        /** Line total incl. VAT, in centimes */
        public int    $totalTtc,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            description: (string) ($data['description'] ?? ''),
            qty:         (int)    ($data['qty']         ?? 1),
            unitHt:      (int)    ($data['unitHt']      ?? 0),
            tvaRateBps:  (int)    ($data['tvaRateBps']  ?? 0),
            totalTtc:    (int)    ($data['totalTtc']    ?? 0),
        );
    }
}
