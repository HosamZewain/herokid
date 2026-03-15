<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use Illuminate\Http\Request;

class TestimonialController extends Controller
{
    public function index()
    {
        $testimonials = Testimonial::orderBy('sort_order')->get();
        return view('admin.testimonials.index', compact('testimonials'));
    }

    public function create()
    {
        return view('admin.testimonials.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reviewer_name'     => 'required|string|max:100',
            'reviewer_location' => 'nullable|string|max:100',
            'reviewer_avatar'   => 'nullable|string|max:500',
            'review_text'       => 'required|string|max:1000',
            'rating'            => 'required|integer|min:1|max:5',
            'sort_order'        => 'required|integer|min:0',
        ]);

        $validated['active'] = $request->has('active');

        Testimonial::create($validated);

        return redirect()->route('admin.testimonials.index')->with('success', 'تم إضافة الرأي بنجاح.');
    }

    public function edit(Testimonial $testimonial)
    {
        return view('admin.testimonials.edit', compact('testimonial'));
    }

    public function update(Request $request, Testimonial $testimonial)
    {
        $validated = $request->validate([
            'reviewer_name'     => 'required|string|max:100',
            'reviewer_location' => 'nullable|string|max:100',
            'reviewer_avatar'   => 'nullable|string|max:500',
            'review_text'       => 'required|string|max:1000',
            'rating'            => 'required|integer|min:1|max:5',
            'sort_order'        => 'required|integer|min:0',
        ]);

        $validated['active'] = $request->has('active');

        $testimonial->update($validated);

        return redirect()->route('admin.testimonials.index')->with('success', 'تم تحديث الرأي بنجاح.');
    }

    public function destroy(Testimonial $testimonial)
    {
        $testimonial->delete();
        return redirect()->route('admin.testimonials.index')->with('success', 'تم حذف الرأي.');
    }
}
