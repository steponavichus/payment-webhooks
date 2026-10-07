<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'payment-webhooks',
    'endpoint' => 'POST /api/webhooks/{provider}',
]));
