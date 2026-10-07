<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider
 * @property string $payment_id
 * @property int $amount Minor units (cents)
 * @property int $refunded_amount Minor units (cents)
 * @property string $currency
 * @property PaymentStatus $status
 */
class Payment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'refunded_amount' => 'integer',
            'status' => PaymentStatus::class,
        ];
    }
}
