<?php

use App\Http\Controllers\Api\RoboDeskIntegrationController;
use Illuminate\Support\Facades\Route;

Route::get('health', [RoboDeskIntegrationController::class, 'health'])->name('health');
Route::get('checkouts/{checkoutReference}', [RoboDeskIntegrationController::class, 'checkout'])->name('checkouts.show');
Route::post('events', [RoboDeskIntegrationController::class, 'event'])->name('events.store');
Route::post('payment-proofs', [RoboDeskIntegrationController::class, 'paymentProof'])->name('payment-proofs.store');
