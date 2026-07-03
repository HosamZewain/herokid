<?php

use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeliveryZoneController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\HomepageStoreSectionController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderProductionPromptController;
use App\Http\Controllers\Admin\PricingPackageController;
use App\Http\Controllers\Admin\ProductCategoryController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductUpsellRuleController;
use App\Http\Controllers\Admin\ProductVariantController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StoryAttachmentController;
use App\Http\Controllers\Admin\StoryProductionPromptTemplateController;
use App\Http\Controllers\Admin\TestimonialController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Front\CartController;
use App\Http\Controllers\Front\CheckoutController;
use App\Http\Controllers\Front\PageController;
use App\Http\Controllers\Front\ProductCartController;
use App\Http\Controllers\Front\ShopController;
use App\Http\Controllers\Front\StoryController;
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
    $featuredStories = Story::where('active', true)->with('categories')->latest()->take(8)->get();
    $faqs = FaqItem::where('active', true)->orderBy('sort_order')->take(5)->get();
    $testimonials = Testimonial::where('active', true)->orderBy('sort_order')->get();
    $packages = PricingPackage::active()->ordered()->get();
    $storeSections = HomepageStoreSection::query()
        ->with(['category.activeProducts' => fn ($query) => $query->orderByDesc('is_featured')->orderBy('sort_order')->latest()])
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->get()
        ->filter(fn ($section) => $section->category && $section->category->activeProducts->isNotEmpty());

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
    $stories = Story::where('active', true)
        ->select('slug', 'updated_at')
        ->orderBy('updated_at', 'desc')
        ->get();
    $productCategories = ProductCategory::where('is_active', true)
        ->where('show_in_store', true)
        ->whereHas('activeProducts')
        ->select('slug', 'updated_at')
        ->get();
    $products = Product::publiclyVisible()
        ->select('slug', 'updated_at')
        ->get();

    $staticPages = [
        ['url' => Seo::url('/'),             'lastmod' => now()->toDateString(), 'freq' => 'daily',   'priority' => '1.0'],
        ['url' => Seo::url('/stories'),      'lastmod' => now()->toDateString(), 'freq' => 'daily',   'priority' => '0.9'],
        ['url' => Seo::url('/shop'),         'lastmod' => now()->toDateString(), 'freq' => 'daily',   'priority' => '0.8'],
        ['url' => Seo::url('/pricing'),      'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.8'],
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
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard.index');

    Route::resource('stories', App\Http\Controllers\Admin\StoryController::class);
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'destroy']);

    Route::resource('product-categories', ProductCategoryController::class)->except(['show']);
    Route::resource('products', ProductController::class)->except(['show']);
    Route::post('products/{product}/variants', [ProductVariantController::class, 'store'])->name('products.variants.store');
    Route::put('product-variants/{variant}', [ProductVariantController::class, 'update'])->name('product-variants.update');
    Route::delete('product-variants/{variant}', [ProductVariantController::class, 'destroy'])->name('product-variants.destroy');
    Route::resource('homepage-store-sections', HomepageStoreSectionController::class)->except(['show']);
    Route::resource('upsell-rules', ProductUpsellRuleController::class)->except(['show']);

    // Story Attachments (private — admin only)
    Route::post('stories/{story}/attachments', [StoryAttachmentController::class, 'store'])->name('stories.attachments.store');
    Route::get('attachments/{attachment}/download', [StoryAttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('attachments/{attachment}', [StoryAttachmentController::class, 'destroy'])->name('attachments.destroy');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::post('orders/{order}/preview', [OrderController::class, 'uploadPreview'])->name('orders.upload-preview');
    Route::get('orders/{order}/photos/{index}', [OrderController::class, 'servePhoto'])->name('orders.photo')->where('index', '[0-9]+');
    Route::get('orders/{order}/production-prompt/regenerate', [OrderProductionPromptController::class, 'regenerate'])->name('orders.production-prompt.regenerate');
    Route::post('orders/{order}/production-prompt/override', [OrderProductionPromptController::class, 'saveOverride'])->name('orders.production-prompt.override');
    Route::delete('orders/{order}/production-prompt/override', [OrderProductionPromptController::class, 'resetOverride'])->name('orders.production-prompt.override-reset');
    Route::post('orders/{order}/production-prompt/snapshot', [OrderProductionPromptController::class, 'saveSnapshot'])->name('orders.production-prompt.snapshot');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/{customerKey}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
    Route::put('customers/{customerKey}', [CustomerController::class, 'update'])->name('customers.update');
    Route::get('customers/{customerKey}', [CustomerController::class, 'show'])->name('customers.show');

    // Content Management
    Route::delete('faqs/bulk-delete', [FaqController::class, 'bulkDestroy'])->name('faqs.bulk-destroy');
    Route::resource('faqs', FaqController::class)->except(['show']);
    Route::resource('testimonials', TestimonialController::class)->except(['show']);

    Route::get('messages', [ContactMessageController::class, 'index'])->name('messages.index');
    Route::get('messages/{message}', [ContactMessageController::class, 'show'])->name('messages.show');
    Route::delete('messages/{message}', [ContactMessageController::class, 'destroy'])->name('messages.destroy');

    // Settings
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('settings/story-production-prompt', [StoryProductionPromptTemplateController::class, 'edit'])->name('settings.story-production-prompt.edit');
    Route::put('settings/story-production-prompt', [StoryProductionPromptTemplateController::class, 'update'])->name('settings.story-production-prompt.update');
    Route::post('settings/story-production-prompt/preview', [StoryProductionPromptTemplateController::class, 'preview'])->name('settings.story-production-prompt.preview');
    Route::post('settings/story-production-prompt/reset', [StoryProductionPromptTemplateController::class, 'reset'])->name('settings.story-production-prompt.reset');
    Route::get('delivery-zones', [DeliveryZoneController::class, 'index'])->name('delivery-zones.index');
    Route::post('delivery-zones/countries', [DeliveryZoneController::class, 'storeCountry'])->name('delivery-zones.countries.store');
    Route::put('delivery-zones/countries/{country}', [DeliveryZoneController::class, 'updateCountry'])->name('delivery-zones.countries.update');
    Route::delete('delivery-zones/countries/{country}', [DeliveryZoneController::class, 'destroyCountry'])->name('delivery-zones.countries.destroy');
    Route::post('delivery-zones/governorates', [DeliveryZoneController::class, 'storeGovernorate'])->name('delivery-zones.governorates.store');
    Route::put('delivery-zones/governorates/{governorate}', [DeliveryZoneController::class, 'updateGovernorate'])->name('delivery-zones.governorates.update');
    Route::delete('delivery-zones/governorates/{governorate}', [DeliveryZoneController::class, 'destroyGovernorate'])->name('delivery-zones.governorates.destroy');

    // Admin Users Management
    Route::resource('users', UserController::class)->except(['show']);

    // Pricing Packages
    Route::resource('pricing', PricingPackageController::class)->except(['show']);

    // Admin Activity Logs
    Route::get('activity-logs', [AdminActivityLogController::class, 'index'])->name('activity-logs.index');
    Route::get('activity-logs/{activityLog}', [AdminActivityLogController::class, 'show'])->name('activity-logs.show');
});

require __DIR__.'/auth.php';
