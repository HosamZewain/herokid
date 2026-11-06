<?php

use App\Http\Controllers\Admin\SalesAssistantController as SalesAdmin;
use App\Http\Controllers\Front\SalesAssistantController;
use App\Http\Middleware\TrackCustomerVisit;
use Illuminate\Support\Facades\Route;

Route::get('/shopping-assistant', [SalesAssistantController::class, 'index'])->name('sales.index');

Route::prefix('shopping-assistant')->name('sales.')->withoutMiddleware(TrackCustomerVisit::class)->group(function () {
    Route::post('/state', [SalesAssistantController::class, 'state'])->middleware('throttle:sales-browser')->block(5, 5)->name('state');
    Route::post('/messages', [SalesAssistantController::class, 'message'])->middleware('throttle:sales-browser')->block(5, 5)->name('message');
    Route::post('/catalog', [SalesAssistantController::class, 'browse'])->middleware('throttle:sales-browser')->name('catalog');
    Route::get('/cart-edit', [SalesAssistantController::class, 'cartEdit'])->name('cart-edit');
    Route::post('/cart-edit/{key}', [SalesAssistantController::class, 'cartUpdate'])->middleware(['throttle:sales-browser', 'throttle:photo-uploads'])->block(60, 15)->name('cart-update');
    Route::post('/upsells', [SalesAssistantController::class, 'upsells'])->middleware('throttle:sales-browser')->name('upsells');
    Route::get('/cart-photo/{hash}', [SalesAssistantController::class, 'photo'])->where('hash', '[a-f0-9]{64}')->name('photo');
    Route::post('/events', [SalesAssistantController::class, 'event'])->middleware('throttle:sales-browser')->name('event');
    Route::get('/item/{kind}/{id}', [SalesAssistantController::class, 'item'])->whereIn('kind', ['story', 'product', 'package'])->whereNumber('id')->name('item');
});
Route::middleware(['auth', 'is_admin', 'admin_audit', 'permission:analytics.view'])->prefix('admin/sales-assistant')->name('admin.sales-assistant.')->group(function () {
    Route::get('/', [SalesAdmin::class, 'index'])->name('index');
    Route::get('/conversation/{conversation}', [SalesAdmin::class, 'conversation'])->middleware('permission:settings.site.update')->name('conversation');
    Route::post('/settings', [SalesAdmin::class, 'update'])->middleware('permission:settings.site.update')->name('update');
    Route::post('/verify-model', [SalesAdmin::class, 'verify'])->middleware(['permission:settings.site.update', 'throttle:3,1'])->name('verify');
    Route::post('/catalog-profile', [SalesAdmin::class, 'profile'])->middleware('permission:settings.site.update')->name('profile');
});
