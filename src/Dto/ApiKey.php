<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a merchant API key.
 *
 * IMPORTANT: The full secret value is only returned once, at creation time.
 * Subsequent retrieve / list calls will only include the $prefix.
 */
final readonly class ApiKey
{
    public function __construct(
        public string  $id,

        /** Human-readable label for this key */
        public string  $name,

        /**
         * Key environment.
         * Possible values: live, test
         */
        public string  $environment,

        /**
         * The lookup prefix (e.g. "abc123").
         * The full key format is: sk_{environment}_{prefix}_{secret}
         */
        public string  $prefix,

        /**
         * The complete secret key — only present immediately after creation.
         * Null on all subsequent requests; store it securely right away.
         */
        public ?string $secretKey,

        /**
         * Key status.
         * Possible values: active, revoked
         */
        public string  $status,

        /** Timestamp of last successful use, or null if never used */
        public ?string $lastUsedAt,

        public string  $createdAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:          (string) $data['id'],
            name:        (string) ($data['name']        ?? ''),
            environment: (string) ($data['environment'] ?? 'live'),
            prefix:      (string) ($data['prefix']      ?? ''),
            secretKey:   isset($data['secretKey']) ? (string) $data['secretKey'] : null,
            status:      (string) ($data['status']      ?? 'active'),
            lastUsedAt:  isset($data['lastUsedAt']) ? (string) $data['lastUsedAt'] : null,
            createdAt:   (string) ($data['createdAt'] ?? $data['created_at'] ?? ''),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLive(): bool
    {
        return $this->environment === 'live';
    }

    public function isTest(): bool
    {
        return $this->environment === 'test';
    }
}
