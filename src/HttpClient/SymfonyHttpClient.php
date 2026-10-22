<?php

declare(strict_types=1);

namespace Aslan\Payment\HttpClient;

use Aslan\Payment\AslanException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as SymfonyHttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Symfony HttpClient adapter for the Aslan Payment SDK.
 *
 * Features:
 *  - Automatic JSON encoding / decoding (plus raw bodies for PDF downloads)
 *  - Authorization & User-Agent headers injected on every request
 *  - Retry logic with exponential back-off on transient errors
 *    (408, 429, 500, 502, 503, 504) — up to $maxRetries attempts
 *  - Maps HTTP 4xx / 5xx responses to typed AslanException instances
 */
final class SymfonyHttpClient implements HttpClientInterface, RawHttpClientInterface
{
    private const SDK_VERSION = '0.2.0';

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
        $options = [];

        // Only send a JSON body (and therefore a JSON Content-Type) when there is
        // one: the API rejects an empty body declared as application/json, which
        // would break body-less POST / DELETE calls (e.g. subscription pause).
        if (!empty($body) && strtoupper($method) !== 'GET') {
            $options['json'] = $body;
        }

        $response = $this->send($method, $url, $headers, $query, $options);

        return $this->decodeJson($response);
    }

    // ------------------------------------------------------------------
    // RawHttpClientInterface
    // ------------------------------------------------------------------

    public function requestRaw(
        string $method,
        string $url,
        array $headers = [],
        array $query = [],
    ): string {
        $response = $this->send($method, $url, $headers, $query, []);

        try {
            return $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw AslanException::networkError(
                'Network error communicating with Aslan API: ' . $e->getMessage(),
                $e
            );
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Send a request with retries and return the successful (2xx) response.
     *
     * @param  array<string, string> $headers
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options Extra Symfony request options (e.g. json)
     *
     * @throws AslanException
     */
    private function send(string $method, string $url, array $headers, array $query, array $options): ResponseInterface
    {
        $defaultHeaders = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'User-Agent'    => 'aslan-payment-php/' . self::SDK_VERSION,
            'Accept'        => 'application/json',
        ];

        $options['headers'] = array_merge($defaultHeaders, $headers);

        if (!empty($query)) {
            $options['query'] = $query;
        }

        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                // Exponential back-off: 500ms, 1000ms, …, capped at 5000ms
                $delayMs = min(self::BASE_DELAY_MS * (2 ** ($attempt - 1)), self::MAX_DELAY_MS);
                usleep($delayMs * 1_000);
            }

            try {
                $response   = $this->client->request($method, $url, $options);
                $statusCode = $response->getStatusCode();
                $requestId  = $response->getHeaders(false)['x-request-id'][0] ?? null;

                if ($statusCode >= 200 && $statusCode < 300) {
                    return $response;
                }

                // Decode the error body so we can surface structured API messages.
                $decoded = $this->decodeJson($response);

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

    /**
     * Decode a JSON response body; returns [] for non-JSON or invalid JSON.
     *
     * @return array<string, mixed>
     */
    private function decodeJson(ResponseInterface $response): array
    {
        $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

        if (!str_contains($contentType, 'application/json')) {
            return [];
        }

        try {
            return $response->toArray(false);
        } catch (\Throwable) {
            // Raw body may not be valid JSON.
            return [];
        }
    }

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
