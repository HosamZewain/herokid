<?php

namespace App\Services\StoryLibrary;

use App\Models\LibraryStory;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;

class LibraryStoryManager
{
    public function save(Request $request, ?LibraryStory $story = null): LibraryStory
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'],
            'slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('library_stories', 'slug')->ignore($story?->id)],
            'category' => ['nullable', 'string', 'max:100'], 'language' => ['required', Rule::in(['ar', 'en'])],
            'reading_direction' => ['required', Rule::in(['rtl', 'ltr'])],
            'age_min' => ['nullable', 'required_with:age_max', 'integer', 'min:0', 'max:18'], 'age_max' => ['nullable', 'required_with:age_min', 'integer', 'min:0', 'max:18', 'gte:age_min'],
            'status' => ['required', Rule::in(['draft', 'published'])], 'sort_order' => ['required', 'integer', 'min:-10000', 'max:10000'],
            'publication_confirmed' => ['required_if:status,published', 'accepted_if:status,published'],
            'pdf_file' => [$story ? 'nullable' : 'required', 'file', 'mimes:pdf', 'max:'.(config('story_library.max_pdf_mb') * 1024)],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=5000,max_height=5000'],
        ]);
        $inspection = null;
        if ($file = $request->file('pdf_file')) {
            try {
                $path = $file->getRealPath();
                $handle = fopen($path, 'rb');
                $signature = fread($handle, 5);
                fclose($handle);
                if ($signature !== '%PDF-') {
                    throw new \RuntimeException('PDF signature');
                }
                $tempDir = storage_path('framework/cache/mpdf');
                if (! is_dir($tempDir)) {
                    mkdir($tempDir, 0775, true);
                }
                $parser = new Mpdf(['tempDir' => $tempDir]);
                $pages = $parser->setSourceFile($path);
                if ($pages < 1 || $pages > config('story_library.max_pages')) {
                    throw new \RuntimeException('PDF pages');
                }
                $inspection = ['page_count' => $pages, 'file_size' => $file->getSize(), 'checksum' => hash_file('sha256', $path)];
            } catch (\Throwable) {
                throw ValidationException::withMessages(['pdf_file' => 'ملف PDF غير صالح أو مشفر أو يتجاوز '.config('story_library.max_pages').' صفحة.']);
            }
        }
        $disk = $story?->disk ?? config('story_library.disk');
        $uuid = $story?->uuid ?? (string) Str::uuid();
        $createdFiles = [];
        try {
            if ($file) {
                $createdFiles['pdf_path'] = $file->storeAs('story-library/'.$uuid, Str::uuid().'.pdf', $disk);
                if (! $createdFiles['pdf_path']) {
                    throw new \RuntimeException('PDF storage failed');
                }
            }
            if ($cover = $request->file('cover')) {
                $createdFiles['cover_path'] = $cover->storeAs('story-library/'.$uuid, Str::uuid().'.'.$cover->extension(), $disk);
                if (! $createdFiles['cover_path']) {
                    throw new \RuntimeException('Cover storage failed');
                }
            }

            return DB::transaction(function () use ($story, $data, $request, $disk, $uuid, $inspection, $createdFiles, $cover) {
                $model = $story ? LibraryStory::lockForUpdate()->findOrFail($story->id) : new LibraryStory;
                $model->fill(collect($data)->only(['title', 'description', 'category', 'language', 'reading_direction', 'age_min', 'age_max', 'status', 'sort_order'])->all());
                $model->slug = $data['slug'] ?? $model->slug ?? 'story-'.Str::lower(Str::random(12));
                $model->uuid = $uuid;
                $model->disk = $disk;
                $model->updated_by = $request->user()->id;
                if ($inspection) {
                    $model->fill([...$inspection, ...$createdFiles]);
                } elseif (isset($createdFiles['cover_path'])) {
                    $model->cover_path = $createdFiles['cover_path'];
                }
                if ($cover) {
                    $model->cover_mime = $cover->getMimeType();
                }
                if ($model->status === 'published') {
                    $model->published_at ??= now();
                }
                $model->save();
                AdminActivityLogger::log('story_library.saved', 'حفظ قصة في المكتبة العامة.', $model, properties: ['status' => $model->status, 'pdf_replaced' => (bool) $inspection, 'page_count' => $model->page_count]);

                return $model;
            });
        } catch (\Throwable $exception) {
            foreach (array_filter($createdFiles) as $path) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }
    }
}
