<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\StoryAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoryAttachmentController extends Controller
{
    /** Upload one or more attachments for a story */
    public function store(Request $request, Story $story)
    {
        $request->validate([
            'attachments'   => 'required|array|min:1',
            'attachments.*' => 'file|max:20480', // 20 MB per file
        ]);

        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store("story-attachments/{$story->id}", 'local');

            $story->attachments()->create([
                'original_name' => $file->getClientOriginalName(),
                'path'          => $path,
                'mime_type'     => $file->getMimeType(),
                'size'          => $file->getSize(),
            ]);
        }

        return back()->with('success', 'تم رفع المرفقات بنجاح!');
    }

    /** Download a private attachment (admin only) */
    public function download(StoryAttachment $attachment)
    {
        if (!Storage::disk('local')->exists($attachment->path)) {
            abort(404);
        }

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    /** Delete a single attachment */
    public function destroy(StoryAttachment $attachment)
    {
        $attachment->delete(); // physical file deleted via model boot

        return back()->with('success', 'تم حذف المرفق بنجاح!');
    }
}
