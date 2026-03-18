<?php

use App\Http\Controllers\ProfileController;
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
    $fullPath = storage_path('app/public/' . ltrim($path, '/'));
    if (!file_exists($fullPath) || !is_file($fullPath)) {
        abort(404);
    }
    return response()->file($fullPath);
})->where('path', '.*')->name('storage.serve');

// Homepage
Route::get('/', function () {
    $featuredStories = \App\Models\Story::where('active', true)->with('categories')->latest()->take(8)->get();
    $faqs            = \App\Models\FaqItem::where('active', true)->orderBy('sort_order')->take(5)->get();
    $testimonials    = \App\Models\Testimonial::where('active', true)->orderBy('sort_order')->get();
    $packages        = \App\Models\PricingPackage::active()->ordered()->get();
    return view('welcome', compact('featuredStories', 'faqs', 'testimonials', 'packages'));
})->name('home');

// Public Story Routes
Route::get('/stories', [\App\Http\Controllers\Front\StoryController::class, 'index'])->name('stories.index');
Route::get('/stories/{slug}', [\App\Http\Controllers\Front\StoryController::class, 'show'])->name('stories.show');

// Checkout routes
Route::post('/checkout/{story:slug}', [\App\Http\Controllers\Front\CheckoutController::class, 'store'])->name('checkout.store');
Route::get('/checkout/success/{order}', [\App\Http\Controllers\Front\CheckoutController::class, 'success'])->name('checkout.success');

// Order Tracking
Route::get('/track-order', [\App\Http\Controllers\Front\TrackOrderController::class, 'index'])->name('track.index');
Route::post('/track-order', [\App\Http\Controllers\Front\TrackOrderController::class, 'track'])->name('track.search');

// Preview Approval (customer)
Route::post('/orders/{order}/approve-preview', function (\App\Models\Order $order) {
    if ($order->status === 'preview_uploaded') {
        $order->update(['status' => 'approved_for_print']);
        $order->statusLogs()->create([
            'status' => 'approved_for_print',
            'notes'  => 'تم الموافقة على التصميم النهائي من قِبل العميل.',
        ]);
        return back()->with('success', 'تمت الموافقة على التصميم! سنبدأ الطباعة قريباً.');
    }
    return back()->with('error', 'لا يوجد تصميم قيد المراجعة حالياً.');
})->middleware('auth')->name('orders.approve-preview');

// Static Pages
// ── Dynamic Sitemap ──────────────────────────────────────────────────────────
Route::get('/sitemap.xml', function () {
    $stories = \App\Models\Story::where('active', true)->select('slug', 'updated_at')->get();

    $staticPages = [
        ['url' => route('home'),          'lastmod' => now()->toDateString(), 'freq' => 'daily',   'priority' => '1.0'],
        ['url' => route('stories.index'), 'lastmod' => now()->toDateString(), 'freq' => 'daily',   'priority' => '0.9'],
        ['url' => route('how-it-works'),  'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.7'],
        ['url' => route('pricing'),       'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.7'],
        ['url' => route('faq'),           'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.6'],
        ['url' => route('contact'),       'lastmod' => now()->toDateString(), 'freq' => 'monthly', 'priority' => '0.5'],
    ];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($staticPages as $page) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>{$page['url']}</loc>\n";
        $xml .= "    <lastmod>{$page['lastmod']}</lastmod>\n";
        $xml .= "    <changefreq>{$page['freq']}</changefreq>\n";
        $xml .= "    <priority>{$page['priority']}</priority>\n";
        $xml .= "  </url>\n";
    }

    foreach ($stories as $story) {
        $url     = route('stories.show', $story->slug);
        $lastmod = $story->updated_at ? $story->updated_at->toDateString() : now()->toDateString();
        $xml .= "  <url>\n";
        $xml .= "    <loc>{$url}</loc>\n";
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "    <changefreq>weekly</changefreq>\n";
        $xml .= "    <priority>0.8</priority>\n";
        $xml .= "  </url>\n";
    }

    $xml .= '</urlset>';

    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap');

Route::get('/faq', [\App\Http\Controllers\Front\PageController::class, 'faq'])->name('faq');
Route::get('/contact', [\App\Http\Controllers\Front\PageController::class, 'contact'])->name('contact');
Route::post('/contact', [\App\Http\Controllers\Front\PageController::class, 'submitContact'])->name('contact.submit');
Route::get('/privacy', [\App\Http\Controllers\Front\PageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [\App\Http\Controllers\Front\PageController::class, 'terms'])->name('terms');
Route::get('/pricing', [\App\Http\Controllers\Front\PageController::class, 'pricing'])->name('pricing');
Route::get('/how-it-works', [\App\Http\Controllers\Front\PageController::class, 'howItWorks'])->name('how-it-works');

// Customer Dashboard
Route::get('/dashboard', function () {
    $orders = \App\Models\Order::where('user_id', auth()->id())
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
Route::middleware(['auth', 'is_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard.index');

    Route::resource('stories', \App\Http\Controllers\Admin\StoryController::class);
    Route::resource('categories', \App\Http\Controllers\Admin\CategoryController::class)->only(['index', 'store', 'destroy']);

    // Story Attachments (private — admin only)
    Route::post('stories/{story}/attachments', [\App\Http\Controllers\Admin\StoryAttachmentController::class, 'store'])->name('stories.attachments.store');
    Route::get('attachments/{attachment}/download', [\App\Http\Controllers\Admin\StoryAttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('attachments/{attachment}', [\App\Http\Controllers\Admin\StoryAttachmentController::class, 'destroy'])->name('attachments.destroy');

    Route::get('orders', [\App\Http\Controllers\Admin\OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [\App\Http\Controllers\Admin\OrderController::class, 'show'])->name('orders.show');
    Route::patch('orders/{order}', [\App\Http\Controllers\Admin\OrderController::class, 'update'])->name('orders.update');
    Route::post('orders/{order}/preview', [\App\Http\Controllers\Admin\OrderController::class, 'uploadPreview'])->name('orders.upload-preview');
    Route::get('orders/{order}/photos/{index}', [\App\Http\Controllers\Admin\OrderController::class, 'servePhoto'])->name('orders.photo')->where('index', '[0-9]+');

    // Content Management
    Route::resource('faqs', \App\Http\Controllers\Admin\FaqController::class)->except(['show']);
    Route::resource('testimonials', \App\Http\Controllers\Admin\TestimonialController::class)->except(['show']);

    Route::get('messages', [\App\Http\Controllers\Admin\ContactMessageController::class, 'index'])->name('messages.index');
    Route::get('messages/{message}', [\App\Http\Controllers\Admin\ContactMessageController::class, 'show'])->name('messages.show');
    Route::delete('messages/{message}', [\App\Http\Controllers\Admin\ContactMessageController::class, 'destroy'])->name('messages.destroy');

    // Settings
    Route::get('settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update'])->name('settings.update');

    // Admin Users Management
    Route::resource('users', \App\Http\Controllers\Admin\UserController::class)->except(['show']);

    // Pricing Packages
    Route::resource('pricing', \App\Http\Controllers\Admin\PricingPackageController::class)->except(['show']);
});

require __DIR__.'/auth.php';
