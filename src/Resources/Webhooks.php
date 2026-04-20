<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\AslanException;
use Aslan\Payment\Dto\WebhookEvent;

/**
 * Webhook verification helper.
 *
 * Use via the static factory on the main client:
 *
 *   $event = Aslan::webhooks($webhookSecret)->constructEvent(
 *       $rawBody,
 *       $request->headers->get('Aslan-Signature')
 *   );
 *
 * The signature header format is:  t=<unix_timestamp>,v1=<hmac_hex>
 *
 * The signed payload is:  "{timestamp}.{rawBody}"
 *
 * HMAC algorithm: SHA-256
 *
 * @see https://docs.aslanpay.ma/webhooks/signatures
 */
final class Webhooks
{
    /** Signature header name */
    public const SIGNATURE_HEADER = 'Aslan-Signature';

    /** Default tolerance window for the timestamp (in seconds) */
    public const DEFAULT_TOLERANCE = 300;

    public function __construct(private readonly string $webhookSecret) {}

    /**
     * Parse and verify a webhook delivery.
     *
     * @param  string      $rawBody      The raw, un-decoded request body string
     * @param  string      $sigHeader    Value of the "Aslan-Signature" header
     * @param  int         $tolerance    Maximum age of the event in seconds (0 = no check)
     *
     * @return WebhookEvent              Verified, parsed event object
     *
     * @throws AslanException            WEBHOOK_SIGNATURE_ERROR on any verification failure
     */
    public function constructEvent(
        string $rawBody,
        string $sigHeader,
        int $tolerance = self::DEFAULT_TOLERANCE,
    ): WebhookEvent {
        $parts = $this->parseSignatureHeader($sigHeader);

        $timestamp  = $parts['t'];
        $signatures = $parts['v1'];

        if (empty($signatures)) {
            throw AslanException::webhookSignatureError(
                'No v1 signatures found in the Aslan-Signature header.'
            );
        }

        // Verify timestamp freshness to prevent replay attacks
        if ($tolerance > 0) {
            $age = abs(time() - $timestamp);
            if ($age > $tolerance) {
                throw AslanException::webhookSignatureError(
                    sprintf(
                        'Webhook timestamp is too old (%d seconds). Tolerance is %d seconds.',
                        $age,
                        $tolerance,
                    )
                );
            }
        }

        // Compute expected signature
        $signedPayload    = $timestamp . '.' . $rawBody;
        $expectedSig      = hash_hmac('sha256', $signedPayload, $this->webhookSecret);

        // Compare against all v1 signatures (timing-safe)
        $verified = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expectedSig, $sig)) {
                $verified = true;
                break;
            }
        }

        if (!$verified) {
            throw AslanException::webhookSignatureError(
                'Webhook signature mismatch. Ensure the raw body and signing secret are correct.'
            );
        }

        // Decode and hydrate the payload
        $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($payload)) {
            throw AslanException::webhookSignatureError(
                'Webhook payload is not a valid JSON object.'
            );
        }

        return WebhookEvent::fromArray($payload);
    }

    /**
     * Generate a valid Aslan-Signature header value for the given payload.
     *
     * Useful for testing your webhook handler: you can simulate incoming
     * Aslan webhook deliveries without hitting the real API.
     *
     * @param  string   $payload   Raw JSON payload string
     * @param  int|null $timestamp Unix timestamp (defaults to now)
     */
    public function generateSignatureHeader(string $payload, ?int $timestamp = null): string
    {
        $timestamp    ??= time();
        $signedPayload  = $timestamp . '.' . $payload;
        $signature      = hash_hmac('sha256', $signedPayload, $this->webhookSecret);

        return 't=' . $timestamp . ',v1=' . $signature;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Parse the "t=...,v1=...[,v1=...]" header format.
     *
     * @return array{t: int, v1: string[]}
     *
     * @throws AslanException
     */
    private function parseSignatureHeader(string $header): array
    {
        if (trim($header) === '') {
            throw AslanException::webhookSignatureError(
                'Aslan-Signature header is empty or missing.'
            );
        }

        $result = ['t' => 0, 'v1' => []];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $eqPos = strpos($part, '=');
            if ($eqPos === false) {
                continue;
            }

            $key   = substr($part, 0, $eqPos);
            $value = substr($part, $eqPos + 1);

            match ($key) {
                't'  => $result['t']    = (int) $value,
                'v1' => $result['v1'][] = $value,
                default => null,  // Ignore unknown fields for forward-compatibility
            };
        }

        if ($result['t'] === 0) {
            throw AslanException::webhookSignatureError(
                'Aslan-Signature header is missing the timestamp (t=) field.'
            );
        }

        return $result;
    }
}
