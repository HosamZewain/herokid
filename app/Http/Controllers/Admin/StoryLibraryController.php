<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LibraryStory;
use App\Services\Storage\PersistentMediaResponse;
use App\Services\StoryLibrary\LibraryStoryManager;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoryLibraryController extends Controller
{
    public function index()
    {
        return view('admin.story-library.index', ['stories' => LibraryStory::orderBy('sort_order')->latest('id')->paginate(25)]);
    }

    public function create()
    {
        return view('admin.story-library.form', ['story' => new LibraryStory(['language' => 'ar', 'reading_direction' => 'rtl', 'status' => 'draft', 'sort_order' => 0])]);
    }

    public function store(Request $request, LibraryStoryManager $manager)
    {
        $story = $manager->save($request);

        return redirect()->route('admin.story-library.edit', $story)->with('success', 'تم حفظ القصة.');
    }

    public function edit(LibraryStory $story)
    {
        return view('admin.story-library.form', compact('story'));
    }

    public function update(Request $request, LibraryStory $story, LibraryStoryManager $manager)
    {
        $manager->save($request, $story);

        return back()->with('success', 'تم تحديث القصة.');
    }

    public function document(LibraryStory $story, PersistentMediaResponse $media)
    {
        abort_unless(Storage::disk($story->disk)->exists($story->pdf_path), 404);

        return $media->inline($story->disk, $story->pdf_path, 'library-story.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store'])->setPrivate();
    }

    public function destroy(Request $request, LibraryStory $story)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500'], 'confirmed' => ['accepted']]);
        $story->delete();
        AdminActivityLogger::log('story_library.deleted', 'إخفاء قصة من المكتبة العامة.', $story, properties: ['reason' => $data['reason']]);

        return redirect()->route('admin.story-library.index')->with('success', 'تم حذف القصة من العرض. الملفات محفوظة ولا تُمسح نهائيًا.');
    }
}
