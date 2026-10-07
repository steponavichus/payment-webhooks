<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /** Money has been captured at some point, so "failed" must not overwrite it. */
    public function isCaptured(): bool
    {
        return $this !== self::Failed;
    }

    public function canBeRefunded(): bool
    {
        return $this === self::Succeeded || $this === self::PartiallyRefunded;
    }
}
