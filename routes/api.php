<?php

use App\Http\Controllers\TwilioController;
use Illuminate\Support\Facades\Route;

Route::prefix('twilio')->group(function (): void {
    Route::get('/token', [TwilioController::class, 'token']);
    Route::post('/voice/incoming', [TwilioController::class, 'incoming']);
    Route::post('/voice/outgoing', [TwilioController::class, 'outgoing']);
    Route::post('/voice/status', [TwilioController::class, 'status']);
    Route::post('/voice/transfer-dial', [TwilioController::class, 'transferDial']);

    Route::post('/transfer/blind', [TwilioController::class, 'blindTransfer']);
    Route::post('/transfer/start', [TwilioController::class, 'warmTransferStart']);
    Route::post('/transfer/complete', [TwilioController::class, 'warmTransferComplete']);
});
