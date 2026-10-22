<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents a merchant API key.
 *
 * IMPORTANT: The full secret value is only returned once, at creation time.
 * Subsequent list calls only include the $prefix.
 */
final readonly class ApiKey
{
    public function __construct(
        public string  $id,

        /** Human-readable label for this key */
        public string  $name,

        /** @deprecated Use $type (same value). */
        public string  $environment,

        /**
         * The lookup prefix.
         * The full key format is: sk_{type}_{prefix}_{secret}
         */
        public string  $prefix,

        /**
         * The complete secret key — only present immediately after creation.
         * Null on all subsequent requests; store it securely right away.
         */
        public ?string $secretKey,

        /**
         * Derived from the API's `isActive` flag.
         * Possible values: active, revoked
         */
        public string  $status,

        /** Timestamp of last successful use, or null if never used */
        public ?string $lastUsedAt,

        public string  $createdAt,

        /** Key mode: live | test (the API's `type`) */
        public string  $type = 'live',

        /** Publishable key (pk_...) issued with this key, if any */
        public ?string $publishableKey = null,

        /** ISO 8601 revocation time, or null */
        public ?string $revokedAt = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type   = (string) ($data['type'] ?? $data['environment'] ?? 'live');
        $active = array_key_exists('isActive', $data)
            ? (bool) $data['isActive']
            : (($data['status'] ?? 'active') === 'active');

        return new self(
            id:             (string) $data['id'],
            name:           (string) ($data['name']   ?? ''),
            environment:    $type,
            prefix:         (string) ($data['prefix'] ?? ''),
            secretKey:      isset($data['secretKey'])  ? (string) $data['secretKey']  : null,
            status:         $active ? 'active' : 'revoked',
            lastUsedAt:     isset($data['lastUsedAt']) ? (string) $data['lastUsedAt'] : null,
            createdAt:      (string) ($data['createdAt'] ?? ''),
            type:           $type,
            publishableKey: isset($data['publishableKey']) ? (string) $data['publishableKey'] : null,
            revokedAt:      isset($data['revokedAt']) ? (string) $data['revokedAt'] : null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLive(): bool
    {
        return $this->type === 'live';
    }

    public function isTest(): bool
    {
        return $this->type === 'test';
    }
}
