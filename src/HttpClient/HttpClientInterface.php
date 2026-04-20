<?php

declare(strict_types=1);

namespace Aslan\Payment\HttpClient;

/**
 * Minimal HTTP client interface for the Aslan Payment SDK.
 *
 * Implementations must handle:
 *  - JSON encoding of the request body
 *  - JSON decoding of the response body
 *  - Propagation of HTTP errors as AslanException instances
 *
 * The returned array is always the decoded JSON body of a successful response.
 */
interface HttpClientInterface
{
    /**
     * Perform an HTTP request.
     *
     * @param  string               $method  HTTP verb (GET, POST, PATCH, DELETE)
     * @param  string               $url     Full URL (base URL + path already joined)
     * @param  array<string, mixed> $body    Request body (will be JSON-encoded for non-GET requests)
     * @param  array<string, string> $headers Additional headers to merge into the request
     * @param  array<string, mixed> $query   Query-string parameters
     *
     * @return array<string, mixed> Decoded JSON response body
     *
     * @throws \Aslan\Payment\AslanException On any HTTP or network error
     */
    public function request(
        string $method,
        string $url,
        array $body = [],
        array $headers = [],
        array $query = [],
    ): array;
}
