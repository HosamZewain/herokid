<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminMediaUploadSession;
use App\Services\MediaLibrary\AdminMediaLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMediaUploadController extends Controller
{
    public function __construct(private readonly AdminMediaLibraryService $mediaLibrary) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.AdminMediaLibraryService::MAX_FILE_SIZE],
            'mime' => ['nullable', 'string', 'max:150'],
        ]);

        $upload = $this->mediaLibrary->start(
            $request->user(),
            $validated['file_name'],
            (int) $validated['size'],
            $validated['mime'] ?? null,
            $validated['title'] ?? null,
        );

        return response()->json([
            'data' => [
                'upload_id' => $upload->public_id,
                'chunk_size' => $upload->chunk_size,
                'total_chunks' => $upload->total_chunks,
                'chunk_url' => route('admin.media-library.uploads.chunks.store', $upload),
                'complete_url' => route('admin.media-library.uploads.complete', $upload),
                'cancel_url' => route('admin.media-library.uploads.destroy', $upload),
            ],
        ], 201);
    }

    public function chunk(Request $request, AdminMediaUploadSession $upload): JsonResponse
    {
        $validated = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:2048'],
        ]);

        $upload = $this->mediaLibrary->appendChunk(
            $upload,
            $request->user(),
            (int) $validated['index'],
            $request->file('chunk'),
        );

        return response()->json([
            'data' => [
                'next_chunk_index' => $upload->next_chunk_index,
                'bytes_received' => $upload->bytes_received,
                'total_size' => $upload->total_size,
            ],
        ]);
    }

    public function complete(Request $request, AdminMediaUploadSession $upload): JsonResponse
    {
        $media = $this->mediaLibrary->complete($upload, $request->user());

        return response()->json([
            'data' => [
                'id' => $media->public_id,
                'name' => $media->original_name,
                'public_url' => $media->publicUrl(),
            ],
        ], 201);
    }

    public function destroy(Request $request, AdminMediaUploadSession $upload): JsonResponse
    {
        $this->mediaLibrary->cancel($upload, $request->user());

        return response()->json([], 204);
    }
}
