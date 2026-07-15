<?php

use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\AdminHomeController;
use App\Http\Controllers\Admin\AiProviderSettingsController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeliveryZoneController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\HomepageStoreSectionController;
use App\Http\Controllers\Admin\NotificationCenterController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderProductionPromptController;
use App\Http\Controllers\Admin\PricingPackageController;
use App\Http\Controllers\Admin\ProductCategoryController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductionAutomationController;
use App\Http\Controllers\Admin\ProductionStudioController;
use App\Http\Controllers\Admin\ProductUpsellRuleController;
use App\Http\Controllers\Admin\ProductVariantController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StoryAttachmentController;
use App\Http\Controllers\Admin\StoryProductionPromptTemplateController;
use App\Http\Controllers\Admin\TestimonialController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VisitorCartController;
use App\Http\Controllers\Front\CartController;
use App\Http\Controllers\Front\CheckoutController;
use App\Http\Controllers\Front\PageController;
use App\Http\Controllers\Front\ProductCartController;
use App\Http\Controllers\Front\ShopController;
use App\Http\Controllers\Front\StoryController;
use App\Http\Controllers\Front\TemporaryPhotoUploadController;
use App\Http\Controllers\Front\TrackOrderController;
use App\Http\Controllers\ProfileController;
use App\Models\FaqItem;
use App\Models\HomepageStoreSection;
use App\Models\Order;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Story;
use App\Models\Testimonial;
use App\Support\Seo;
use Illuminate\Support\Facades\Route;

// ── Storage Fallback for Shared Hosting ──────────────────────────────────────
// On some servers the public/storage symlink can't be created (symlink() and
// exec() disabled). The .htaccess only routes requests to PHP when the file
// does NOT exist on disk, so this only fires when the symlink is missing —
// it has zero cost when the symlink is in place.
Route::get('/storage/{path}', function (string $path) {
    // Block directory traversal
    if (str_contains($path, '..')) {
        abort(403);
    }
    $fullPath = storage_path('app/public/'.ltrim($path, '/'));
    if (! file_exists($fullPath) || ! is_file($fullPath)) {
        abort(404);
    }

    return response()->file($fullPath, [
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ]);
})->where('path', '.*')->name('storage.serve');

// Homepage
Route::get('/', function () {
    $featuredStories = Story::where('active', true)->with('categories')->inRandomOrder()->take(8)->get();
    $faqs = FaqItem::where('active', true)->orderBy('sort_order')->take(5)->get();
    $testimonials = Testimonial::where('active', true)->orderBy('sort_order')->get();
    $packages = PricingPackage::active()->ordered()->get();
    $storeSections = setting('shop_enabled', '1') === '1'
        ? HomepageStoreSection::query()
            ->with(['category.activeProducts' => fn ($query) => $query->orderByDesc('is_featured')->orderBy('sort_order')->latest()])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($section) => $section->category && $section->category->activeProducts->isNotEmpty())
        : collect();

    return view('welcome', compact('featuredStories', 'faqs', 'testimonials', 'packages', 'storeSections'));
})->name('home');

// Public Story Routes
Route::get('/stories', [StoryController::class, 'index'])->name('stories.index');
Route::get('/stories/{slug}', [StoryController::class, 'show'])->name('stories.show');

// Public Store Routes
Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/shop/product/{product:slug}', [ShopController::class, 'show'])->name('shop.product.show');
Route::get('/shop/{category:slug}', [ShopController::class, 'category'])->name('shop.category');

// Cart and checkout routes
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::get('/photo-uploads/session', [TemporaryPhotoUploadController::class, 'session'])->name('photo-uploads.session');
Route::post('/photo-uploads', [TemporaryPhotoUploadController::class, 'store'])->middleware('throttle:20,1')->name('photo-uploads.store');
Route::get('/photo-uploads/{publicId}', [TemporaryPhotoUploadController::class, 'show'])->name('photo-uploads.show');
Route::delete('/photo-uploads/{publicId}', [TemporaryPhotoUploadController::class, 'destroy'])->name('photo-uploads.destroy');
Route::post('/cart/stories/{story:slug}', [CartController::class, 'store'])->name('cart.store');
Route::post('/cart/products/{product:slug}', [ProductCartController::class, 'store'])->name('cart.products.store');
Route::delete('/cart/{key}', [CartController::class, 'destroy'])->name('cart.destroy');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::get('/checkout/success', [CheckoutController::class, 'success'])->name('checkout.success');

// Order Tracking
Route::get('/track-order', [TrackOrderController::class, 'index'])->name('track.index');
Route::post('/track-order', [TrackOrderController::class, 'track'])->name('track.search');

// Preview Approval (customer)
Route::post('/orders/{order}/approve-preview', function (Order $order) {
    if ($order->status === 'preview_uploaded') {
        $order->update(['status' => 'approved_for_print']);
        $order->statusLogs()->create([
            'status' => 'approved_for_print',
            'notes' => 'تم الموافقة على التصميم النهائي من قِبل العميل.',
        ]);

        return back()->with('success', 'تمت الموافقة على التصميم! سنبدأ الطباعة قريباً.');
    }

    return back()->with('error', 'لا يوجد تصميم قيد المراجعة حالياً.');
})->middleware('auth')->name('orders.approve-preview');

Route::get('/orders/{order}/production-photos/{index}', [OrderController::class, 'serveProductionPhoto'])
    ->middleware('signed')
    ->name('orders.production-photo')
    ->where('index', '[0-9]+');

// Static Pages
// ── Dynamic Sitemap ──────────────────────────────────────────────────────────
Route::get('/sitemap.xml', function () {
    $shopEnabled = setting('shop_enabled', '1') === '1';
    $stories = Story::where('active', true)
        ->select('slug', 'updated_at')
        ->orderBy('updated_at', 'desc')
        ->get();
    $productCategories = $shopEnabled ? ProductCategory::where('is_active', true)
        ->where('show_in_store', true)
        ->whereHas('activeProducts')
        ->select('slug', 'updated_at')
        ->get() : collect();
    $products = $shopEnabled ? Product::publiclyVisible()
        ->select('slug', 'updated_at')
        ->get() : collect();

    $staticPages = [
        ['url' => Seo::url('/'),             'lastmod' => now()->toDateString(), 'freq' => 'daily',   'priority' => '1.0'],
        ['url' => Seo::url('/stories'),      'lastmod' => now()->toDateString(), 'freq' => 'daily',   'priority' => '0.9'],
        ['url' => Seo::url('/pricing'),      'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.8'],
        ['url' => Seo::url('/faq'),          'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.7'],
        ['url' => Seo::url('/contact'),      'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.6'],
        ['url' => Seo::url('/how-it-works'), 'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.6'],
    ];

    if ($shopEnabled && $products->isNotEmpty()) {
        $staticPages[] = ['url' => Seo::url('/shop'), 'lastmod' => now()->toDateString(), 'freq' => 'daily', 'priority' => '0.8'];
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

    foreach ($staticPages as $page) {
        $xml .= "  <url>\n";
        $xml .= '    <loc>'.e($page['url'])."</loc>\n";
        $xml .= "    <lastmod>{$page['lastmod']}</lastmod>\n";
        $xml .= "    <changefreq>{$page['freq']}</changefreq>\n";
        $xml .= "    <priority>{$page['priority']}</priority>\n";
        $xml .= "  </url>\n";
    }

    foreach ($stories as $story) {
        $url = Seo::url('/stories/'.$story->slug);
        $lastmod = $story->updated_at ? $story->updated_at->toDateString() : now()->toDateString();
        $xml .= "  <url>\n";
        $xml .= '    <loc>'.e($url)."</loc>\n";
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "    <changefreq>weekly</changefreq>\n";
        $xml .= "    <priority>0.8</priority>\n";
        $xml .= "  </url>\n";
    }

    foreach ($productCategories as $category) {
        $xml .= "  <url>\n";
        $xml .= '    <loc>'.e(Seo::url('/shop/'.$category->slug))."</loc>\n";
        $xml .= '    <lastmod>'.($category->updated_at?->toDateString() ?? now()->toDateString())."</lastmod>\n";
        $xml .= "    <changefreq>weekly</changefreq>\n";
        $xml .= "    <priority>0.7</priority>\n";
        $xml .= "  </url>\n";
    }

    foreach ($products as $product) {
        $xml .= "  <url>\n";
        $xml .= '    <loc>'.e(Seo::url('/shop/product/'.$product->slug))."</loc>\n";
        $xml .= '    <lastmod>'.($product->updated_at?->toDateString() ?? now()->toDateString())."</lastmod>\n";
        $xml .= "    <changefreq>weekly</changefreq>\n";
        $xml .= "    <priority>0.7</priority>\n";
        $xml .= "  </url>\n";
    }

    $xml .= '</urlset>';

    return response($xml, 200)
        ->header('Content-Type', 'application/xml; charset=UTF-8')
        ->header('Cache-Control', 'public, max-age=300, s-maxage=86400, stale-while-revalidate=604800');
})->name('sitemap');

Route::get('/faq', [PageController::class, 'faq'])->name('faq');
Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::post('/contact', [PageController::class, 'submitContact'])->name('contact.submit');
Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [PageController::class, 'terms'])->name('terms');
Route::get('/pricing', [PageController::class, 'pricing'])->name('pricing');
Route::get('/how-it-works', [PageController::class, 'howItWorks'])->name('how-it-works');

// Customer Dashboard
Route::get('/dashboard', function () {
    $orders = Order::where('user_id', auth()->id())
        ->with(['story', 'previews', 'statusLogs'])
        ->latest()
        ->get();

    return view('dashboard', compact('orders'));
})->middleware(['auth', 'verified'])->name('dashboard');

// Customer Profile
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Admin Routes
Route::middleware(['auth', 'is_admin', 'admin_audit'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminHomeController::class)->name('home');
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view')
        ->name('dashboard.index');
    Route::get('analytics', [AnalyticsController::class, 'index'])
        ->middleware('permission:analytics.view')
        ->name('analytics.index');
    Route::post('analytics/refresh', [AnalyticsController::class, 'refresh'])
        ->middleware(['permission:analytics.view', 'throttle:6,1'])
        ->name('analytics.refresh');
    Route::get('visitor-carts', [VisitorCartController::class, 'index'])
        ->middleware('permission:visitor_carts.view')
        ->name('visitor-carts.index');
    Route::get('visitor-carts/{visitorCart}', [VisitorCartController::class, 'show'])
        ->middleware('permission:visitor_carts.view')
        ->name('visitor-carts.show');

    Route::resource('stories', App\Http\Controllers\Admin\StoryController::class)
        ->middlewareFor(['index', 'show'], 'permission:stories.view')
        ->middlewareFor(['create', 'store'], 'permission:stories.create')
        ->middlewareFor(['edit', 'update'], 'permission:stories.update')
        ->middlewareFor('destroy', 'permission:stories.delete');
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'destroy'])
        ->middlewareFor('index', 'permission:story_categories.view')
        ->middlewareFor('store', 'permission:story_categories.create')
        ->middlewareFor('destroy', 'permission:story_categories.delete');

    Route::resource('product-categories', ProductCategoryController::class)->except(['show'])
        ->middlewareFor('index', 'permission:store.categories.view')
        ->middlewareFor(['create', 'store'], 'permission:store.categories.create')
        ->middlewareFor(['edit', 'update'], 'permission:store.categories.update')
        ->middlewareFor('destroy', 'permission:store.categories.delete');
    Route::resource('products', ProductController::class)->except(['show'])
        ->middlewareFor('index', 'permission:store.products.view')
        ->middlewareFor(['create', 'store'], 'permission:store.products.create')
        ->middlewareFor(['edit', 'update'], 'permission:store.products.update')
        ->middlewareFor('destroy', 'permission:store.products.delete');
    Route::post('products/{product}/variants', [ProductVariantController::class, 'store'])
        ->middleware('permission:store.variants.create')
        ->name('products.variants.store');
    Route::put('product-variants/{variant}', [ProductVariantController::class, 'update'])
        ->middleware('permission:store.variants.update')
        ->name('product-variants.update');
    Route::delete('product-variants/{variant}', [ProductVariantController::class, 'destroy'])
        ->middleware('permission:store.variants.delete')
        ->name('product-variants.destroy');
    Route::resource('homepage-store-sections', HomepageStoreSectionController::class)->except(['show'])
        ->middlewareFor('index', 'permission:store.homepage_sections.view')
        ->middlewareFor(['create', 'store'], 'permission:store.homepage_sections.create')
        ->middlewareFor(['edit', 'update'], 'permission:store.homepage_sections.update')
        ->middlewareFor('destroy', 'permission:store.homepage_sections.delete');
    Route::resource('upsell-rules', ProductUpsellRuleController::class)->except(['show'])
        ->middlewareFor('index', 'permission:store.upsell_rules.view')
        ->middlewareFor(['create', 'store'], 'permission:store.upsell_rules.create')
        ->middlewareFor(['edit', 'update'], 'permission:store.upsell_rules.update')
        ->middlewareFor('destroy', 'permission:store.upsell_rules.delete');

    // Story Attachments (private — admin only)
    Route::post('stories/{story}/attachments', [StoryAttachmentController::class, 'store'])->middleware('permission:story_attachments.create')->name('stories.attachments.store');
    Route::get('attachments/{attachment}/download', [StoryAttachmentController::class, 'download'])->middleware('permission:story_attachments.view')->name('attachments.download');
    Route::delete('attachments/{attachment}', [StoryAttachmentController::class, 'destroy'])->middleware('permission:story_attachments.delete')->name('attachments.destroy');

    Route::get('orders', [OrderController::class, 'index'])->middleware('permission:orders.view')->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('permission:orders.view')->name('orders.show');
    Route::patch('orders/{order}', [OrderController::class, 'update'])->middleware('permission:orders.update')->name('orders.update');
    Route::post('orders/{order}/preview', [OrderController::class, 'uploadPreview'])->middleware('permission:orders.preview.upload')->name('orders.upload-preview');
    Route::get('orders/{order}/photos/{index}', [OrderController::class, 'servePhoto'])->middleware('permission:orders.photos.view')->name('orders.photo')->where('index', '[0-9]+');
    Route::get('orders/{order}/production-prompt/regenerate', [OrderProductionPromptController::class, 'regenerate'])->middleware('permission:orders.production_prompt.manage')->name('orders.production-prompt.regenerate');
    Route::post('orders/{order}/production-prompt/override', [OrderProductionPromptController::class, 'saveOverride'])->middleware('permission:orders.production_prompt.manage')->name('orders.production-prompt.override');
    Route::delete('orders/{order}/production-prompt/override', [OrderProductionPromptController::class, 'resetOverride'])->middleware('permission:orders.production_prompt.manage')->name('orders.production-prompt.override-reset');
    Route::post('orders/{order}/production-prompt/snapshot', [OrderProductionPromptController::class, 'saveSnapshot'])->middleware('permission:orders.production_prompt.manage')->name('orders.production-prompt.snapshot');

    Route::get('production-studio', [ProductionStudioController::class, 'index'])->middleware('permission:production_studio.view')->name('production-studio.index');
    Route::post('production-studio/from-order/{order}', [ProductionStudioController::class, 'storeFromOrder'])->middleware('permission:production_studio.create_from_order')->name('production-studio.from-order');
    Route::get('production-studio/{project}', [ProductionStudioController::class, 'show'])->middleware('permission:production_studio.view')->name('production-studio.show');
    Route::get('production-studio/{project}/photos/{index}', [ProductionStudioController::class, 'servePhoto'])->whereNumber('index')->middleware('permission:production_studio.view')->name('production-studio.photo');
    Route::get('production-studio/{project}/assets/{asset}', [ProductionStudioController::class, 'serveGeneratedAsset'])->middleware('permission:production_studio.ai_review')->name('production-studio.assets.show');
    Route::patch('production-studio/{project}', [ProductionStudioController::class, 'update'])->middleware('permission:production_studio.manage')->name('production-studio.update');
    Route::post('production-studio/{project}/archive', [ProductionStudioController::class, 'archive'])->middleware('permission:production_studio.archive')->name('production-studio.archive');
    Route::post('production-studio/{project}/cancel', [ProductionStudioController::class, 'cancel'])->middleware('permission:production_studio.delete_or_cancel')->name('production-studio.cancel');
    Route::post('production-studio/{project}/reopen', [ProductionStudioController::class, 'reopen'])->middleware('permission:production_studio.archive')->name('production-studio.reopen');
    Route::post('production-studio/{project}/story-versions/from-story', [ProductionStudioController::class, 'createDraftFromStory'])->middleware('permission:production_studio.story_edit')->name('production-studio.story-versions.from-story');
    Route::post('production-studio/{project}/story-versions/extract-scenes', [ProductionStudioController::class, 'extractScenes'])->middleware('permission:production_studio.story_edit')->name('production-studio.story-versions.extract-scenes');
    Route::post('production-studio/{project}/story-versions/apply-scenes', [ProductionStudioController::class, 'applyExtractedScenes'])->middleware('permission:production_studio.story_edit')->name('production-studio.story-versions.apply-scenes');
    Route::patch('production-studio/{project}/story-versions/{version}', [ProductionStudioController::class, 'reviewStoryVersion'])->middleware('permission:production_studio.story_edit')->name('production-studio.story-versions.review');
    Route::patch('production-studio/{project}/character-profile', [ProductionStudioController::class, 'updateCharacterProfile'])->middleware('permission:production_studio.character_profile_edit')->name('production-studio.character-profile.update');
    Route::post('production-studio/{project}/character-profile/analyze', [ProductionStudioController::class, 'analyzeCharacterProfile'])->middleware('permission:production_studio.character_profile_edit')->name('production-studio.character-profile.analyze');
    Route::post('production-studio/{project}/character-profile/apply-analysis', [ProductionStudioController::class, 'applyCharacterAnalysis'])->middleware('permission:production_studio.character_profile_edit')->name('production-studio.character-profile.apply-analysis');
    Route::post('production-studio/{project}/scenes', [ProductionStudioController::class, 'storeScene'])->middleware('permission:production_studio.scene_edit')->name('production-studio.scenes.store');
    Route::post('production-studio/{project}/scenes/replace-template-hero', [ProductionStudioController::class, 'replaceTemplateHeroInScenes'])->middleware('permission:production_studio.scene_edit')->name('production-studio.scenes.replace-template-hero');
    Route::patch('production-studio/{project}/scenes/{scene}', [ProductionStudioController::class, 'updateScene'])->middleware('permission:production_studio.scene_edit')->name('production-studio.scenes.update');
    Route::post('production-studio/{project}/scenes/{scene}/improve', [ProductionStudioController::class, 'improveScene'])->middleware('permission:production_studio.scene_edit')->name('production-studio.scenes.improve');
    Route::post('production-studio/{project}/scenes/{scene}/apply-improvement', [ProductionStudioController::class, 'applySceneImprovement'])->middleware('permission:production_studio.scene_edit')->name('production-studio.scenes.apply-improvement');
    Route::patch('production-studio/{project}/qa/{qaCheck}', [ProductionStudioController::class, 'updateQa'])->middleware('permission:production_studio.qa_review')->name('production-studio.qa.update');
    Route::post('production-studio/{project}/ai/character-sheet', [ProductionStudioController::class, 'generateCharacterSheet'])->middleware('permission:production_studio.ai_generate')->name('production-studio.ai.character-sheet');
    Route::post('production-studio/{project}/ai/cover', [ProductionStudioController::class, 'generateCoverImage'])->middleware('permission:production_studio.ai_generate')->name('production-studio.ai.cover');
    Route::post('production-studio/{project}/scenes/{scene}/ai', [ProductionStudioController::class, 'generateSceneImage'])->middleware('permission:production_studio.ai_generate')->name('production-studio.ai.scene');
    Route::post('production-studio/{project}/scenes/ai/bulk', [ProductionStudioController::class, 'generateAllSceneImages'])->middleware('permission:production_studio.ai_generate')->name('production-studio.ai.scenes.bulk');
    Route::get('production-studio/{project}/ai/job-log', [ProductionStudioController::class, 'generationJobLog'])->middleware('permission:production_studio.ai_review')->name('production-studio.ai.jobs.log');
    Route::get('production-studio/{project}/ai/jobs/{generationJob}', [ProductionStudioController::class, 'generationJobStatus'])->middleware('permission:production_studio.ai_review')->name('production-studio.ai.jobs.status');
    Route::get('production-studio/{project}/ai/jobs', [ProductionStudioController::class, 'generationJobsStatus'])->middleware('permission:production_studio.ai_review')->name('production-studio.ai.jobs.bulk-status');
    Route::post('production-studio/{project}/ai/jobs/{generationJob}/retry', [ProductionStudioController::class, 'retryGeneration'])->middleware('permission:production_studio.ai_retry')->name('production-studio.ai.retry');
    Route::post('production-studio/{project}/assets/{asset}/identity-correction', [ProductionStudioController::class, 'correctAssetIdentity'])->middleware('permission:production_studio.ai_retry')->name('production-studio.assets.identity-correction');
    Route::post('production-studio/{project}/assets/{asset}/approve', [ProductionStudioController::class, 'approveAsset'])->middleware('permission:production_studio.ai_approve')->name('production-studio.assets.approve');
    Route::post('production-studio/{project}/assets/{asset}/reject', [ProductionStudioController::class, 'rejectAsset'])->middleware('permission:production_studio.ai_approve')->name('production-studio.assets.reject');
    Route::delete('production-studio/{project}/assets/{asset}', [ProductionStudioController::class, 'deleteAsset'])->middleware('permission:production_studio.ai_approve')->name('production-studio.assets.delete');
    Route::post('production-studio/{project}/layout/assets', [ProductionStudioController::class, 'uploadLayoutAsset'])->middleware('permission:production_studio.layout_manage')->name('production-studio.layout.assets.store');
    Route::get('production-studio/{project}/layout/assets/{asset}/preview', [ProductionStudioController::class, 'serveGeneratedAsset'])->middleware('permission:production_studio.layout_manage')->name('production-studio.layout.assets.preview');
    Route::post('production-studio/{project}/layout/save', [ProductionStudioController::class, 'saveLayout'])->middleware('permission:production_studio.layout_manage')->name('production-studio.layout.save');
    Route::post('production-studio/{project}/layout/generate', [ProductionStudioController::class, 'generateLayout'])->middleware('permission:production_studio.layout_manage')->name('production-studio.layout.generate');
    Route::get('production-studio/{project}/layout/preview', [ProductionStudioController::class, 'previewLayout'])->middleware('permission:production_studio.layout_manage')->name('production-studio.layout.preview');
    Route::get('production-studio/{project}/layout/{layout}/status', [ProductionStudioController::class, 'layoutStatus'])->middleware('permission:production_studio.layout_manage')->name('production-studio.layout.status');
    Route::get('production-studio/{project}/layout/{layout}/download/{file}', [ProductionStudioController::class, 'downloadLayoutFile'])->middleware('permission:production_studio.layout_download')->name('production-studio.layout.download');
    Route::post('production-studio/{project}/automation/preflight', [ProductionAutomationController::class, 'preflight'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.preflight');
    Route::post('production-studio/{project}/automation/start', [ProductionAutomationController::class, 'start'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.start');
    Route::get('production-studio/{project}/automation/status', [ProductionAutomationController::class, 'status'])->middleware('permission:production_studio.view')->name('production-studio.automation.status');
    Route::post('production-studio/{project}/automation/pause', [ProductionAutomationController::class, 'pause'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.pause');
    Route::post('production-studio/{project}/automation/resume', [ProductionAutomationController::class, 'resume'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.resume');
    Route::post('production-studio/{project}/automation/budget', [ProductionAutomationController::class, 'increaseBudget'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.budget');
    Route::post('production-studio/{project}/automation/cancel', [ProductionAutomationController::class, 'cancel'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.cancel');
    Route::post('production-studio/{project}/automation/retry-step', [ProductionAutomationController::class, 'retryStep'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.retry-step');
    Route::post('production-studio/{project}/automation/story-preparation/approve', [ProductionAutomationController::class, 'approveStoryPreparation'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.story-preparation.approve');
    Route::post('production-studio/{project}/automation/character-profile/correct', [ProductionAutomationController::class, 'correctCharacterProfile'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.character-profile.correct');
    Route::post('production-studio/{project}/automation/child-reference/{asset}/approve', [ProductionAutomationController::class, 'approveChildReference'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.child-reference.approve');
    Route::post('production-studio/{project}/automation/child-reference/{asset}/reject', [ProductionAutomationController::class, 'rejectChildReference'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.child-reference.reject');
    Route::post('production-studio/{project}/automation/phase3/assets/{asset}/approve', [ProductionAutomationController::class, 'approvePhase3Asset'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.phase3.assets.approve');
    Route::post('production-studio/{project}/automation/phase3/assets/{asset}/reject', [ProductionAutomationController::class, 'rejectPhase3Asset'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.phase3.assets.reject');
    Route::post('production-studio/{project}/automation/phase3/scenes/{scene}/correct', [ProductionAutomationController::class, 'correctPhase3Scene'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.phase3.scenes.correct');
    Route::post('production-studio/{project}/automation/phase4/layouts/{layout}/retry', [ProductionAutomationController::class, 'retryPhase4Layout'])->middleware('permission:production_studio.automation_manage')->name('production-studio.automation.phase4.layouts.retry');
    Route::post('production-studio/{project}/automation/final-proof/draft', [ProductionAutomationController::class, 'createFinalProofDraft'])->middleware('permission:production_studio.final_proof_review')->name('production-studio.automation.final-proof.draft');
    Route::post('production-studio/{project}/automation/final-proof', [ProductionAutomationController::class, 'finalProof'])->middleware('permission:production_studio.final_proof_review')->name('production-studio.automation.final-proof');
    Route::post('production-studio/{project}/automation/final-proof/{proof}/approve', [ProductionAutomationController::class, 'approveFinalProof'])->middleware('permission:production_studio.final_proof_review')->name('production-studio.automation.final-proof.approve');
    Route::post('production-studio/{project}/automation/final-proof/{proof}/reject', [ProductionAutomationController::class, 'rejectFinalProof'])->middleware('permission:production_studio.final_proof_review')->name('production-studio.automation.final-proof.reject');
    Route::get('production-studio/{project}/automation/runs/{run}/layouts/{layout}/download/{file}', [ProductionAutomationController::class, 'download'])->middleware(['permission:production_studio.layout_download', 'signed'])->name('production-studio.automation.download');
    Route::get('production-studio/{project}/automation/runs/{run}/proofs/{proof}/report', [ProductionAutomationController::class, 'downloadProofReport'])->middleware(['permission:production_studio.final_proof_review', 'signed'])->name('production-studio.automation.proof-report');

    Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view')->name('customers.index');
    Route::get('customers/{customerKey}/edit', [CustomerController::class, 'edit'])->middleware('permission:customers.update')->name('customers.edit');
    Route::put('customers/{customerKey}', [CustomerController::class, 'update'])->middleware('permission:customers.update')->name('customers.update');
    Route::get('customers/{customerKey}', [CustomerController::class, 'show'])->middleware('permission:customers.view')->name('customers.show');

    // Content Management
    Route::delete('faqs/bulk-delete', [FaqController::class, 'bulkDestroy'])->middleware('permission:content.faqs.delete')->name('faqs.bulk-destroy');
    Route::resource('faqs', FaqController::class)->except(['show'])
        ->middlewareFor('index', 'permission:content.faqs.view')
        ->middlewareFor(['create', 'store'], 'permission:content.faqs.create')
        ->middlewareFor(['edit', 'update'], 'permission:content.faqs.update')
        ->middlewareFor('destroy', 'permission:content.faqs.delete');
    Route::resource('testimonials', TestimonialController::class)->except(['show'])
        ->middlewareFor('index', 'permission:content.testimonials.view')
        ->middlewareFor(['create', 'store'], 'permission:content.testimonials.create')
        ->middlewareFor(['edit', 'update'], 'permission:content.testimonials.update')
        ->middlewareFor('destroy', 'permission:content.testimonials.delete');

    Route::get('messages', [ContactMessageController::class, 'index'])->middleware('permission:content.messages.view')->name('messages.index');
    Route::get('messages/{message}', [ContactMessageController::class, 'show'])->middleware('permission:content.messages.view')->name('messages.show');
    Route::delete('messages/{message}', [ContactMessageController::class, 'destroy'])->middleware('permission:content.messages.delete')->name('messages.destroy');

    // Settings
    Route::get('settings', [SettingsController::class, 'index'])->middleware('permission:settings.site.view')->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->middleware('permission:settings.site.update')->name('settings.update');
    Route::get('settings/story-production-prompt', [StoryProductionPromptTemplateController::class, 'edit'])->middleware('permission:settings.production_prompt.view')->name('settings.story-production-prompt.edit');
    Route::put('settings/story-production-prompt', [StoryProductionPromptTemplateController::class, 'update'])->middleware('permission:settings.production_prompt.manage')->name('settings.story-production-prompt.update');
    Route::post('settings/story-production-prompt/preview', [StoryProductionPromptTemplateController::class, 'preview'])->middleware('permission:settings.production_prompt.manage')->name('settings.story-production-prompt.preview');
    Route::post('settings/story-production-prompt/reset', [StoryProductionPromptTemplateController::class, 'reset'])->middleware('permission:settings.production_prompt.manage')->name('settings.story-production-prompt.reset');
    Route::get('settings/ai-providers', [AiProviderSettingsController::class, 'index'])->middleware('permission:settings.ai_providers.view')->name('settings.ai-providers.index');
    Route::get('settings/ai-providers/{provider}', [AiProviderSettingsController::class, 'edit'])->middleware('permission:settings.ai_providers.view')->name('settings.ai-providers.edit');
    Route::put('settings/ai-providers/{provider}', [AiProviderSettingsController::class, 'update'])->middleware('permission:settings.ai_providers.manage')->name('settings.ai-providers.update');
    Route::delete('settings/ai-providers/{provider}/credential', [AiProviderSettingsController::class, 'removeCredential'])->middleware('permission:settings.ai_providers.manage_credentials')->name('settings.ai-providers.credential.destroy');
    Route::post('settings/ai-providers/{provider}/test', [AiProviderSettingsController::class, 'testConnection'])->middleware('permission:settings.ai_providers.test_connection')->name('settings.ai-providers.test');
    Route::get('settings/ai-providers/{provider}/models', [AiProviderSettingsController::class, 'models'])->middleware('permission:settings.ai_providers.view')->name('settings.ai-providers.models');
    Route::put('settings/ai-providers/{provider}/models', [AiProviderSettingsController::class, 'updateModels'])->middleware('permission:settings.ai_providers.manage_models')->name('settings.ai-providers.models.update');
    Route::get('settings/notifications', [NotificationCenterController::class, 'index'])->middleware('permission:settings.notifications.view')->name('settings.notifications.index');
    Route::put('settings/notifications/telegram', [NotificationCenterController::class, 'updateTelegram'])->name('settings.notifications.telegram.update');
    Route::delete('settings/notifications/telegram/token', [NotificationCenterController::class, 'removeTelegramToken'])->middleware('permission:settings.notifications.manage_credentials')->name('settings.notifications.telegram.token.destroy');
    Route::post('settings/notifications/telegram/test', [NotificationCenterController::class, 'testTelegram'])->middleware(['permission:settings.notifications.test', 'throttle:5,1'])->name('settings.notifications.telegram.test');
    Route::put('settings/notifications/rules', [NotificationCenterController::class, 'updateRules'])->middleware('permission:settings.notifications.manage_rules')->name('settings.notifications.rules.update');
    Route::put('settings/notifications/thresholds', [NotificationCenterController::class, 'updateThresholds'])->middleware('permission:settings.notifications.manage')->name('settings.notifications.thresholds.update');
    Route::get('delivery-zones', [DeliveryZoneController::class, 'index'])->middleware('permission:settings.delivery_zones.view')->name('delivery-zones.index');
    Route::post('delivery-zones/countries', [DeliveryZoneController::class, 'storeCountry'])->middleware('permission:settings.delivery_zones.create')->name('delivery-zones.countries.store');
    Route::put('delivery-zones/countries/{country}', [DeliveryZoneController::class, 'updateCountry'])->middleware('permission:settings.delivery_zones.update')->name('delivery-zones.countries.update');
    Route::delete('delivery-zones/countries/{country}', [DeliveryZoneController::class, 'destroyCountry'])->middleware('permission:settings.delivery_zones.delete')->name('delivery-zones.countries.destroy');
    Route::post('delivery-zones/governorates', [DeliveryZoneController::class, 'storeGovernorate'])->middleware('permission:settings.delivery_zones.create')->name('delivery-zones.governorates.store');
    Route::put('delivery-zones/governorates/{governorate}', [DeliveryZoneController::class, 'updateGovernorate'])->middleware('permission:settings.delivery_zones.update')->name('delivery-zones.governorates.update');
    Route::delete('delivery-zones/governorates/{governorate}', [DeliveryZoneController::class, 'destroyGovernorate'])->middleware('permission:settings.delivery_zones.delete')->name('delivery-zones.governorates.destroy');

    // Admin Users Management
    Route::resource('users', UserController::class)->except(['show'])
        ->middlewareFor('index', 'permission:admin_users.view')
        ->middlewareFor(['create', 'store'], 'permission:admin_users.create,admin_users.permissions.manage')
        ->middlewareFor('destroy', 'permission:admin_users.delete');

    // Pricing Packages
    Route::resource('pricing', PricingPackageController::class)->except(['show'])
        ->middlewareFor('index', 'permission:settings.pricing.view')
        ->middlewareFor(['create', 'store'], 'permission:settings.pricing.create')
        ->middlewareFor(['edit', 'update'], 'permission:settings.pricing.update')
        ->middlewareFor('destroy', 'permission:settings.pricing.delete');

    // Admin Activity Logs
    Route::get('activity-logs', [AdminActivityLogController::class, 'index'])->middleware('permission:activity_logs.view')->name('activity-logs.index');
    Route::get('activity-logs/{activityLog}', [AdminActivityLogController::class, 'show'])->middleware('permission:activity_logs.view')->name('activity-logs.show');
});

require __DIR__.'/auth.php';
