<?php

declare(strict_types=1);

namespace Aslan\Payment;

/**
 * Base exception class for all Aslan Payment SDK errors.
 *
 * Error codes:
 *  - AUTHENTICATION_ERROR   — invalid or missing API key
 *  - AUTHORIZATION_ERROR    — authenticated but not allowed
 *  - VALIDATION_ERROR       — request payload failed validation
 *  - NOT_FOUND              — resource does not exist
 *  - CONFLICT               — idempotency key reuse with different params
 *  - RATE_LIMIT_ERROR       — too many requests
 *  - GATEWAY_ERROR          — upstream payment provider error
 *  - SERVER_ERROR           — unexpected server-side error (5xx)
 *  - NETWORK_ERROR          — connection / timeout failure
 *  - WEBHOOK_SIGNATURE_ERROR — webhook signature verification failed
 *  - UNSUPPORTED_OPERATION  — the configured HTTP client lacks a needed capability
 */
final class AslanException extends \RuntimeException
{
    /**
     * @param string $errorCode  Machine-readable API error code (see list above).
     *                           getCode() returns the HTTP status (int, 0 when none).
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 0,
        public readonly array $details = [],
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * Machine-readable error code, e.g. "VALIDATION_ERROR".
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    // ------------------------------------------------------------------
    // Factory helpers
    // ------------------------------------------------------------------

    public static function authenticationError(string $message = 'Authentication failed', int $httpStatus = 401, ?string $requestId = null): self
    {
        return new self('AUTHENTICATION_ERROR', $message, $httpStatus, [], $requestId);
    }

    public static function authorizationError(string $message = 'You do not have permission to perform this action', int $httpStatus = 403, ?string $requestId = null): self
    {
        return new self('AUTHORIZATION_ERROR', $message, $httpStatus, [], $requestId);
    }

    public static function validationError(string $message, array $details = [], int $httpStatus = 422, ?string $requestId = null): self
    {
        return new self('VALIDATION_ERROR', $message, $httpStatus, $details, $requestId);
    }

    public static function notFound(string $message = 'Resource not found', int $httpStatus = 404, ?string $requestId = null): self
    {
        return new self('NOT_FOUND', $message, $httpStatus, [], $requestId);
    }

    public static function conflict(string $message = 'Conflict', int $httpStatus = 409, ?string $requestId = null): self
    {
        return new self('CONFLICT', $message, $httpStatus, [], $requestId);
    }

    public static function rateLimitError(string $message = 'Too many requests. Please retry after a moment.', int $httpStatus = 429, ?string $requestId = null): self
    {
        return new self('RATE_LIMIT_ERROR', $message, $httpStatus, [], $requestId);
    }

    public static function gatewayError(string $message, array $details = [], int $httpStatus = 502, ?string $requestId = null): self
    {
        return new self('GATEWAY_ERROR', $message, $httpStatus, $details, $requestId);
    }

    public static function serverError(string $message = 'An unexpected server error occurred', int $httpStatus = 500, ?string $requestId = null): self
    {
        return new self('SERVER_ERROR', $message, $httpStatus, [], $requestId);
    }

    public static function networkError(string $message, ?\Throwable $previous = null): self
    {
        return new self('NETWORK_ERROR', $message, 0, [], null, $previous);
    }

    public static function webhookSignatureError(string $message = 'Webhook signature verification failed'): self
    {
        return new self('WEBHOOK_SIGNATURE_ERROR', $message, 0);
    }

    /**
     * Build the appropriate exception from a decoded API error response.
     */
    public static function fromApiResponse(int $httpStatus, array $body, ?string $requestId = null): self
    {
        $code    = (string) ($body['code'] ?? 'SERVER_ERROR');
        $message = $body['message'] ?? 'An error occurred';
        $details = $body['details'] ?? $body['errors'] ?? [];

        return new self($code, $message, $httpStatus, $details, $requestId);
    }
}
