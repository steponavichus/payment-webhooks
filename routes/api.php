<?php

use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/{provider}', WebhookController::class)
    ->where('provider', '[a-z0-9_-]+')
    ->middleware(VerifyWebhookSignature::class)
    ->name('webhooks.receive');
