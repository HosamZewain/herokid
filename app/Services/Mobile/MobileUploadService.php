<?php

namespace App\Services\Mobile;

use App\Models\ChildProfile;
use App\Models\ChildProfilePhoto;
use App\Models\MobileUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileUploadService
{
    public const CHUNK_SIZE = 1024 * 1024;

    public function initiate(User $user, array $attributes, ?ChildProfile $child): MobileUpload
    {
        return MobileUpload::create([
            'user_id' => $user->id,
            'child_profile_id' => $child?->id,
            'purpose' => $attributes['purpose'],
            'original_filename' => Str::limit(basename($attributes['filename']), 255, ''),
            'declared_mime_type' => strtolower($attributes['mime_type']),
            'expected_size' => $attributes['size'],
            'chunk_size' => self::CHUNK_SIZE,
            'chunks' => [],
            'status' => 'pending',
            'disk' => 'local',
            'expires_at' => now()->addHours(24),
        ]);
    }

    public function storeChunk(MobileUpload $upload, int $index, UploadedFile $chunk, ?string $checksum): MobileUpload
    {
        if (! $chunk->isValid()) {
            throw ValidationException::withMessages(['chunk' => 'The upload chunk could not be read.']);
        }

        $maximumIndex = max(0, (int) ceil($upload->expected_size / $upload->chunk_size) - 1);
        if ($index < 0 || $index > $maximumIndex) {
            throw ValidationException::withMessages(['chunk_index' => 'The chunk index is outside this upload.']);
        }

        $contents = $chunk->get();
        $expectedBytes = $index === $maximumIndex
            ? $upload->expected_size - ($index * $upload->chunk_size)
            : $upload->chunk_size;
        if (strlen($contents) !== $expectedBytes) {
            throw ValidationException::withMessages(['chunk' => 'The chunk size does not match the requested upload range.']);
        }

        $actualChecksum = hash('sha256', $contents);
        if ($checksum && ! hash_equals(strtolower($checksum), $actualChecksum)) {
            throw ValidationException::withMessages(['chunk_checksum' => 'The chunk checksum did not match.']);
        }

        $stored = DB::transaction(function () use ($upload, $index, $contents, $actualChecksum): MobileUpload {
            $locked = MobileUpload::query()->lockForUpdate()->findOrFail($upload->id);
            $this->assertMutable($locked);
            $chunks = $locked->chunks ?? [];
            $key = (string) $index;

            if (isset($chunks[$key])) {
                if (! hash_equals((string) $chunks[$key]['checksum'], $actualChecksum)) {
                    throw ValidationException::withMessages(['chunk' => 'A different chunk already exists at this position.']);
                }

                return $locked;
            }

            $path = $this->chunkPath($locked, $index);
            if (! Storage::disk($locked->disk)->put($path, $contents)) {
                throw ValidationException::withMessages(['chunk' => 'The chunk could not be stored.']);
            }

            $chunks[$key] = ['size' => strlen($contents), 'checksum' => $actualChecksum];
            ksort($chunks, SORT_NUMERIC);
            $locked->forceFill([
                'chunks' => $chunks,
                'received_size' => collect($chunks)->sum('size'),
                'status' => 'uploading',
            ])->save();

            return $locked->fresh();
        });

        if ($stored->received_size === $stored->expected_size
            && count($stored->chunks ?? []) === (int) ceil($stored->expected_size / $stored->chunk_size)) {
            return $this->finalize($stored);
        }

        return $stored;
    }

    public function attachToChild(MobileUpload $upload, ChildProfile $child): ChildProfilePhoto
    {
        if ($upload->status !== 'completed' || $upload->purpose !== 'child_reference' || ! $upload->path) {
            throw ValidationException::withMessages(['upload' => 'This upload is not a completed child reference photo.']);
        }

        if ($upload->child_profile_id !== $child->id || $upload->user_id !== $child->user_id) {
            abort(404);
        }

        return DB::transaction(function () use ($upload, $child): ChildProfilePhoto {
            $existing = ChildProfilePhoto::where('mobile_upload_id', $upload->id)->first();
            if ($existing) {
                return $existing;
            }

            $extension = $this->extensionForMime($upload->verified_mime_type);
            $destination = 'child-profiles/'.$child->uuid.'/photos/'.Str::uuid().'.'.$extension;
            if (! Storage::disk($upload->disk)->copy($upload->path, $destination)) {
                throw ValidationException::withMessages(['upload' => 'The completed photo could not be saved to the child profile.']);
            }

            try {
                return $child->photos()->create([
                    'mobile_upload_id' => $upload->id,
                    'disk' => $upload->disk,
                    'path' => $destination,
                    'original_filename' => $upload->original_filename,
                    'mime_type' => $upload->verified_mime_type,
                    'file_size' => $upload->expected_size,
                    'width' => $upload->width,
                    'height' => $upload->height,
                    'checksum' => $upload->checksum,
                    'sort_order' => ((int) $child->photos()->max('sort_order')) + 1,
                    'status' => 'active',
                    'reuse_consent_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                Storage::disk($upload->disk)->delete($destination);
                throw $exception;
            }
        });
    }

    public function delete(MobileUpload $upload): void
    {
        foreach (array_keys($upload->chunks ?? []) as $index) {
            Storage::disk($upload->disk)->delete($this->chunkPath($upload, (int) $index));
        }
        if ($upload->path) {
            Storage::disk($upload->disk)->delete($upload->path);
        }
        $upload->forceFill(['status' => 'deleted', 'path' => null, 'chunks' => []])->save();
    }

    private function finalize(MobileUpload $upload): MobileUpload
    {
        $stream = tmpfile();
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['upload' => 'The upload could not be assembled.']);
        }

        try {
            foreach (array_keys($upload->chunks ?? []) as $index) {
                $chunkStream = Storage::disk($upload->disk)->readStream($this->chunkPath($upload, (int) $index));
                if (! is_resource($chunkStream)) {
                    throw ValidationException::withMessages(['upload' => 'An uploaded chunk is missing.']);
                }
                stream_copy_to_stream($chunkStream, $stream);
                fclose($chunkStream);
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
            if (! is_string($contents) || strlen($contents) !== $upload->expected_size) {
                throw ValidationException::withMessages(['upload' => 'The assembled upload size is invalid.']);
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';
            $mime = strtolower($mime);
            $allowed = config('photo_uploads.allowed_mimes', []);
            $imageInfo = @getimagesizefromstring($contents);
            $isHeic = str_contains($mime, 'heic') || str_contains($mime, 'heif');
            if (! in_array($mime, $allowed, true) || (! is_array($imageInfo) && ! $isHeic)) {
                throw ValidationException::withMessages(['upload' => 'The completed file is not a supported child image.']);
            }

            $finalPath = 'mobile-uploads/'.$upload->uuid.'/complete.'.$this->extensionForMime($mime);
            if (! Storage::disk($upload->disk)->put($finalPath, $contents)) {
                throw ValidationException::withMessages(['upload' => 'The completed image could not be stored.']);
            }

            foreach (array_keys($upload->chunks ?? []) as $index) {
                Storage::disk($upload->disk)->delete($this->chunkPath($upload, (int) $index));
            }

            $upload->forceFill([
                'status' => 'completed',
                'path' => $finalPath,
                'verified_mime_type' => $mime,
                'width' => is_array($imageInfo) ? (int) $imageInfo[0] : null,
                'height' => is_array($imageInfo) ? (int) $imageInfo[1] : null,
                'checksum' => hash('sha256', $contents),
                'completed_at' => now(),
            ])->save();

            return $upload->fresh();
        } catch (ValidationException $exception) {
            $upload->forceFill(['status' => 'failed', 'safe_error_message' => collect($exception->errors())->flatten()->first()])->save();
            throw $exception;
        } finally {
            fclose($stream);
        }
    }

    private function assertMutable(MobileUpload $upload): void
    {
        if ($upload->expires_at->isPast() || ! in_array($upload->status, ['pending', 'uploading'], true)) {
            throw ValidationException::withMessages(['upload' => 'This upload can no longer accept chunks.']);
        }
    }

    private function chunkPath(MobileUpload $upload, int $index): string
    {
        return 'mobile-uploads/'.$upload->uuid.'/chunks/'.str_pad((string) $index, 6, '0', STR_PAD_LEFT).'.part';
    }

    private function extensionForMime(?string $mime): string
    {
        return match (strtolower((string) $mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic', 'image/heic-sequence' => 'heic',
            'image/heif', 'image/heif-sequence' => 'heif',
            default => 'jpg',
        };
    }
}
