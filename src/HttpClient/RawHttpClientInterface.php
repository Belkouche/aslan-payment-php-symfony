<?php

declare(strict_types=1);

namespace Aslan\Payment\HttpClient;

/**
 * Optional capability for HTTP clients that can return a raw (non-JSON)
 * response body — used for binary downloads such as receipt / facture PDFs.
 *
 * The bundled SymfonyHttpClient implements it. Custom HttpClientInterface
 * implementations only need it if the application downloads PDFs.
 */
interface RawHttpClientInterface
{
    /**
     * Perform an HTTP request and return the raw response body.
     *
     * @param  string                $method  HTTP verb
     * @param  string                $url     Full URL (base URL + path already joined)
     * @param  array<string, string> $headers Additional headers to merge into the request
     * @param  array<string, mixed>  $query   Query-string parameters
     *
     * @return string Raw response body (e.g. PDF bytes)
     *
     * @throws \Aslan\Payment\AslanException On any HTTP or network error
     */
    public function requestRaw(
        string $method,
        string $url,
        array $headers = [],
        array $query = [],
    ): string;
}
