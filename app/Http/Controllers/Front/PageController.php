<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\FaqItem;
use App\Models\PricingPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class PageController extends Controller
{
    public function about()
    {
        return view('front.pages.about');
    }

    public function faq()
    {
        $faqs = FaqItem::where('active', true)->orderBy('sort_order')->get();

        return view('front.pages.faq', compact('faqs'));
    }

    public function contact()
    {
        return view('front.pages.contact', ['formToken' => now()->timestamp]);
    }

    public function submitContact(Request $request)
    {
        // ── 1. Honeypot — bots fill this hidden field, humans never see it
        if ($request->filled('website')) {
            return back()->with('success', 'تم استلام رسالتك بنجاح، وسنتواصل معك قريباً!');
        }

        // ── 2. Timing check — reject if submitted in under 3 seconds
        $loadedAt = (int) $request->input('_loaded_at', 0);
        if ($loadedAt > 0 && (now()->timestamp - $loadedAt) < 3) {
            return back()->with('success', 'تم استلام رسالتك بنجاح، وسنتواصل معك قريباً!');
        }

        // ── 3. Rate limiting — max 3 submissions per IP per 10 minutes
        $key = 'contact:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors(['message' => 'لقد أرسلت عدة رسائل مؤخراً. يرجى الانتظار '.ceil($seconds / 60).' دقيقة قبل المحاولة مجدداً.']);
        }
        RateLimiter::hit($key, 600); // 10 minutes decay

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:30',
            'message' => 'required|string|max:2000',
        ]);

        ContactMessage::create($validated);

        return back()->with('success', 'تم استلام رسالتك بنجاح، وسنتواصل معك قريباً!');
    }

    public function privacy()
    {
        return view('front.pages.privacy');
    }

    public function terms()
    {
        return view('front.pages.terms');
    }

    public function pricing()
    {
        $packages = PricingPackage::active()->purchasable()->where('show_in_store', true)->with(['items.product', 'items.variant', 'eligibleStories'])->ordered()->get()->filter->availableForPurchase();

        return view('front.pages.pricing', compact('packages'));
    }

    public function howItWorks()
    {
        return view('front.pages.how-it-works');
    }
}
