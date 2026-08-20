<?php

use App\Http\Controllers\Admin\RoboDeskIntegrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [RoboDeskIntegrationController::class, 'index'])->middleware('permission:robodesk.view')->name('index');
Route::post('events/{event}/retry', [RoboDeskIntegrationController::class, 'retry'])->middleware('permission:robodesk.retry')->name('events.retry');
Route::get('payment-proofs/{proof}', [RoboDeskIntegrationController::class, 'proof'])->middleware('permission:robodesk.view_media')->name('payment-proofs.show');
Route::post('payment-proofs/{proof}/approve', [RoboDeskIntegrationController::class, 'approveProof'])->middleware('permission:robodesk.review_payments')->name('payment-proofs.approve');
Route::post('payment-proofs/{proof}/reject', [RoboDeskIntegrationController::class, 'rejectProof'])->middleware('permission:robodesk.review_payments')->name('payment-proofs.reject');
