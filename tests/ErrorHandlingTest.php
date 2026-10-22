<?php

declare(strict_types=1);

namespace Aslan\Payment\Tests;

use Aslan\Payment\Aslan;
use Aslan\Payment\AslanException;
use Aslan\Payment\HttpClient\SymfonyHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * End-to-end error paths through the real Symfony adapter (mocked transport):
 * AslanException must actually be thrown and catchable.
 */
final class ErrorHandlingTest extends TestCase
{
    private const KEY = 'sk_test_testPrefix_testSecret';

    /** @param MockResponse[] $responses */
    private function client(array $responses, ?array &$requests = null): Aslan
    {
        $requests = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$requests) {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses);
        });

        return new Aslan(self::KEY, httpClient: new SymfonyHttpClient(self::KEY, maxRetries: 0, client: $mock));
    }

    public function test_bad_api_key_format_throws_catchable_exception(): void
    {
        try {
            new Aslan('pk_live_bad');
            self::fail('Expected AslanException');
        } catch (AslanException $e) {
            self::assertSame('AUTHENTICATION_ERROR', $e->errorCode);
            self::assertSame('AUTHENTICATION_ERROR', $e->getErrorCode());
            self::assertSame(0, $e->getCode());
            self::assertStringContainsString('Invalid secret key format', $e->getMessage());
        }
    }

    public function test_api_error_response_throws_catchable_exception(): void
    {
        $client = $this->client([
            new MockResponse(
                json_encode(['code' => 'VALIDATION_ERROR', 'message' => 'Invalid plan data', 'details' => ['errors' => ['amount' => ['required']]]]),
                ['http_code' => 422, 'response_headers' => ['content-type' => 'application/json', 'x-request-id' => 'req_1']],
            ),
        ]);

        try {
            $client->plans->create(['name' => 'x']);
            self::fail('Expected AslanException');
        } catch (AslanException $e) {
            self::assertSame('VALIDATION_ERROR', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
            self::assertSame(422, $e->getCode());
            self::assertSame('req_1', $e->requestId);
            self::assertSame('Invalid plan data', $e->getMessage());
            self::assertSame(['errors' => ['amount' => ['required']]], $e->details);
        }
    }

    public function test_duplicate_customer_email_surfaces_as_conflict(): void
    {
        // Exact body the API sends for a duplicate email (error-handler envelope).
        $client = $this->client([
            new MockResponse(
                '{"error":{"code":"CONFLICT","message":"A customer with this email already exists"}}',
                ['http_code' => 409, 'response_headers' => ['content-type' => 'application/json', 'x-request-id' => 'req_409']],
            ),
        ], $requests);

        try {
            $client->customers->create(['email' => 'jane@example.com', 'name' => 'Jane Doe']);
            self::fail('Expected AslanException');
        } catch (AslanException $e) {
            self::assertSame('CONFLICT', $e->errorCode);
            self::assertSame('CONFLICT', $e->getErrorCode());
            self::assertSame(409, $e->getCode());
            self::assertSame(409, $e->httpStatus);
            self::assertSame('A customer with this email already exists', $e->getMessage());
            self::assertSame('req_409', $e->requestId);
        }

        self::assertCount(1, $requests, '409 must not be retried');
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://api.aslanpay.ma/pay/api/v1/customers', $requests[0]['url']);
        self::assertSame('{"email":"jane@example.com","name":"Jane Doe"}', $requests[0]['options']['body']);
    }

    public function test_api_400_envelope_keeps_api_error_code_and_details(): void
    {
        $client = $this->client([
            new MockResponse(
                '{"error":{"code":"VALIDATION_ERROR","message":"Invalid customer data","details":[{"field":"phone","message":"phone must match +212XXXXXXXXX"}]}}',
                ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']],
            ),
        ]);

        try {
            $client->customers->create(['phone' => '0612345678']);
            self::fail('Expected AslanException');
        } catch (AslanException $e) {
            self::assertSame('VALIDATION_ERROR', $e->errorCode);
            self::assertSame(400, $e->getCode());
            self::assertSame('Invalid customer data', $e->getMessage());
            self::assertSame([['field' => 'phone', 'message' => 'phone must match +212XXXXXXXXX']], $e->details);
        }
    }

    public function test_not_found_maps_to_not_found(): void
    {
        $client = $this->client([
            new MockResponse('{"code":"NOT_FOUND","message":"Subscription not found"}', [
                'http_code'        => 404,
                'response_headers' => ['content-type' => 'application/json'],
            ]),
        ]);

        $this->expectException(AslanException::class);
        $this->expectExceptionMessage('Subscription not found');
        $this->expectExceptionCode(404);

        $client->subscriptions->retrieve('missing');
    }

    public function test_webhook_signature_failure_throws_catchable_exception(): void
    {
        try {
            Aslan::webhooks('whsec_x')->constructEvent('{}', 't=' . time() . ',v1=deadbeef');
            self::fail('Expected AslanException');
        } catch (AslanException $e) {
            self::assertSame('WEBHOOK_SIGNATURE_ERROR', $e->errorCode);
            self::assertSame(0, $e->getCode());
        }
    }

    public function test_bodyless_post_sends_no_json_content_type(): void
    {
        $client = $this->client([
            new MockResponse('{"data":{"subscriptionId":"s1","status":"paused"}}', [
                'response_headers' => ['content-type' => 'application/json'],
            ]),
        ], $requests);

        $client->subscriptions->pause('s1');

        $headers = implode("\n", $requests[0]['options']['headers'] ?? []);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://api.aslanpay.ma/pay/api/v1/billing/subscriptions/s1/pause', $requests[0]['url']);
        self::assertStringNotContainsStringIgnoringCase('content-type: application/json', $headers);
        self::assertSame('', (string) ($requests[0]['options']['body'] ?? ''));
    }

    public function test_post_with_body_sends_json(): void
    {
        $client = $this->client([
            new MockResponse('{"data":{"subscriptionId":"s1","status":"cancelled"}}', [
                'response_headers' => ['content-type' => 'application/json'],
            ]),
        ], $requests);

        $client->subscriptions->cancel('s1');

        $headers = implode("\n", $requests[0]['options']['headers'] ?? []);
        self::assertStringContainsStringIgnoringCase('content-type: application/json', $headers);
        self::assertSame('{"atPeriodEnd":false}', $requests[0]['options']['body']);
    }

    public function test_pdf_download_returns_raw_bytes(): void
    {
        $pdf    = "%PDF-1.7\n\x00\xFFbinary";
        $client = $this->client([
            new MockResponse($pdf, ['response_headers' => ['content-type' => 'application/pdf']]),
        ], $requests);

        self::assertSame($pdf, $client->billingAttempts->receiptPdf('a1'));
        self::assertSame('https://api.aslanpay.ma/pay/api/v1/billing/attempts/a1/receipt.pdf', $requests[0]['url']);
        self::assertStringContainsString('accept: application/pdf', strtolower(implode("\n", $requests[0]['options']['headers'])));
    }

    public function test_pdf_download_error_throws(): void
    {
        $client = $this->client([
            new MockResponse('{"code":"VALIDATION_ERROR","message":"Billing attempt is not succeeded"}', [
                'http_code'        => 400,
                'response_headers' => ['content-type' => 'application/json'],
            ]),
        ]);

        $this->expectException(AslanException::class);
        $this->expectExceptionMessage('Billing attempt is not succeeded');

        $client->billingAttempts->receiptPdf('a1');
    }
}
