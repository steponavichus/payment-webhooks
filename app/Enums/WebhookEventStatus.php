<?php

namespace App\Enums;

enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    /** Final statuses are never processed again. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Processed, self::Ignored, self::Failed => true,
            default => false,
        };
    }
}
