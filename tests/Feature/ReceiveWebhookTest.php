<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

class ReceiveWebhookTest extends TestCase
{
    use RefreshDatabase;
    use SignsWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        config(['webhooks.providers.demo.secrets' => [self::SECRET]]);
        Queue::fake();
    }

    public function test_a_valid_event_is_accepted_stored_and_queued(): void
    {
        $this->postWebhook($this->event())
            ->assertStatus(202)
            ->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'demo',
            'event_id' => 'evt_1',
            'type' => 'payment.succeeded',
            'status' => 'received',
        ]);
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    }

    public function test_the_original_payload_is_stored_as_received(): void
    {
        $this->postWebhook($this->event('evt_7', 'payment.failed', ['payment_id' => 'pay_9', 'amount' => 5, 'currency' => 'EUR']));

        $stored = WebhookEvent::query()->where('event_id', 'evt_7')->firstOrFail();

        $this->assertSame('pay_9', $stored->payload['data']['payment_id']);
    }

    public function test_a_repeated_delivery_is_acknowledged_but_stored_and_queued_once(): void
    {
        $event = $this->event();

        $this->postWebhook($event)->assertStatus(202);
        $this->postWebhook($event)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, WebhookEvent::query()->count());
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    }

    public function test_the_same_event_id_from_another_provider_is_a_different_event(): void
    {
        config(['webhooks.providers.other.secrets' => [self::SECRET]]);

        $this->postWebhook($this->event(), provider: 'demo')->assertStatus(202);
        $this->postWebhook($this->event(), provider: 'other')->assertStatus(202);

        $this->assertSame(2, WebhookEvent::query()->count());
    }

    public function test_a_bad_signature_is_rejected_and_nothing_is_stored(): void
    {
        $body = json_encode($this->event(), JSON_THROW_ON_ERROR);

        $this->postWebhook($body, $this->signatureHeader($body, secret: 'wrong'))
            ->assertStatus(401);

        $this->assertSame(0, WebhookEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        $signature = $this->signatureHeader(json_encode($this->event(), JSON_THROW_ON_ERROR));
        $tampered = $this->event();
        $tampered['data']['amount'] = 999999;

        $this->postWebhook($tampered, $signature)->assertStatus(401);
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        $body = json_encode($this->event(), JSON_THROW_ON_ERROR);

        $this->postWebhook($body, $this->signatureHeader($body, time() - 3600))
            ->assertStatus(401);
    }

    public function test_a_missing_signature_header_is_rejected(): void
    {
        $this->call('POST', '/api/webhooks/demo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($this->event(), JSON_THROW_ON_ERROR))->assertStatus(401);
    }

    public function test_an_unknown_provider_gets_404_even_with_a_signature(): void
    {
        $this->postWebhook($this->event(), provider: 'nope')->assertNotFound();
    }

    public function test_a_provider_without_secrets_is_not_reachable(): void
    {
        config(['webhooks.providers.demo.secrets' => []]);

        $this->postWebhook($this->event())->assertNotFound();
    }

    public function test_a_signed_but_malformed_payload_gets_422(): void
    {
        $this->postWebhook(['id' => 'evt_1'])->assertStatus(422);
        $this->postWebhook('not json at all')->assertStatus(422);

        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_the_event_type_format_is_restricted(): void
    {
        $this->postWebhook($this->event('evt_1', 'Payment Succeeded!'))->assertStatus(422);
    }
}
