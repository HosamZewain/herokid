<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function faq()
    {
        $faqs = \App\Models\FaqItem::where('active', true)->orderBy('sort_order')->get();
        return view('front.pages.faq', compact('faqs'));
    }

    public function contact()
    {
        return view('front.pages.contact');
    }

    public function submitContact(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'phone'   => 'nullable|string|max:30',
            'message' => 'required|string|max:2000',
        ]);

        \App\Models\ContactMessage::create($validated);

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
        $packages = \App\Models\PricingPackage::active()->ordered()->get();
        return view('front.pages.pricing', compact('packages'));
    }

    public function howItWorks()
    {
        return view('front.pages.how-it-works');
    }
}
