<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Represents the merchant webhook configuration.
 *
 * Only one webhook endpoint is supported per merchant.
 * The signing secret is returned only at creation / rotation time.
 */
final readonly class WebhookConfig
{
    public function __construct(
        public string  $id,

        /** HTTPS URL Aslan sends events to */
        public string  $url,

        /**
         * Config status.
         * Possible values: active, inactive
         */
        public string  $status,

        /**
         * List of event types this endpoint subscribes to.
         * An empty array means all events are delivered.
         * @var string[]
         */
        public array   $events,

        /**
         * HMAC-SHA256 signing secret — only returned at creation / rotation.
         * Null on subsequent fetches; store it securely right away.
         */
        public ?string $signingSecret,

        /** API version used to format event payloads */
        public string  $apiVersion,

        public string  $createdAt,
        public string  $updatedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:            (string) $data['id'],
            url:           (string) ($data['url']        ?? ''),
            status:        (string) ($data['status']     ?? 'active'),
            events:        (array)  ($data['events']     ?? []),
            signingSecret: isset($data['signingSecret']) ? (string) $data['signingSecret'] : null,
            apiVersion:    (string) ($data['apiVersion'] ?? $data['api_version'] ?? '2024-01'),
            createdAt:     (string) ($data['createdAt']  ?? $data['created_at'] ?? ''),
            updatedAt:     (string) ($data['updatedAt']  ?? $data['updated_at'] ?? ''),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Returns true when this endpoint subscribes to the given event type.
     * An empty events array means the endpoint receives all events.
     */
    public function subscribesTo(string $eventType): bool
    {
        if (empty($this->events)) {
            return true;
        }

        return in_array($eventType, $this->events, true);
    }
}
