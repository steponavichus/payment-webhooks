<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Webhooks\Exceptions\PaymentNotFoundException;
use App\Webhooks\PaymentEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

class ProcessWebhookEventTest extends TestCase
{
    use RefreshDatabase;
    use SignsWebhooks;

    /** @param  array<string, mixed>  $payload */
    private function store(array $payload): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'provider' => 'demo',
            'event_id' => $payload['id'],
            'type' => $payload['type'],
            'payload' => $payload,
            'status' => WebhookEventStatus::Received,
            'received_at' => now(),
        ]);
    }

    private function process(WebhookEvent $event): WebhookEvent
    {
        ProcessWebhookEvent::dispatchSync($event->id);

        return $event->refresh();
    }

    private function payment(string $id = 'pay_1'): Payment
    {
        return Payment::query()->where('payment_id', $id)->firstOrFail();
    }

    public function test_a_succeeded_event_creates_the_payment(): void
    {
        $event = $this->process($this->store($this->event()));

        $payment = $this->payment();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(1500, $payment->amount);
        $this->assertSame('USD', $payment->currency);
        $this->assertSame(WebhookEventStatus::Processed, $event->status);
        $this->assertNotNull($event->processed_at);
        $this->assertSame(1, $event->attempts);
    }

    public function test_running_the_same_event_twice_applies_it_once(): void
    {
        $this->process($this->store($this->event('evt_1')));
        $refund = $this->store($this->event('evt_2', 'payment.refunded', ['payment_id' => 'pay_1', 'amount' => 500]));

        $this->process($refund);
        $this->process($refund); // duplicate job, for example after a redispatch

        $this->assertSame(500, $this->payment()->refunded_amount);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_a_late_failed_event_does_not_overwrite_a_captured_payment(): void
    {
        $this->process($this->store($this->event('evt_1')));

        $this->process($this->store($this->event('evt_2', 'payment.failed')));

        $this->assertSame(PaymentStatus::Succeeded, $this->payment()->status);
    }

    public function test_a_failed_event_for_an_unknown_payment_creates_a_failed_payment(): void
    {
        $this->process($this->store($this->event('evt_1', 'payment.failed')));

        $this->assertSame(PaymentStatus::Failed, $this->payment()->status);
    }

    public function test_a_late_succeeded_event_does_not_undo_a_refund(): void
    {
        $this->process($this->store($this->event('evt_1')));
        $this->process($this->store($this->event('evt_2', 'payment.refunded', ['payment_id' => 'pay_1', 'amount' => 1500])));

        $this->process($this->store($this->event('evt_3'))); // provider re-sends "succeeded" under a new id

        $this->assertSame(PaymentStatus::Refunded, $this->payment()->status);
    }

    public function test_partial_refunds_add_up_to_a_full_refund(): void
    {
        $this->process($this->store($this->event('evt_1')));

        $this->process($this->store($this->event('evt_2', 'payment.refunded', ['payment_id' => 'pay_1', 'amount' => 500])));
        $this->assertSame(PaymentStatus::PartiallyRefunded, $this->payment()->status);

        $this->process($this->store($this->event('evt_3', 'payment.refunded', ['payment_id' => 'pay_1', 'amount' => 1000])));
        $this->assertSame(PaymentStatus::Refunded, $this->payment()->status);
        $this->assertSame(1500, $this->payment()->refunded_amount);
    }

    public function test_a_refund_above_the_payment_amount_fails_without_retries(): void
    {
        $this->process($this->store($this->event('evt_1')));

        $event = $this->process($this->store($this->event('evt_2', 'payment.refunded', ['payment_id' => 'pay_1', 'amount' => 1501])));

        $this->assertSame(WebhookEventStatus::Failed, $event->status);
        $this->assertNotNull($event->last_error);
        $this->assertStringContainsString('exceeds', $event->last_error);
        $this->assertSame(0, $this->payment()->refunded_amount);
    }

    public function test_a_refund_for_a_failed_payment_is_rejected(): void
    {
        $this->process($this->store($this->event('evt_1', 'payment.failed')));

        $event = $this->process($this->store($this->event('evt_2', 'payment.refunded', ['payment_id' => 'pay_1', 'amount' => 100])));

        $this->assertSame(WebhookEventStatus::Failed, $event->status);
    }

    public function test_a_refund_that_arrives_first_is_retryable_and_works_after_the_payment_exists(): void
    {
        $refund = $this->store($this->event('evt_2', 'payment.refunded', ['payment_id' => 'pay_1', 'amount' => 400]));
        $processor = app(PaymentEventProcessor::class);

        try {
            $processor->process($refund);
            $this->fail('A refund for an unknown payment must throw.');
        } catch (PaymentNotFoundException) {
            // the job is retried with backoff in this case
        }

        $this->process($this->store($this->event('evt_1'))); // the missing "succeeded" arrives

        $processor->process($refund->refresh());
        $this->assertSame(400, $this->payment()->refunded_amount);
    }

    public function test_an_unknown_event_type_is_ignored(): void
    {
        $event = $this->process($this->store($this->event('evt_1', 'customer.updated', ['name' => 'x'])));

        $this->assertSame(WebhookEventStatus::Ignored, $event->status);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_invalid_event_data_fails_with_a_readable_error(): void
    {
        $event = $this->process($this->store($this->event('evt_1', 'payment.succeeded', ['payment_id' => 'pay_1', 'amount' => -5, 'currency' => 'USD'])));

        $this->assertSame(WebhookEventStatus::Failed, $event->status);
        $this->assertNotNull($event->last_error);
        $this->assertStringContainsString('Invalid event data', $event->last_error);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_full_flow_from_http_to_database(): void
    {
        config(['webhooks.providers.demo.secrets' => [self::SECRET]]);

        $this->postWebhook($this->event('evt_1'))->assertStatus(202); // queue is "sync" in tests

        $this->assertSame(PaymentStatus::Succeeded, $this->payment()->status);
        $this->assertSame(WebhookEventStatus::Processed, WebhookEvent::query()->firstOrFail()->status);
    }
}
