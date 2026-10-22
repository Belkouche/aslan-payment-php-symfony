<?php

declare(strict_types=1);

namespace Aslan\Payment\Dto;

/**
 * Result of creating a subscription (the INITIAL flow).
 *
 * The subscription starts as `pending_initial`. Send the customer's browser to
 * the hosted Paywall to save their card with 3-D Secure: the Paywall expects
 * an HTML form POST of `payload` + `signature` to `redirectUrl` — use
 * autoSubmitFormHtml() to render it.
 */
final readonly class SubscriptionCheckout
{
    public function __construct(
        public string $subscriptionId,

        /** Transaction of the initial charge (reported by payment.succeeded) */
        public string $transactionId,

        /** Hosted Paywall URL (the form action) */
        public string $redirectUrl,

        /**
         * Paywall form fields: ['payload' => string, 'signature' => string]
         * @var array<string, mixed>
         */
        public array  $paywall,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            subscriptionId: (string) ($data['subscriptionId'] ?? ''),
            transactionId:  (string) ($data['transactionId']  ?? ''),
            redirectUrl:    (string) ($data['redirectUrl']    ?? ''),
            paywall:        (array)  ($data['paywall']        ?? []),
        );
    }

    /**
     * Render a minimal HTML page that auto-POSTs the Paywall form.
     * Echo it as the response body to send the customer to the Paywall.
     */
    public function autoSubmitFormHtml(): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $inputs = '';
        foreach ($this->paywall as $name => $value) {
            if (is_scalar($value)) {
                $inputs .= '<input type="hidden" name="' . $e($name) . '" value="' . $e($value) . '">';
            }
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer">'
            . '<title>Redirecting to secure payment</title></head><body>'
            . '<form id="aslan-paywall" method="post" action="' . $e($this->redirectUrl) . '">' . $inputs
            . '<noscript><button type="submit">Continue to payment</button></noscript></form>'
            . '<script>document.getElementById("aslan-paywall").submit()</script></body></html>';
    }
}
