<?php

namespace App\Services\Mobile;

use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MobileChildIdentityDeletionService
{
    public function delete(ChildIdentityRequest $identity, User $user): void
    {
        abort_unless($identity->user_id === $user->id, 404);
        abort_if($identity->orders()->withTrashed()->exists(), 422, 'An identity linked to an order must be removed through the privacy request workflow.');
        $identity->load(['photos', 'attempts', 'share']);
        $files = $identity->photos->flatMap(fn ($photo) => [
            ['disk' => $photo->disk, 'path' => $photo->path],
            $photo->ai_input_path ? ['disk' => $photo->ai_input_disk ?: $photo->disk, 'path' => $photo->ai_input_path] : null,
        ])->merge($identity->attempts->flatMap(fn ($attempt) => [
            $attempt->output_storage_path ? ['disk' => $attempt->output_disk ?: 'local', 'path' => $attempt->output_storage_path] : null,
            $attempt->preview_storage_path ? ['disk' => $attempt->output_disk ?: 'local', 'path' => $attempt->preview_storage_path] : null,
            $attempt->share_feed_card_path ? ['disk' => 'local', 'path' => $attempt->share_feed_card_path] : null,
            $attempt->share_story_card_path ? ['disk' => 'local', 'path' => $attempt->share_story_card_path] : null,
            $attempt->share_og_card_path ? ['disk' => 'local', 'path' => $attempt->share_og_card_path] : null,
        ]))->merge($identity->share ? collect(ChildIdentityShare::VARIANTS)->map(fn (string $variant) => $identity->share->cardPath($variant) ? [
            'disk' => $identity->share->card_disk,
            'path' => $identity->share->cardPath($variant),
        ] : null) : collect())->filter()->unique(fn (array $file) => $file['disk'].'|'.$file['path'])->values();

        DB::transaction(function () use ($identity, $user): void {
            DB::table('consent_records')->insert([
                'user_id' => $user->id,
                'child_profile_id' => $identity->child_profile_id,
                'consent_type' => 'child_identity_deleted',
                'document_version' => 'privacy-v1-2026-08',
                'granted' => false,
                'recorded_at' => now(),
                'withdrawn_at' => now(),
                'source' => 'mobile',
                'metadata' => json_encode(['identity_uuid' => $identity->uuid], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $attemptIds = $identity->attempts()->pluck('id');
            $identity->forceFill(['approved_attempt_id' => null, 'status' => 'cancelled'])->saveQuietly();
            DB::table('child_identity_attempt_photos')->whereIn('child_identity_generation_attempt_id', $attemptIds)->delete();
            $identity->forceDelete();
        });

        $files->each(function (array $file): void {
            if (! str_contains($file['path'], '..')) {
                Storage::disk($file['disk'])->delete($file['path']);
            }
        });
    }
}
