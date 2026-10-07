<?php

namespace App\Jobs;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use App\Webhooks\Exceptions\UnrecoverableWebhookException;
use App\Webhooks\PaymentEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    /** Attempts before the event is marked as failed. */
    public int $tries = 5;

    public function __construct(public readonly int $webhookEventId) {}

    /** @return list<int> seconds to wait between attempts */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(PaymentEventProcessor $processor): void
    {
        try {
            DB::transaction(function () use ($processor): void {
                // The row lock makes concurrent workers (or a duplicate job) wait for each other.
                $event = WebhookEvent::query()->lockForUpdate()->find($this->webhookEventId);

                if ($event === null || $event->status->isFinal()) {
                    return; // already handled: a duplicate job is harmless
                }

                $applied = $processor->process($event);

                $event->forceFill([
                    'status' => $applied ? WebhookEventStatus::Processed : WebhookEventStatus::Ignored,
                    'attempts' => $this->attempts(),
                    'last_error' => null,
                    'processed_at' => now(),
                ])->save();
            });
        } catch (UnrecoverableWebhookException $e) {
            $this->fail($e); // no retries, goes straight to failed()
        } catch (Throwable $e) {
            $this->recordAttempt($e);

            throw $e; // the queue retries according to $tries and backoff()
        }
    }

    /** Called by the queue when attempts are exhausted or fail() was used. */
    public function failed(Throwable $exception): void
    {
        WebhookEvent::query()->whereKey($this->webhookEventId)->update([
            'status' => WebhookEventStatus::Failed->value,
            'attempts' => $this->attempts(),
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }

    private function recordAttempt(Throwable $exception): void
    {
        WebhookEvent::query()->whereKey($this->webhookEventId)->update([
            'status' => WebhookEventStatus::Processing->value,
            'attempts' => $this->attempts(),
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
