<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FaqItem;
use Illuminate\Http\Request;

class FaqController extends Controller
{
    public function index()
    {
        $faqs = FaqItem::orderBy('sort_order')->get();
        return view('admin.faqs.index', compact('faqs'));
    }

    public function create()
    {
        return view('admin.faqs.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'question'   => 'required|string|max:500',
            'answer'     => 'required|string',
            'sort_order' => 'required|integer|min:0',
        ]);

        $validated['active'] = $request->has('active');

        FaqItem::create($validated);

        return redirect()->route('admin.faqs.index')->with('success', 'تم إضافة السؤال بنجاح.');
    }

    public function edit(FaqItem $faq)
    {
        return view('admin.faqs.edit', compact('faq'));
    }

    public function update(Request $request, FaqItem $faq)
    {
        $validated = $request->validate([
            'question'   => 'required|string|max:500',
            'answer'     => 'required|string',
            'sort_order' => 'required|integer|min:0',
        ]);

        $validated['active'] = $request->has('active');

        $faq->update($validated);

        return redirect()->route('admin.faqs.index')->with('success', 'تم تحديث السؤال بنجاح.');
    }

    public function destroy(FaqItem $faq)
    {
        $faq->delete();
        return redirect()->route('admin.faqs.index')->with('success', 'تم حذف السؤال بنجاح.');
    }
}
