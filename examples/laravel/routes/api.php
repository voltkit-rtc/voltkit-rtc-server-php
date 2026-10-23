<?php

declare(strict_types=1);

use App\Http\Controllers\RtcTokenController;
use App\Http\Controllers\VoltKitWebhookController;
use Illuminate\Support\Facades\Route;

// Add to routes/api.php. The webhook route must receive the raw body: routes/api.php has no CSRF or
// form-decoding middleware in the way, and $request->getContent() is the bytes as sent.
Route::post('/rtc/token', RtcTokenController::class)->middleware('auth:sanctum');
Route::post('/hooks/voltkit', VoltKitWebhookController::class);
