<?php

namespace App\Console\Commands;

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('webhooks:redispatch {--minutes= : Only events older than this many minutes (default: config webhooks.redispatch_after)}')]
#[Description('Queue again events that were stored but never queued (for example while the queue was down)')]
class RedispatchWebhookEvents extends Command
{
    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('webhooks.redispatch_after'));

        $ids = WebhookEvent::query()
            ->where('status', WebhookEventStatus::Received->value)
            ->where('received_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->limit(500)
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessWebhookEvent::dispatch((int) $id);
        }

        $this->info("Re-dispatched {$ids->count()} event(s).");

        return self::SUCCESS;
    }
}
