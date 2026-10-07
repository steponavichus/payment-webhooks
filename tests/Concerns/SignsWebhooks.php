<?php

namespace Tests\Concerns;

use App\Webhooks\SignatureVerifier;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

trait SignsWebhooks
{
    protected const SECRET = 'test-secret';

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>
     */
    protected function event(string $id = 'evt_1', string $type = 'payment.succeeded', ?array $data = null): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'created' => time(),
            'data' => $data ?? ['payment_id' => 'pay_1', 'amount' => 1500, 'currency' => 'USD'],
        ];
    }

    protected function signatureHeader(string $body, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".SignatureVerifier::sign($body, $secret, $timestamp);
    }

    /**
     * Sends the exact raw body that was signed (a re-encoded body would break the signature).
     *
     * @param  array<string, mixed>|string  $payload
     * @return TestResponse<Response>
     */
    protected function postWebhook(array|string $payload, ?string $signature = null, string $provider = 'demo'): TestResponse
    {
        $body = is_array($payload) ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload;
        $signature ??= $this->signatureHeader($body);

        return $this->call(
            'POST',
            "/api/webhooks/{$provider}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $body,
        );
    }
}
