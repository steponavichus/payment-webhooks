<?php

namespace App\Webhooks;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Webhooks\Exceptions\PaymentNotFoundException;
use App\Webhooks\Exceptions\UnrecoverableWebhookException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Applies a stored webhook event to the payments table.
 * Must be called inside a database transaction (the job does that).
 */
class PaymentEventProcessor
{
    /**
     * @return bool true if the event was applied, false if its type is not handled
     */
    public function process(WebhookEvent $event): bool
    {
        $data = is_array($event->payload['data'] ?? null) ? $event->payload['data'] : [];

        return match ($event->type) {
            'payment.succeeded' => $this->succeeded($event, $data),
            'payment.failed' => $this->failed($event, $data),
            'payment.refunded' => $this->refunded($event, $data),
            default => false,
        };
    }

    /** @param  array<string, mixed>  $data */
    private function succeeded(WebhookEvent $event, array $data): bool
    {
        $values = $this->validated($data, $this->paymentRules());
        $payment = $this->find($event->provider, $values['payment_id']);

        if ($payment !== null && ! $payment->status->canBeRefunded() && $payment->status !== PaymentStatus::Failed) {
            // Already refunded: a late "succeeded" must not roll the payment back.
            return true;
        }

        $payment ??= new Payment(['provider' => $event->provider, 'payment_id' => $values['payment_id']]);
        $payment->fill([
            'amount' => $values['amount'],
            'currency' => strtoupper($values['currency']),
            'status' => PaymentStatus::Succeeded,
        ])->save();

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function failed(WebhookEvent $event, array $data): bool
    {
        $values = $this->validated($data, $this->paymentRules());
        $payment = $this->find($event->provider, $values['payment_id']);

        if ($payment !== null && $payment->status->isCaptured()) {
            // Money was captured already: a late "failed" must not overwrite it.
            return true;
        }

        $payment ??= new Payment(['provider' => $event->provider, 'payment_id' => $values['payment_id']]);
        $payment->fill([
            'amount' => $values['amount'],
            'currency' => strtoupper($values['currency']),
            'status' => PaymentStatus::Failed,
        ])->save();

        return true;
    }

    /** @param  array<string, mixed>  $data */
    private function refunded(WebhookEvent $event, array $data): bool
    {
        $values = $this->validated($data, [
            'payment_id' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $payment = $this->find($event->provider, $values['payment_id']);

        if ($payment === null) {
            // Retryable: the "succeeded" event may simply not have been processed yet.
            throw new PaymentNotFoundException("Payment {$values['payment_id']} is not known yet.");
        }

        if (! $payment->status->canBeRefunded()) {
            throw new UnrecoverableWebhookException(
                "Payment {$payment->payment_id} in status {$payment->status->value} cannot be refunded."
            );
        }

        $total = $payment->refunded_amount + $values['amount'];

        if ($total > $payment->amount) {
            throw new UnrecoverableWebhookException(
                "Refund of {$values['amount']} exceeds the remaining amount of payment {$payment->payment_id}."
            );
        }

        $payment->forceFill([
            'refunded_amount' => $total,
            'status' => $total === $payment->amount ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
        ])->save();

        return true;
    }

    private function find(string $provider, string $paymentId): ?Payment
    {
        return Payment::query()
            ->where('provider', $provider)
            ->where('payment_id', $paymentId)
            ->lockForUpdate()
            ->first();
    }

    /** @return array<string, list<string>> */
    private function paymentRules(): array
    {
        return [
            'payment_id' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>
     */
    private function validated(array $data, array $rules): array
    {
        try {
            return Validator::make($data, $rules)->validate();
        } catch (ValidationException $e) {
            throw new UnrecoverableWebhookException('Invalid event data: '.implode(' ', $e->validator->errors()->all()), 0, $e);
        }
    }
}
