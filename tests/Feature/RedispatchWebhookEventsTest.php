<?php

namespace Tests\Feature;

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RedispatchWebhookEventsTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $id, WebhookEventStatus $status, int $minutesAgo): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'provider' => 'demo',
            'event_id' => $id,
            'type' => 'payment.succeeded',
            'payload' => ['id' => $id],
            'status' => $status,
            'received_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_only_old_events_that_were_never_queued_are_dispatched_again(): void
    {
        Queue::fake();

        $stuck = $this->store('stuck', WebhookEventStatus::Received, 10);
        $this->store('fresh', WebhookEventStatus::Received, 1);
        $this->store('done', WebhookEventStatus::Processed, 60);
        $this->store('failed', WebhookEventStatus::Failed, 60);

        $this->assertSame(0, Artisan::call('webhooks:redispatch'));
        $this->assertStringContainsString('Re-dispatched 1 event(s).', Artisan::output());

        Queue::assertPushed(ProcessWebhookEvent::class, 1);
        Queue::assertPushed(ProcessWebhookEvent::class, fn (ProcessWebhookEvent $job) => $job->webhookEventId === $stuck->id);
    }

    public function test_the_age_threshold_can_be_overridden(): void
    {
        Queue::fake();
        $this->store('fresh', WebhookEventStatus::Received, 1);

        $this->assertSame(0, Artisan::call('webhooks:redispatch', ['--minutes' => 0]));

        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    }
}
