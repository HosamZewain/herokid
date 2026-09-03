<?php

use App\Http\Controllers\Api\Agent\AgentCheckoutController;
use App\Http\Controllers\Api\Agent\AgentOrderController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ChildProfileController;
use App\Http\Controllers\Api\V1\ChildProfilePhotoController;
use App\Http\Controllers\Api\V1\CustomerAddressController;
use App\Http\Controllers\Api\V1\DeviceInstallationController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\MobileAnalyticsController;
use App\Http\Controllers\Api\V1\MobileCartController;
use App\Http\Controllers\Api\V1\MobileCheckoutController;
use App\Http\Controllers\Api\V1\MobileChildIdentityController;
use App\Http\Controllers\Api\V1\MobileChildIdentityShareController;
use App\Http\Controllers\Api\V1\MobileDraftController;
use App\Http\Controllers\Api\V1\MobileNotificationController;
use App\Http\Controllers\Api\V1\MobileOrderController;
use App\Http\Controllers\Api\V1\MobileOrderPreviewController;
use App\Http\Controllers\Api\V1\MobileOtpController;
use App\Http\Controllers\Api\V1\MobileSessionController;
use App\Http\Controllers\Api\V1\MobileUploadController;
use App\Http\Controllers\Api\V1\PrivacyController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('agent')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::post('checkouts/acquire-next', [AgentCheckoutController::class, 'acquireNext'])
        ->middleware('agent_api:ability:orders.acquire,permission:orders.assign');
    Route::get('checkouts/{reference}/production-context', [AgentCheckoutController::class, 'context'])
        ->middleware('agent_api:ability:orders.read,permission:orders.view');
    Route::post('checkouts/{reference}/complete-production', [AgentCheckoutController::class, 'complete'])
        ->middleware('agent_api:ability:orders.update-status,permission:orders.update');

    Route::post('orders/{order}/attachments', [AgentOrderController::class, 'attachments'])
        ->middleware('agent_api:ability:orders.upload-attachment,permission:orders.update');
    Route::post('orders/{order}/previews', [AgentOrderController::class, 'previews'])
        ->middleware('agent_api:ability:orders.upload-preview,permission:orders.preview.upload');
    Route::get('orders/{order}/references/child-photos/{index}', [AgentOrderController::class, 'childPhoto'])
        ->whereNumber('index')->middleware('agent_api:ability:orders.read,permission:orders.photos.view')
        ->name('agent.orders.references.child-photo');
    Route::get('orders/{order}/references/approved-identity', [AgentOrderController::class, 'approvedIdentity'])
        ->middleware('agent_api:ability:orders.read,permission:orders.photos.view')
        ->name('agent.orders.references.approved-identity');
    Route::get('orders/{order}/attachments/{attachment}', [AgentOrderController::class, 'attachment'])
        ->middleware('agent_api:ability:orders.read,permission:orders.view')
        ->name('agent.orders.attachments.download');
});

Route::prefix('v1')->group(function (): void {
    Route::get('bootstrap', BootstrapController::class)->middleware('throttle:120,1');
    Route::get('catalog', [CatalogController::class, 'index'])->middleware('throttle:120,1');
    Route::get('catalog/{type}/{slug}', [CatalogController::class, 'show'])
        ->whereIn('type', ['story', 'product'])
        ->middleware('throttle:120,1');
    Route::post('analytics/events', [MobileAnalyticsController::class, 'store'])->middleware('throttle:120,1');

    Route::prefix('auth')->middleware('throttle:10,1')->group(function (): void {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('otp/request', [MobileOtpController::class, 'request']);
        Route::post('otp/verify', [MobileOtpController::class, 'verify']);
        Route::post('social', SocialAuthController::class);
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [AuthController::class, 'update']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::apiResource('children', ChildProfileController::class);
        Route::apiResource('addresses', CustomerAddressController::class);
        Route::get('favorites', [FavoriteController::class, 'index']);
        Route::post('favorites', [FavoriteController::class, 'store']);
        Route::delete('favorites/{type}/{id}', [FavoriteController::class, 'destroy'])->whereNumber('id');
        Route::apiResource('drafts', MobileDraftController::class);
        Route::get('orders', [MobileOrderController::class, 'index']);
        Route::get('orders/{order}', [MobileOrderController::class, 'show']);
        Route::post('orders/{order}/reorder', [MobileOrderController::class, 'reorder']);
        Route::get('orders/{order}/preview', [MobileOrderPreviewController::class, 'show']);
        Route::get('orders/{order}/preview/document', [MobileOrderPreviewController::class, 'document']);
        Route::post('orders/{order}/preview/approve', [MobileOrderPreviewController::class, 'approve']);
        Route::post('orders/{order}/preview/revision', [MobileOrderPreviewController::class, 'requestRevision']);
        Route::get('devices', [DeviceInstallationController::class, 'index']);
        Route::post('devices', [DeviceInstallationController::class, 'store']);
        Route::patch('devices/{device}', [DeviceInstallationController::class, 'update']);
        Route::delete('devices/{device}', [DeviceInstallationController::class, 'destroy']);
        Route::get('notifications', [MobileNotificationController::class, 'index']);
        Route::post('notifications/read-all', [MobileNotificationController::class, 'readAll']);
        Route::post('notifications/{notification}/read', [MobileNotificationController::class, 'read']);
        Route::get('sessions', [MobileSessionController::class, 'index']);
        Route::delete('sessions/{session}', [MobileSessionController::class, 'destroy'])->whereNumber('session');
        Route::get('privacy/requests', [PrivacyController::class, 'index']);
        Route::post('privacy/requests', [PrivacyController::class, 'store'])->middleware('throttle:5,1');
        Route::post('privacy/requests/{privacyRequest}/cancel', [PrivacyController::class, 'cancel']);
        Route::get('privacy/consents', [PrivacyController::class, 'consents']);
        Route::get('cart', [MobileCartController::class, 'show']);
        Route::post('cart/items', [MobileCartController::class, 'storeItem']);
        Route::patch('cart/items/{item}', [MobileCartController::class, 'updateItem']);
        Route::delete('cart/items/{item}', [MobileCartController::class, 'destroyItem']);
        Route::post('cart/items/{item}/duplicate', [MobileCartController::class, 'duplicateItem']);
        Route::put('cart/promo-code', [MobileCartController::class, 'applyPromo']);
        Route::delete('cart/promo-code', [MobileCartController::class, 'removePromo']);
        Route::post('checkout', [MobileCheckoutController::class, 'store'])->middleware('throttle:10,1');
        Route::get('children/{child:uuid}/photos', [ChildProfilePhotoController::class, 'index']);
        Route::get('children/{child:uuid}/photos/{photo:uuid}/media', [ChildProfilePhotoController::class, 'media'])
            ->name('api.v1.children.photos.media');
        Route::delete('children/{child:uuid}/photos/{photo:uuid}', [ChildProfilePhotoController::class, 'destroy']);

        Route::post('uploads', [MobileUploadController::class, 'store'])->middleware('throttle:30,1');
        Route::get('uploads/{upload:uuid}', [MobileUploadController::class, 'show']);
        Route::post('uploads/{upload:uuid}/chunks/{index}', [MobileUploadController::class, 'chunk'])
            ->whereNumber('index')->middleware('throttle:120,1');
        Route::post('uploads/{upload:uuid}/attach-child-photo', [MobileUploadController::class, 'attach']);
        Route::delete('uploads/{upload:uuid}', [MobileUploadController::class, 'destroy']);

        Route::get('child-identities', [MobileChildIdentityController::class, 'index']);
        Route::post('child-identities', [MobileChildIdentityController::class, 'store'])->middleware('throttle:6,1');
        Route::get('child-identities/{identity:uuid}', [MobileChildIdentityController::class, 'show']);
        Route::post('child-identities/{identity:uuid}/generate', [MobileChildIdentityController::class, 'generate'])->middleware('throttle:6,1');
        Route::post('child-identities/{identity:uuid}/attempts/{attempt}/approve', [MobileChildIdentityController::class, 'approve']);
        Route::post('child-identities/{identity:uuid}/share', [MobileChildIdentityShareController::class, 'store'])->middleware('throttle:10,1');
        Route::post('child-identities/{identity:uuid}/share/{share}/event', [MobileChildIdentityShareController::class, 'event'])->middleware('throttle:60,1');
        Route::get('child-identities/{identity:uuid}/attempts/{attempt}/media', [MobileChildIdentityController::class, 'media'])
            ->name('api.v1.identities.attempts.media');
        Route::delete('child-identities/{identity:uuid}', [MobileChildIdentityController::class, 'destroy']);
    });
});
