<?php

use App\Http\Controllers\Admin\RoboDeskIntegrationController;
use App\Http\Controllers\Admin\RoboDeskSettingsController;
use App\Http\Controllers\Admin\RoboDeskSimulatorController;
use Illuminate\Support\Facades\Route;

Route::get('/', [RoboDeskIntegrationController::class, 'index'])->middleware('permission:robodesk.view')->name('index');
Route::post('events/{event}/retry', [RoboDeskIntegrationController::class, 'retry'])->middleware('permission:robodesk.retry')->name('events.retry');
Route::get('payment-proofs/{proof}', [RoboDeskIntegrationController::class, 'proof'])->middleware('permission:robodesk.view_media')->name('payment-proofs.show');
Route::post('payment-proofs/{proof}/approve', [RoboDeskIntegrationController::class, 'approveProof'])->middleware('permission:robodesk.review_payments')->name('payment-proofs.approve');
Route::post('payment-proofs/{proof}/reject', [RoboDeskIntegrationController::class, 'rejectProof'])->middleware('permission:robodesk.review_payments')->name('payment-proofs.reject');

Route::get('settings', [RoboDeskSettingsController::class, 'index'])->middleware('permission:robodesk.configure')->name('settings.index');
Route::post('settings/general', [RoboDeskSettingsController::class, 'updateGeneral'])->middleware('permission:robodesk.configure')->name('settings.general');
Route::get('settings/{integrationKey}', [RoboDeskSettingsController::class, 'edit'])->middleware('permission:robodesk.configure')->name('settings.edit');
Route::post('settings/{integrationKey}', [RoboDeskSettingsController::class, 'update'])->middleware('permission:robodesk.configure')->name('settings.update');

Route::get('simulator', [RoboDeskSimulatorController::class, 'index'])->middleware('permission:robodesk.configure')->name('simulator.index');
Route::get('simulator/{checkoutReference}', [RoboDeskSimulatorController::class, 'show'])->middleware('permission:robodesk.configure')->name('simulator.show');
Route::post('simulator/{checkoutReference}/reply', [RoboDeskSimulatorController::class, 'reply'])->middleware('permission:robodesk.configure')->name('simulator.reply');
Route::post('settings/{integrationKey}/test', [RoboDeskSettingsController::class, 'test'])->middleware('permission:robodesk.configure')->name('settings.test');
Route::get('settings/{integrationKey}/test/{reference}', [RoboDeskSettingsController::class, 'testStatus'])->middleware('permission:robodesk.configure')->name('settings.test.status');
