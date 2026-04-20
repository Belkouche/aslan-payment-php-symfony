<?php

declare(strict_types=1);

namespace Aslan\Payment;

use Aslan\Payment\HttpClient\HttpClientInterface;
use Aslan\Payment\HttpClient\SymfonyHttpClient;
use Aslan\Payment\Resources\ApiKeys;
use Aslan\Payment\Resources\CheckoutSessions;
use Aslan\Payment\Resources\Customers;
use Aslan\Payment\Resources\PaymentLinks;
use Aslan\Payment\Resources\Refunds;
use Aslan\Payment\Resources\Transactions;
use Aslan\Payment\Resources\Vendors;
use Aslan\Payment\Resources\Webhooks;
use Aslan\Payment\Resources\WebhooksConfig;

/**
 * Aslan Payment SDK — main entry point.
 *
 * Usage:
 *
 *   $aslan = new Aslan\Payment\Aslan('sk_live_xxx');
 *
 *   // Create a checkout session
 *   $session = $aslan->checkoutSessions->create([
 *       'amount'     => 5000,   // 50.00 MAD
 *       'currency'   => 'MAD',
 *       'reference'  => 'order_123',
 *       'successUrl' => 'https://example.com/thanks',
 *       'cancelUrl'  => 'https://example.com/cart',
 *   ]);
 *
 *   header('Location: ' . $session->url);
 *
 * @see https://docs.aslanpay.ma
 */
final class Aslan
{
    // ------------------------------------------------------------------
    // Resource accessors (public readonly)
    // ------------------------------------------------------------------

    public readonly CheckoutSessions $checkoutSessions;
    public readonly Transactions     $transactions;
    public readonly PaymentLinks     $paymentLinks;
    public readonly Refunds          $refunds;
    public readonly Vendors          $vendors;
    public readonly Customers        $customers;
    public readonly ApiKeys          $apiKeys;
    public readonly WebhooksConfig   $webhooksConfig;

    // ------------------------------------------------------------------
    // Internal HTTP client
    // ------------------------------------------------------------------

    private readonly HttpClientInterface $http;

    // ------------------------------------------------------------------
    // Constructor
    // ------------------------------------------------------------------

    /**
     * @param  string                  $secretKey   API key starting with sk_live_ or sk_test_
     * @param  string                  $baseUrl     Override the default API base URL
     * @param  int                     $timeout     Request timeout in seconds
     * @param  int                     $maxRetries  Number of automatic retries on transient errors
     * @param  HttpClientInterface|null $httpClient  Provide your own HTTP client (e.g. for testing)
     *
     * @throws AslanException AUTHENTICATION_ERROR if the key format is invalid
     */
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.aslanpay.ma/pay',
        private readonly int $timeout = 30,
        private readonly int $maxRetries = 2,
        ?HttpClientInterface $httpClient = null,
    ) {
        if (!str_starts_with($secretKey, 'sk_live_') && !str_starts_with($secretKey, 'sk_test_')) {
            throw new AslanException(
                'AUTHENTICATION_ERROR',
                'Invalid secret key format. Must start with sk_live_ or sk_test_.'
            );
        }

        $this->http = $httpClient ?? new SymfonyHttpClient(
            secretKey:  $secretKey,
            timeout:    $timeout,
            maxRetries: $maxRetries,
        );

        $this->checkoutSessions = new CheckoutSessions($this);
        $this->transactions     = new Transactions($this);
        $this->paymentLinks     = new PaymentLinks($this);
        $this->refunds          = new Refunds($this);
        $this->vendors          = new Vendors($this);
        $this->customers        = new Customers($this);
        $this->apiKeys          = new ApiKeys($this);
        $this->webhooksConfig   = new WebhooksConfig($this);
    }

    // ------------------------------------------------------------------
    // Static helpers
    // ------------------------------------------------------------------

    /**
     * Build a webhook verifier without instantiating a full client.
     *
     * Use this in your webhook handler to verify and parse incoming events:
     *
     *   $event = Aslan::webhooks($_ENV['ASLAN_WEBHOOK_SECRET'])
     *       ->constructEvent(
     *           file_get_contents('php://input'),
     *           $_SERVER['HTTP_ASLAN_SIGNATURE'] ?? ''
     *       );
     */
    public static function webhooks(string $webhookSecret): Webhooks
    {
        return new Webhooks($webhookSecret);
    }

    // ------------------------------------------------------------------
    // Accessors / convenience
    // ------------------------------------------------------------------

    public function isTestMode(): bool
    {
        return str_starts_with($this->secretKey, 'sk_test_');
    }

    public function isLiveMode(): bool
    {
        return str_starts_with($this->secretKey, 'sk_live_');
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    // ------------------------------------------------------------------
    // Internal HTTP dispatch (called by resource classes)
    // ------------------------------------------------------------------

    /**
     * Perform an API request.
     *
     * Resource classes call this method — do not call it from application code.
     *
     * @param  string               $method   HTTP verb
     * @param  string               $path     API path (e.g. "/api/v1/checkout/sessions")
     * @param  array<string, mixed> $body     Request body (ignored for GET)
     * @param  array<string, string> $headers  Extra request headers
     * @param  array<string, mixed> $query    Query-string parameters
     *
     * @return array<string, mixed>
     *
     * @throws AslanException
     *
     * @internal
     */
    public function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        array $query = [],
    ): array {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        return $this->http->request($method, $url, $body, $headers, $query);
    }
}
