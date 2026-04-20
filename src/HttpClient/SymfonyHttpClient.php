<?php

declare(strict_types=1);

namespace Aslan\Payment\HttpClient;

use Aslan\Payment\AslanException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as SymfonyHttpClientInterface;

/**
 * Symfony HttpClient adapter for the Aslan Payment SDK.
 *
 * Features:
 *  - Automatic JSON encoding / decoding
 *  - Authorization & User-Agent headers injected on every request
 *  - Retry logic with exponential back-off on transient errors
 *    (408, 429, 500, 502, 503, 504) — up to $maxRetries attempts
 *  - Maps HTTP 4xx / 5xx responses to typed AslanException instances
 */
final class SymfonyHttpClient implements HttpClientInterface
{
    private const SDK_VERSION = '0.1.0';

    /** HTTP status codes that should trigger a retry */
    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    /** Base delay in milliseconds for the first retry */
    private const BASE_DELAY_MS = 500;

    /** Maximum delay cap in milliseconds */
    private const MAX_DELAY_MS = 5_000;

    private readonly SymfonyHttpClientInterface $client;

    public function __construct(
        private readonly string $secretKey,
        private readonly int $timeout = 30,
        private readonly int $maxRetries = 2,
        ?SymfonyHttpClientInterface $client = null,
    ) {
        $this->client = $client ?? HttpClient::create([
            'timeout' => $timeout,
        ]);
    }

    // ------------------------------------------------------------------
    // HttpClientInterface
    // ------------------------------------------------------------------

    public function request(
        string $method,
        string $url,
        array $body = [],
        array $headers = [],
        array $query = [],
    ): array {
        $defaultHeaders = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'User-Agent'    => 'aslan-payment-php/' . self::SDK_VERSION,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];

        $mergedHeaders = array_merge($defaultHeaders, $headers);

        $options = [
            'headers' => $mergedHeaders,
        ];

        if (!empty($query)) {
            $options['query'] = $query;
        }

        if (!empty($body) && strtoupper($method) !== 'GET') {
            $options['json'] = $body;
        }

        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                // Exponential back-off: 500ms, 1000ms, …, capped at 5000ms
                $delayMs = min(self::BASE_DELAY_MS * (2 ** ($attempt - 1)), self::MAX_DELAY_MS);
                usleep($delayMs * 1_000);
            }

            try {
                $response    = $this->client->request($method, $url, $options);
                $statusCode  = $response->getStatusCode();
                $requestId   = $response->getHeaders(false)['x-request-id'][0] ?? null;
                $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

                // Attempt to decode JSON regardless of status so we can surface
                // structured error messages from the API.
                $decoded = [];
                if (str_contains($contentType, 'application/json')) {
                    try {
                        $decoded = $response->toArray(false);
                    } catch (\Throwable) {
                        // Fall through with empty array — raw body may not be valid JSON.
                    }
                }

                if ($statusCode >= 200 && $statusCode < 300) {
                    return $decoded;
                }

                // Check if we should retry transient server errors
                if (in_array($statusCode, self::RETRYABLE_STATUSES, true) && $attempt < $this->maxRetries) {
                    $lastException = AslanException::fromApiResponse($statusCode, $decoded, $requestId);
                    continue;
                }

                throw $this->buildException($statusCode, $decoded, $requestId);
            } catch (TransportExceptionInterface $e) {
                $lastException = AslanException::networkError(
                    'Network error communicating with Aslan API: ' . $e->getMessage(),
                    $e
                );

                if ($attempt < $this->maxRetries) {
                    continue;
                }

                throw $lastException;
            } catch (AslanException $e) {
                throw $e;
            }
        }

        // All retries exhausted — re-throw the last recorded exception
        throw $lastException ?? AslanException::serverError('Request failed after ' . $this->maxRetries . ' retries');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function buildException(int $httpStatus, array $body, ?string $requestId): AslanException
    {
        return match (true) {
            $httpStatus === 401 => AslanException::authenticationError(
                $body['message'] ?? 'Authentication failed',
                $httpStatus,
                $requestId,
            ),
            $httpStatus === 403 => AslanException::authorizationError(
                $body['message'] ?? 'Forbidden',
                $httpStatus,
                $requestId,
            ),
            $httpStatus === 404 => AslanException::notFound(
                $body['message'] ?? 'Resource not found',
                $httpStatus,
                $requestId,
            ),
            $httpStatus === 409 => AslanException::conflict(
                $body['message'] ?? 'Conflict',
                $httpStatus,
                $requestId,
            ),
            $httpStatus === 422 => AslanException::validationError(
                $body['message'] ?? 'Validation failed',
                $body['details'] ?? $body['errors'] ?? [],
                $httpStatus,
                $requestId,
            ),
            $httpStatus === 429 => AslanException::rateLimitError(
                $body['message'] ?? 'Too many requests',
                $httpStatus,
                $requestId,
            ),
            $httpStatus >= 500  => AslanException::fromApiResponse($httpStatus, $body, $requestId),
            default             => AslanException::fromApiResponse($httpStatus, $body, $requestId),
        };
    }
}
