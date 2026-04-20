<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a vendor (sub-merchant) in a marketplace or split-payment setup.
 */
final readonly class Vendor
{
    public function __construct(
        public string  $id,

        /** Display name */
        public string  $name,

        /** Unique vendor code / external reference */
        public string  $code,

        /**
         * Vendor status.
         * Possible values: active, inactive, suspended
         */
        public string  $status,

        public ?string $email,
        public ?string $phone,

        /** ICE (Identifiant Commun de l'Entreprise) — Moroccan business ID */
        public ?string $ice,

        /** IBAN or Moroccan RIB for settlements */
        public ?string $iban,

        /** Total settled amount in centimes */
        public int     $settledAmount,

        /** Pending settlement amount in centimes */
        public int     $pendingAmount,

        public array   $metadata,

        public string  $createdAt,
        public string  $updatedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:             (string) $data['id'],
            name:           (string) ($data['name']   ?? ''),
            code:           (string) ($data['code']   ?? ''),
            status:         (string) ($data['status'] ?? 'active'),
            email:          isset($data['email']) ? (string) $data['email'] : null,
            phone:          isset($data['phone']) ? (string) $data['phone'] : null,
            ice:            isset($data['ice'])   ? (string) $data['ice']   : null,
            iban:           isset($data['iban'])  ? (string) $data['iban']  : null,
            settledAmount:  (int) ($data['settledAmount']  ?? 0),
            pendingAmount:  (int) ($data['pendingAmount']  ?? 0),
            metadata:       (array) ($data['metadata']     ?? []),
            createdAt:      (string) ($data['createdAt'] ?? $data['created_at'] ?? ''),
            updatedAt:      (string) ($data['updatedAt'] ?? $data['updated_at'] ?? ''),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
