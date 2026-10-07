<?php

namespace App\Models;

use App\Enums\WebhookEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider
 * @property string $event_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property WebhookEventStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
class WebhookEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookEventStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
