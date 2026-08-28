<?php

use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\AdminHomeController;
use App\Http\Controllers\Admin\AiProviderSettingsController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\BookletPreviewController as AdminBookletPreviewController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ChildIdentityController as AdminChildIdentityController;
use App\Http\Controllers\Admin\ChildIdentityMediaController as AdminChildIdentityMediaController;
use App\Http\Controllers\Admin\ChildIdentitySettingsController;
use App\Http\Controllers\Admin\ChildIdentityShareController as AdminChildIdentityShareController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeliveryZoneController;
use App\Http\Controllers\Admin\ExpenseCategoryController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\HomepageStoreSectionController;
use App\Http\Controllers\Admin\MobileOperationsController;
use App\Http\Controllers\Admin\NotificationCenterController;
use App\Http\Controllers\Admin\OrderAssignmentController;
use App\Http\Controllers\Admin\OrderAdminNoteController;
use App\Http\Controllers\Admin\OrderAttachmentController;
use App\Http\Controllers\Admin\OrderChildIdentityPromptController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderEditController;
use App\Http\Controllers\Admin\OrderGroupController;
use App\Http\Controllers\Admin\OrderProductionPromptController;
use App\Http\Controllers\Admin\OrderProductProductionController;
use App\Http\Controllers\Admin\OrderStatusDefinitionController;
use App\Http\Controllers\Admin\OrderWhatsAppTemplateController;
use App\Http\Controllers\Admin\PricingPackageController;
use App\Http\Controllers\Admin\ProductCategoryController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductionAutomationController;
use App\Http\Controllers\Admin\ProductionStudioController;
use App\Http\Controllers\Admin\ProductUpsellRuleController;
use App\Http\Controllers\Admin\ProductVariantController;
use App\Http\Controllers\Admin\SalesReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StoryAttachmentController;
use App\Http\Controllers\Admin\StoryProductionPromptTemplateController;
use App\Http\Controllers\Admin\TestimonialController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VisitorCartController;
use App\Http\Controllers\Front\BookletPreviewController as PublicBookletPreviewController;
use App\Http\Controllers\Front\CartController;
use App\Http\Controllers\Front\CheckoutController;
use App\Http\Controllers\Front\ChildIdentityController;
use App\Http\Controllers\Front\ChildIdentityMediaController;
use App\Http\Controllers\Front\ChildIdentityShareController;
use App\Http\Controllers\Front\CustomerPreviewDecisionController;
use App\Http\Controllers\Front\FootballStoriesController;
use App\Http\Controllers\Front\PackageCartController;
use App\Http\Controllers\Front\PackageController;
use App\Http\Controllers\Front\PageController;
use App\Http\Controllers\Front\ProductCartController;
use App\Http\Controllers\Front\PublicChildIdentityShareController;
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
use App\Services\Catalog\CatalogSalesRankingService;
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
    $featuredStories = collect();
    if (homepage_section_enabled('hero') || homepage_section_enabled('stories')) {
        $activeStories = Story::where('active', true)->with('categories')->get();
        $salesCounts = app(CatalogSalesRankingService::class)
            ->counts($activeStories->pluck('id'), collect())['stories'];
        $featuredStories = $activeStories
            ->sortBy(fn (Story $story): array => [
                -($salesCounts[$story->id] ?? 0),
                $story->title,
            ])
            ->take(8)
            ->values();
    }
    $faqs = homepage_section_enabled('faq')
        ? FaqItem::where('active', true)->orderBy('sort_order')->take(5)->get()
        : collect();
    $testimonials = homepage_section_enabled('testimonials')
        ? Testimonial::where('active', true)->orderBy('sort_order')->get()
        : collect();
    $packages = homepage_section_enabled('pricing')
        ? PricingPackage::active()->purchasable()->where('show_on_homepage', true)->where('show_in_store', true)->with(['items.product', 'items.variant', 'eligibleStories'])->ordered()->get()->filter->availableForPurchase()->take(5)->values()
        : collect();
    $storeSections = homepage_section_enabled('store') && setting('shop_enabled', '1') === '1'
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
Route::get('/football-stories', [FootballStoriesController::class, 'index'])->name('football-stories.index');
Route::post('/football-stories/cart', [FootballStoriesController::class, 'store'])->name('football-stories.store');
Route::get('/stories', [StoryController::class, 'index'])->name('stories.index');
Route::get('/stories/{slug}', [StoryController::class, 'show'])->name('stories.show');

Route::get('/preview/{token}', [PublicBookletPreviewController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:120,1')
    ->name('booklet-previews.show');
Route::get('/preview/{token}/scenes', [PublicBookletPreviewController::class, 'scenes'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:120,1')
    ->name('booklet-previews.scenes');
Route::get('/preview-media/{bookletPreview}', [PublicBookletPreviewController::class, 'document'])
    ->middleware('throttle:300,1')
    ->name('booklet-previews.document');

// Public Store Routes
Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/shop/package/{pricingPackage:slug}', [PackageController::class, 'show'])->name('shop.package.show');
Route::get('/shop/product/{product:slug}', [ShopController::class, 'show'])->name('shop.product.show');
Route::get('/shop/{category:slug}', [ShopController::class, 'category'])->name('shop.category');

// Create Your Child Identity — private, resumable, and independent from production.
Route::prefix('child-identity')->name('child-identity.')->group(function (): void {
    Route::get('/', [ChildIdentityController::class, 'index'])->name('index');
    Route::post('/', [ChildIdentityController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::get('resume/{identity:uuid}/{token}', [ChildIdentityController::class, 'resume'])
        ->middleware('throttle:20,1')
        ->name('resume');
    Route::get('{identity:uuid}', [ChildIdentityController::class, 'show'])->name('show');
    Route::post('{identity:uuid}/photos', [ChildIdentityController::class, 'uploadPhoto'])
        ->middleware('throttle:20,1')
        ->name('photos.store');
    Route::post('{identity:uuid}/photos/{photo}/ai-input', [ChildIdentityController::class, 'storePhotoAiInput'])
        ->middleware('throttle:10,1')
        ->name('photos.ai-input');
    Route::delete('{identity:uuid}/photos/{photo}', [ChildIdentityController::class, 'removePhoto'])->name('photos.destroy');
    Route::post('{identity:uuid}/generate', [ChildIdentityController::class, 'generate'])
        ->middleware('throttle:6,1')
        ->name('generate');
    Route::get('{identity:uuid}/poll', [ChildIdentityController::class, 'poll'])
        ->middleware('throttle:60,1')
        ->name('poll');
    Route::post('{identity:uuid}/attempts/{attempt}/approve', [ChildIdentityController::class, 'approve'])->name('approve');
    Route::post('{identity:uuid}/category', [ChildIdentityController::class, 'selectCategory'])->name('category');
    Route::post('{identity:uuid}/story', [ChildIdentityController::class, 'selectStory'])->name('story');
    Route::post('{identity:uuid}/cart', [ChildIdentityController::class, 'addToCart'])->name('cart');
    Route::get('{identity:uuid}/media/photos/{photo}', [ChildIdentityMediaController::class, 'photo'])
        ->middleware('signed')
        ->name('media.photo');
    Route::get('{identity:uuid}/media/attempts/{attempt}', [ChildIdentityMediaController::class, 'attempt'])
        ->middleware('signed')
        ->name('media.attempt');
    Route::post('{identity:uuid}/shares', [ChildIdentityShareController::class, 'store'])
        ->name('shares.store');
    Route::patch('{identity:uuid}/shares/{share}', [ChildIdentityShareController::class, 'update'])
        ->name('shares.update');
    Route::post('{identity:uuid}/shares/{share}/revoke', [ChildIdentityShareController::class, 'revoke'])
        ->name('shares.revoke');
    Route::post('{identity:uuid}/shares/{share}/reenable', [ChildIdentityShareController::class, 'reenable'])
        ->name('shares.reenable');
    Route::post('{identity:uuid}/shares/{share}/regenerate', [ChildIdentityShareController::class, 'regenerate'])
        ->middleware('throttle:6,1')
        ->name('shares.regenerate');
    Route::post('{identity:uuid}/shares/{share}/events', [ChildIdentityShareController::class, 'event'])
        ->middleware('throttle:60,1')
        ->name('shares.events');
    Route::get('{identity:uuid}/shares/{share}/status', [ChildIdentityShareController::class, 'status'])
        ->middleware('throttle:60,1')
        ->name('shares.status');
});

Route::prefix('s')->name('child-identity-shares.')->group(function (): void {
    Route::get('{share:public_token}', [PublicChildIdentityShareController::class, 'show'])
        ->middleware('throttle:120,1')
        ->name('show');
    Route::get('{share:public_token}/card/{variant}', [PublicChildIdentityShareController::class, 'card'])
        ->middleware('throttle:240,1')
        ->name('card');
    Route::get('{share:public_token}/start', [PublicChildIdentityShareController::class, 'cta'])
        ->middleware('throttle:60,1')
        ->name('cta');
});

// Cart and checkout routes
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::get('/photo-uploads/session', [TemporaryPhotoUploadController::class, 'session'])->name('photo-uploads.session');
Route::post('/photo-uploads', [TemporaryPhotoUploadController::class, 'store'])->middleware('throttle:20,1')->name('photo-uploads.store');
Route::get('/photo-uploads/{publicId}', [TemporaryPhotoUploadController::class, 'show'])->name('photo-uploads.show');
Route::delete('/photo-uploads/{publicId}', [TemporaryPhotoUploadController::class, 'destroy'])->name('photo-uploads.destroy');
Route::post('/cart/stories/{story:slug}', [CartController::class, 'store'])->name('cart.store');
Route::post('/cart/products/{product:slug}', [ProductCartController::class, 'store'])->name('cart.products.store');
Route::post('/cart/packages/{pricingPackage:slug}', [PackageCartController::class, 'store'])->name('cart.packages.store');
Route::delete('/cart/{key}', [CartController::class, 'destroy'])->name('cart.destroy');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::get('/checkout/success', [CheckoutController::class, 'success'])->name('checkout.success');

// Order Tracking
Route::get('/track-order', [TrackOrderController::class, 'index'])->name('track.index');
Route::post('/track-order', [TrackOrderController::class, 'track'])->name('track.search');

// Preview Approval (customer)
Route::post('/orders/{order}/approve-preview', [CustomerPreviewDecisionController::class, 'approve'])
    ->middleware('auth')->name('orders.approve-preview');

Route::get('/orders/{order}/production-photos/{index}', [OrderController::class, 'serveProductionPhoto'])
    ->middleware('signed')
    ->name('orders.production-photo')
    ->where('index', '[0-9]+');
Route::get('/orders/{order}/approved-child-identity', [OrderController::class, 'serveApprovedChildIdentity'])
    ->middleware('signed')
    ->name('orders.approved-child-identity');

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
    $packages = $shopEnabled ? PricingPackage::active()->purchasable()
        ->where('show_in_store', true)
        ->with(['items.product', 'items.variant', 'eligibleStories'])
        ->select('id', 'slug', 'active', 'story_count', 'applies_to_all_stories', 'updated_at')
        ->get()
        ->filter->availableForPurchase() : collect();

    $staticPages = [
        ['url' => Seo::url('/'),             'lastmod' => now()->toDateString(), 'freq' => 'daily',   'priority' => '1.0'],
        ['url' => Seo::url('/shop'),         'lastmod' => now()->toDateString(), 'freq' => 'daily',   'priority' => '0.9'],
        ['url' => Seo::url('/football-stories'), 'lastmod' => now()->toDateString(), 'freq' => 'daily', 'priority' => '0.9'],
        ['url' => Seo::url('/packages'),     'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.8'],
        ['url' => Seo::url('/about'),        'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.8'],
        ['url' => Seo::url('/faq'),          'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.7'],
        ['url' => Seo::url('/contact'),      'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.6'],
        ['url' => Seo::url('/how-it-works'), 'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.6'],
    ];

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

    foreach ($packages as $package) {
        $url = Seo::url('/shop/package/'.$package->slug);
        $lastmod = $package->updated_at ? $package->updated_at->toDateString() : now()->toDateString();
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

Route::get('/about', [PageController::class, 'about'])->name('about');
Route::get('/faq', [PageController::class, 'faq'])->name('faq');
Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::post('/contact', [PageController::class, 'submitContact'])->name('contact.submit');
Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [PageController::class, 'terms'])->name('terms');
Route::get('/packages', [PageController::class, 'pricing'])->name('packages');
Route::redirect('/pricing', '/packages', 301)->name('pricing');
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
    Route::get('sales-report', [SalesReportController::class, 'index'])
        ->middleware('permission:sales_reports.view')
        ->name('sales-report.index');
    Route::get('sales-report/export', [SalesReportController::class, 'export'])
        ->middleware(['permission:sales_reports.view', 'throttle:10,1'])
        ->name('sales-report.export');
    Route::get('expenses', [ExpenseController::class, 'index'])
        ->middleware('permission:expenses.view')
        ->name('expenses.index');
    Route::get('expenses/export', [ExpenseController::class, 'export'])
        ->middleware(['permission:expenses.export', 'throttle:10,1'])
        ->name('expenses.export');
    Route::get('expenses/categories', [ExpenseCategoryController::class, 'index'])
        ->middleware('permission:expenses.manage_categories')
        ->name('expenses.categories.index');
    Route::post('expenses/categories', [ExpenseCategoryController::class, 'store'])
        ->middleware('permission:expenses.manage_categories')
        ->name('expenses.categories.store');
    Route::put('expenses/categories/{category}', [ExpenseCategoryController::class, 'update'])
        ->middleware('permission:expenses.manage_categories')
        ->name('expenses.categories.update');
    Route::get('expenses/create/{kind?}', [ExpenseController::class, 'create'])
        ->where('kind', 'income|expense|opening')
        ->name('expenses.create');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])
        ->middleware('permission:expenses.view')
        ->name('expenses.show');
    Route::get('expenses/{expense}/edit', [ExpenseController::class, 'edit'])
        ->middleware('permission:expenses.edit')
        ->name('expenses.edit');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('permission:expenses.edit')
        ->name('expenses.update');
    Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])
        ->middleware('permission:expenses.void')
        ->name('expenses.void');
    Route::get('expenses/{expense}/attachment', [ExpenseController::class, 'attachment'])
        ->middleware('permission:expenses.view_attachments')
        ->name('expenses.attachment');
    Route::get('expenses/{expense}/attachment/download', [ExpenseController::class, 'download'])
        ->middleware('permission:expenses.download_attachments')
        ->name('expenses.attachment.download');
    Route::get('visitor-carts', [VisitorCartController::class, 'index'])
        ->middleware('permission:visitor_carts.view')
        ->name('visitor-carts.index');
    Route::get('visitor-carts/{visitorCart}', [VisitorCartController::class, 'show'])
        ->middleware('permission:visitor_carts.view')
        ->name('visitor-carts.show');

    Route::get('booklet-previews', [AdminBookletPreviewController::class, 'index'])
        ->middleware('permission:booklet_previews.view')
        ->name('booklet-previews.index');
    Route::get('booklet-previews/create', [AdminBookletPreviewController::class, 'create'])
        ->middleware('permission:booklet_previews.create')
        ->name('booklet-previews.create');
    Route::post('booklet-previews', [AdminBookletPreviewController::class, 'store'])
        ->middleware('permission:booklet_previews.create')
        ->name('booklet-previews.store');
    Route::get('booklet-previews/{bookletPreview}', [AdminBookletPreviewController::class, 'show'])
        ->middleware('permission:booklet_previews.view')
        ->name('booklet-previews.show');
    Route::patch('booklet-previews/{bookletPreview}', [AdminBookletPreviewController::class, 'update'])
        ->middleware('permission:booklet_previews.update')
        ->name('booklet-previews.update');
    Route::post('booklet-previews/{bookletPreview}/versions', [AdminBookletPreviewController::class, 'replace'])
        ->middleware('permission:booklet_previews.update')
        ->name('booklet-previews.versions.store');
    Route::post('booklet-previews/{bookletPreview}/publish', [AdminBookletPreviewController::class, 'publish'])
        ->middleware('permission:booklet_previews.publish')
        ->name('booklet-previews.publish');
    Route::delete('booklet-previews/{bookletPreview}/publish', [AdminBookletPreviewController::class, 'unpublish'])
        ->middleware('permission:booklet_previews.publish')
        ->name('booklet-previews.unpublish');
    Route::post('booklet-previews/{bookletPreview}/revoke', [AdminBookletPreviewController::class, 'revoke'])
        ->middleware('permission:booklet_previews.revoke')
        ->name('booklet-previews.revoke');
    Route::post('booklet-previews/{bookletPreview}/reenable', [AdminBookletPreviewController::class, 'reenable'])
        ->middleware('permission:booklet_previews.revoke')
        ->name('booklet-previews.reenable');
    Route::delete('booklet-previews/{bookletPreview}', [AdminBookletPreviewController::class, 'destroy'])
        ->middleware('permission:booklet_previews.delete')
        ->name('booklet-previews.destroy');
    Route::post('booklet-previews/{bookletPreview}/restore', [AdminBookletPreviewController::class, 'restore'])
        ->middleware('permission:booklet_previews.delete')
        ->name('booklet-previews.restore');
    Route::get('booklet-previews/{bookletPreview}/versions/{version}/download', [AdminBookletPreviewController::class, 'download'])
        ->middleware('permission:booklet_previews.download_source')
        ->name('booklet-previews.versions.download');

    Route::get('child-identities', [AdminChildIdentityController::class, 'index'])
        ->middleware('permission:child_identities.view')
        ->name('child-identities.index');
    Route::get('child-identities/trash', [AdminChildIdentityController::class, 'index'])
        ->middleware('permission:child_identities.view')
        ->defaults('view', 'trash')
        ->name('child-identities.trash');
    Route::get('child-identities/settings', [ChildIdentitySettingsController::class, 'edit'])
        ->middleware('permission:child_identities.settings')
        ->name('child-identities.settings.edit');
    Route::put('child-identities/settings', [ChildIdentitySettingsController::class, 'update'])
        ->middleware('permission:child_identities.settings')
        ->name('child-identities.settings.update');
    Route::get('child-identities/share-report', [AdminChildIdentityShareController::class, 'report'])
        ->middleware('permission:child_identities.view_share_report')
        ->name('child-identities.share-report');
    Route::get('child-identities/{identity}', [AdminChildIdentityController::class, 'show'])
        ->whereNumber('identity')
        ->middleware('permission:child_identities.view')
        ->name('child-identities.show');
    Route::post('child-identities/{identity}/generate', [AdminChildIdentityController::class, 'generate'])
        ->whereNumber('identity')
        ->middleware(['permission:child_identities.generate', 'throttle:10,1'])
        ->name('child-identities.generate');
    Route::patch('child-identities/{identity}/prompt', [AdminChildIdentityController::class, 'updatePrompt'])
        ->whereNumber('identity')
        ->middleware('permission:child_identities.generate')
        ->name('child-identities.prompt.update');
    Route::post('child-identities/{identity}/attempts/{attempt}/approve', [AdminChildIdentityController::class, 'approve'])
        ->whereNumber('identity')
        ->middleware('permission:child_identities.approve')
        ->name('child-identities.attempts.approve');
    Route::post('child-identities/{identity}/attempts/{attempt}/reject', [AdminChildIdentityController::class, 'reject'])
        ->whereNumber('identity')
        ->middleware('permission:child_identities.approve')
        ->name('child-identities.attempts.reject');
    Route::delete('child-identities/{identity}', [AdminChildIdentityController::class, 'destroy'])
        ->whereNumber('identity')
        ->middleware('permission:child_identities.delete')
        ->name('child-identities.destroy');
    Route::post('child-identities/{identity}/restore', [AdminChildIdentityController::class, 'restore'])
        ->whereNumber('identity')
        ->middleware('permission:child_identities.restore')
        ->name('child-identities.restore');
    Route::delete('child-identities/{identity}/force', [AdminChildIdentityController::class, 'forceDelete'])
        ->whereNumber('identity')
        ->middleware('permission:child_identities.force_delete')
        ->name('child-identities.force-delete');
    Route::get('child-identities/{identity}/media/photos/{photo}', [AdminChildIdentityMediaController::class, 'photo'])
        ->whereNumber('identity')
        ->middleware(['permission:child_identities.view_media', 'signed'])
        ->name('child-identities.media.photo');
    Route::get('child-identities/{identity}/media/attempts/{attempt}', [AdminChildIdentityMediaController::class, 'attempt'])
        ->whereNumber('identity')
        ->middleware(['permission:child_identities.view_media', 'signed'])
        ->name('child-identities.media.attempt');
    Route::get('child-identity-shares/{share}/media/{variant}', [AdminChildIdentityMediaController::class, 'shareCard'])
        ->middleware(['permission:child_identities.view_media', 'signed'])
        ->name('child-identities.media.share-card');
    Route::post('child-identity-shares/{share}/regenerate', [AdminChildIdentityShareController::class, 'regenerate'])
        ->middleware(['permission:child_identities.manage_shares', 'throttle:10,1'])
        ->name('child-identity-shares.regenerate');
    Route::post('child-identity-shares/{share}/revoke', [AdminChildIdentityShareController::class, 'revoke'])
        ->middleware('permission:child_identities.manage_shares')
        ->name('child-identity-shares.revoke');
    Route::post('child-identity-shares/{share}/reenable', [AdminChildIdentityShareController::class, 'reenable'])
        ->middleware('permission:child_identities.manage_shares')
        ->name('child-identity-shares.reenable');
    Route::patch('child-identity-shares/{share}', [AdminChildIdentityShareController::class, 'update'])
        ->middleware('permission:child_identities.manage_shares')
        ->name('child-identity-shares.update');
    Route::delete('child-identity-shares/{share}/cards', [AdminChildIdentityShareController::class, 'removeCards'])
        ->middleware('permission:child_identities.manage_shares')
        ->name('child-identity-shares.cards.destroy');

    Route::post('stories/scene-import-preview', [App\Http\Controllers\Admin\StoryController::class, 'previewSceneImport'])
        ->name('stories.scene-import-preview');
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
    Route::get('orders/export', [OrderController::class, 'export'])->middleware(['permission:orders.view', 'throttle:10,1'])->name('orders.export');
    Route::get('orders/create', [OrderController::class, 'create'])->middleware('permission:orders.create')->name('orders.create');
    Route::post('orders', [OrderController::class, 'store'])->middleware('permission:orders.create')->name('orders.store');
    Route::get('orders/groups/{representative}', [OrderGroupController::class, 'show'])->whereNumber('representative')->middleware('permission:orders.view')->name('orders.groups.show');
    Route::get('orders/groups/{representative}/edit', [OrderEditController::class, 'edit'])->whereNumber('representative')->middleware('permission:orders.update')->name('orders.groups.edit');
    Route::put('orders/groups/{representative}', [OrderEditController::class, 'update'])->whereNumber('representative')->middleware('permission:orders.update')->name('orders.groups.update');
    Route::patch('orders/groups/{representative}/status', [OrderGroupController::class, 'updateStatus'])->whereNumber('representative')->middleware('permission:orders.update')->name('orders.groups.status');
    Route::patch('orders/groups/{representative}/payment', [OrderGroupController::class, 'updatePayment'])->whereNumber('representative')->middleware('permission:orders.update')->name('orders.groups.payment');
    Route::patch('orders/groups/{representative}/workflow-statuses', [OrderGroupController::class, 'updateWorkflowStatuses'])->whereNumber('representative')->middleware('permission:orders.update')->name('orders.groups.workflow-statuses');
    Route::post('orders/groups/{representative}/assignment/acquire', [OrderAssignmentController::class, 'acquire'])->whereNumber('representative')->middleware('permission:orders.assign')->name('orders.groups.assignment.acquire');
    Route::post('orders/groups/{representative}/assignment/takeover', [OrderAssignmentController::class, 'takeover'])->whereNumber('representative')->middleware('permission:orders.assignment.manage')->name('orders.groups.assignment.takeover');
    Route::delete('orders/groups/{representative}/assignment', [OrderAssignmentController::class, 'release'])->whereNumber('representative')->middleware('permission:orders.assign')->name('orders.groups.assignment.release');
    Route::delete('orders/groups/{representative}', [OrderGroupController::class, 'destroy'])->whereNumber('representative')->middleware('permission:orders.delete')->name('orders.groups.destroy');
    Route::post('orders/groups/{representative}/restore', [OrderGroupController::class, 'restore'])->whereNumber('representative')->middleware('permission:orders.delete')->name('orders.groups.restore');
    Route::get('orders/{order}/products/{item}/production', OrderProductProductionController::class)
        ->whereNumber(['order', 'item'])
        ->middleware('permission:orders.production_prompt.manage')
        ->name('orders.products.production');
    Route::put('orders/{order}/products/{item}/production-prompt', [OrderProductProductionController::class, 'updatePrompt'])
        ->whereNumber(['order', 'item'])
        ->middleware(['permission:orders.production_prompt.manage', 'permission:store.products.update'])
        ->name('orders.products.production-prompt.update');
    Route::post('orders/{order}/products/{item}/production-prompt/use-current', [OrderProductProductionController::class, 'useCurrentPrompt'])
        ->whereNumber(['order', 'item'])
        ->middleware('permission:orders.production_prompt.manage')
        ->name('orders.products.production-prompt.use-current');
    Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('permission:orders.view')->name('orders.show');
    Route::patch('orders/{order}', [OrderController::class, 'update'])->middleware('permission:orders.update')->name('orders.update');
    Route::post('orders/{order}/notes', [OrderAdminNoteController::class, 'store'])->whereNumber('order')->middleware('permission:orders.update')->name('orders.notes.store');
    Route::patch('orders/{order}/details', [OrderController::class, 'updateDetails'])->middleware('permission:orders.update')->name('orders.details.update');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->middleware('permission:orders.delete')->name('orders.destroy');
    Route::post('orders/{order}/restore', [OrderController::class, 'restore'])->whereNumber('order')->middleware('permission:orders.delete')->name('orders.restore');
    Route::post('orders/{order}/photos', [OrderController::class, 'uploadPhotos'])->middleware(['permission:orders.update', 'permission:orders.photos.view'])->name('orders.photos.store');
    Route::post('orders/{order}/attachments', [OrderAttachmentController::class, 'store'])->middleware('permission:orders.update')->name('orders.attachments.store');
    Route::get('order-attachments/{attachment}', [OrderAttachmentController::class, 'show'])->middleware('permission:orders.view')->name('orders.attachments.show');
    Route::get('order-attachments/{attachment}/download', [OrderAttachmentController::class, 'download'])->middleware('permission:orders.view')->name('orders.attachments.download');
    Route::delete('order-attachments/{attachment}', [OrderAttachmentController::class, 'destroy'])->middleware('permission:orders.update')->name('orders.attachments.destroy');
    Route::post('orders/{order}/preview', [OrderController::class, 'uploadPreview'])->middleware('permission:orders.preview.upload')->name('orders.upload-preview');
    Route::post('orders/{order}/booklet-preview', [AdminBookletPreviewController::class, 'storeForOrder'])->middleware('permission:orders.preview.upload')->name('orders.booklet-preview.store');
    Route::post('orders/{order}/previews/{legacyPreview}/promote', [AdminBookletPreviewController::class, 'promoteLegacy'])->middleware('permission:orders.preview.upload')->name('orders.previews.promote');
    Route::get('orders/{order}/photos/{index}', [OrderController::class, 'servePhoto'])->middleware('permission:orders.photos.view')->name('orders.photo')->where('index', '[0-9]+');
    Route::get('orders/{order}/production-prompt/regenerate', [OrderProductionPromptController::class, 'regenerate'])->middleware('permission:orders.production_prompt.manage')->name('orders.production-prompt.regenerate');
    Route::post('orders/{order}/production-prompt/override', [OrderProductionPromptController::class, 'saveOverride'])->middleware('permission:orders.production_prompt.manage')->name('orders.production-prompt.override');
    Route::delete('orders/{order}/production-prompt/override', [OrderProductionPromptController::class, 'resetOverride'])->middleware('permission:orders.production_prompt.manage')->name('orders.production-prompt.override-reset');
    Route::post('orders/{order}/production-prompt/snapshot', [OrderProductionPromptController::class, 'saveSnapshot'])->middleware('permission:orders.production_prompt.manage')->name('orders.production-prompt.snapshot');
    Route::get('orders/{order}/child-identity-prompt/regenerate', [OrderChildIdentityPromptController::class, 'regenerate'])->middleware('permission:orders.production_prompt.manage')->name('orders.child-identity-prompt.regenerate');
    Route::post('orders/{order}/child-identity-prompt/override', [OrderChildIdentityPromptController::class, 'saveOverride'])->middleware('permission:orders.production_prompt.manage')->name('orders.child-identity-prompt.override');
    Route::delete('orders/{order}/child-identity-prompt/override', [OrderChildIdentityPromptController::class, 'resetOverride'])->middleware('permission:orders.production_prompt.manage')->name('orders.child-identity-prompt.override-reset');
    Route::post('orders/{order}/child-identity-prompt/snapshot', [OrderChildIdentityPromptController::class, 'saveSnapshot'])->middleware('permission:orders.production_prompt.manage')->name('orders.child-identity-prompt.snapshot');

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
    Route::get('settings/order-statuses', [OrderStatusDefinitionController::class, 'index'])->middleware('permission:settings.order_statuses.manage')->name('settings.order-statuses.index');
    Route::post('settings/order-statuses', [OrderStatusDefinitionController::class, 'store'])->middleware('permission:settings.order_statuses.manage')->name('settings.order-statuses.store');
    Route::put('settings/order-statuses/{orderStatusDefinition}', [OrderStatusDefinitionController::class, 'update'])->middleware('permission:settings.order_statuses.manage')->name('settings.order-statuses.update');
    Route::delete('settings/order-statuses/{orderStatusDefinition}', [OrderStatusDefinitionController::class, 'destroy'])->middleware('permission:settings.order_statuses.manage')->name('settings.order-statuses.destroy');
    Route::get('settings/order-whatsapp-messages', [OrderWhatsAppTemplateController::class, 'edit'])->middleware('permission:settings.site.view')->name('settings.order-whatsapp-messages.edit');
    Route::put('settings/order-whatsapp-messages', [OrderWhatsAppTemplateController::class, 'update'])->middleware('permission:settings.site.update')->name('settings.order-whatsapp-messages.update');
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
    Route::get('mobile-operations', [MobileOperationsController::class, 'index'])->middleware('permission:settings.mobile.view')->name('mobile-operations.index');
    Route::put('mobile-operations/config', [MobileOperationsController::class, 'updateConfig'])->middleware('permission:settings.mobile.manage')->name('mobile-operations.config.update');
    Route::post('mobile-operations/promo-codes', [MobileOperationsController::class, 'storePromo'])->middleware('permission:settings.mobile.manage')->name('mobile-operations.promo-codes.store');
    Route::patch('mobile-operations/promo-codes/{promoCode}', [MobileOperationsController::class, 'updatePromo'])->middleware('permission:settings.mobile.manage')->name('mobile-operations.promo-codes.update');
    Route::patch('mobile-operations/privacy-requests/{privacyRequest}', [MobileOperationsController::class, 'updatePrivacyRequest'])->middleware('permission:settings.mobile.manage')->name('mobile-operations.privacy-requests.update');
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
    Route::patch('pricing/{pricing}/homepage-visibility', [PricingPackageController::class, 'updateHomepageVisibility'])
        ->middleware('permission:settings.pricing.update')
        ->name('pricing.homepage-visibility');
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
