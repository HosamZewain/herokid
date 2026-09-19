<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminMediaFile;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminMediaLibraryController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', 'in:all,image,pdf,text'],
        ]);

        $query = AdminMediaFile::query()->latest('id');
        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('original_name', 'like', '%'.$search.'%')
                    ->orWhere('uploaded_by_name', 'like', '%'.$search.'%');
            });
        }

        $type = $validated['type'] ?? 'all';
        match ($type) {
            'image' => $query->where('mime_type', 'like', 'image/%'),
            'pdf' => $query->where('mime_type', 'application/pdf'),
            'text' => $query->where('mime_type', 'text/plain'),
            default => null,
        };

        $files = $query->paginate(24)->withQueryString();
        $stats = [
            'count' => AdminMediaFile::query()->count(),
            'size' => (int) AdminMediaFile::query()->sum('size'),
            'images' => AdminMediaFile::query()->where('mime_type', 'like', 'image/%')->count(),
            'documents' => AdminMediaFile::query()->whereIn('mime_type', ['application/pdf', 'text/plain'])->count(),
        ];

        return view('admin.media-library.index', compact('files', 'stats', 'search', 'type'));
    }
}
