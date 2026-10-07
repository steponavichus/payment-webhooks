<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebhookController
{
    /**
     * Accepts a webhook, stores it and queues processing. Nothing slow happens
     * here: providers expect a fast response, otherwise they retry.
     */
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        $payload = is_array($decoded) ? $decoded : [];

        $validator = Validator::make($payload, [
            'id' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_.]+$/'],
            'created' => ['required', 'integer'],
            'data' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid payload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $now = now();

        // One atomic statement (INSERT ... ON CONFLICT DO NOTHING): two concurrent
        // deliveries of the same event cannot both be stored.
        $inserted = WebhookEvent::query()->insertOrIgnore([
            'provider' => $provider,
            'event_id' => $payload['id'],
            'type' => $payload['type'],
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => WebhookEventStatus::Received->value,
            'attempts' => 0,
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            // Already known: the provider retried. Answer 200 so it stops retrying.
            return response()->json(['status' => 'duplicate']);
        }

        $id = (int) WebhookEvent::query()
            ->where('provider', $provider)
            ->where('event_id', $payload['id'])
            ->value('id');

        ProcessWebhookEvent::dispatch($id);

        return response()->json(['status' => 'accepted', 'event_id' => $id], 202);
    }
}
