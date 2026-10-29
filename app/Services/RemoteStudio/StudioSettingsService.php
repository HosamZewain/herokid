<?php

namespace App\Services\RemoteStudio;

use App\Models\AiModel;
use App\Models\StudioAsset;
use App\Models\StudioSettingsRevision;
use App\Models\User;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Storage\MediaStorage;
use App\Support\AdminActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudioSettingsService
{
    public function __construct(private readonly StudioSettingsSchema $schema) {}

    public function current(): ?StudioSettingsRevision
    {
        return StudioSettingsRevision::find(DB::table('studio_settings_state')->where('id', 1)->value('revision_id'));
    }

    public function publish(array $settings, int $baseRevision, User $user, string $reason): StudioSettingsRevision
    {
        $this->schema->validate($settings);
        if ($settings['openai_cover']['enabled']) {
            if ((int) config('queue.connections.database.retry_after') < 360
                || (config('queue.connections.database.connection') ?: config('database.default')) !== config('database.default')) {
                throw ValidationException::withMessages(['settings' => 'Studio covers require the database queue on the application connection and DB_QUEUE_RETRY_AFTER of at least 360 seconds.']);
            }
            $model = AiModel::where('code', $settings['openai_cover']['model'])->where('is_active', true)
                ->whereHas('provider', fn ($q) => $q->where('driver', 'openai')->where('is_active', true))->first();
            if (! $model || ! $model->supportsCapability('cover_generation')
                || ! app(AiProviderCredentialService::class)->hasCredential($model->provider)) {
                throw ValidationException::withMessages(['settings' => 'Enable an approved OpenAI cover model and configure its server-side credential first.']);
            }
        }

        return DB::transaction(function () use ($settings, $baseRevision, $user, $reason): StudioSettingsRevision {
            $state = DB::table('studio_settings_state')->where('id', 1)->lockForUpdate()->first();
            abort_unless((int) $state->revision_id === $baseRevision, 409, 'Settings changed; reload before publishing.');
            $assets = [];
            $ids = $this->schema->assetIds($settings);
            $available = StudioAsset::whereIn('id', $ids)->get()->keyBy('id');
            foreach ($ids as $id) {
                $asset = $available->get($id);
                if (! $asset || ! $this->intact($asset)) {
                    throw ValidationException::withMessages(['settings' => 'A referenced asset is missing or damaged. Upload it before publishing.']);
                }
                $assets[] = $this->metadata($asset);
            }
            $revision = StudioSettingsRevision::create(['settings' => $settings, 'assets' => $assets,
                'checksum' => $this->checksum($settings, $assets),
                'published_by' => $user->id, 'reason' => $reason, 'created_at' => now()]);
            foreach ($assets as $asset) {
                DB::table('studio_revision_assets')->insert(['revision_id' => $revision->id, 'asset_id' => $asset['id']]);
            }
            DB::table('studio_settings_state')->where('id', 1)->update(['revision_id' => $revision->id]);
            AdminActivityLogger::log('studio.settings.published', subject: $revision, properties: ['revision' => $revision->id, 'base_revision' => $baseRevision, 'reason' => $reason], admin: $user);

            return $revision;
        });
    }

    public function snapshot(StudioSettingsRevision $revision): array
    {
        // Fail closed if files were lost; clients must keep their complete last-known-good snapshot.
        $available = StudioAsset::whereIn('id', array_column($revision->assets, 'id'))->get()->keyBy('id');
        foreach ($revision->assets as $metadata) {
            $asset = $available->get($metadata['id']);
            abort_unless($asset && $this->intact($asset), 503, 'Published Studio asset unavailable. Keep the previous snapshot.');
        }

        return ['schema_version' => 1, 'revision' => (string) $revision->id, 'checksum' => $revision->checksum,
            'updated_at' => $revision->created_at->toIso8601String(), 'settings' => $revision->settings, 'assets' => $revision->assets];
    }

    public function upload(UploadedFile $file, User $user): StudioAsset
    {
        validator(['file' => $file], ['file' => 'required|image|mimes:png,jpg,jpeg,webp|max:'.config('remote_studio.asset_max_kb').'|dimensions:max_width=6000,max_height=6000'])->validate();
        $info = getimagesize($file->getRealPath());
        if ($info[0] * $info[1] > 25_000_000) {
            throw ValidationException::withMessages(['file' => 'Image exceeds 25 million pixels.']);
        }
        // Re-encode, removing EXIF/local filenames and never accepting SVG/HTML.
        $image = imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages(['file' => 'Unreadable image.']);
        }
        imagesavealpha($image, true);
        ob_start();
        try {
            imagepng($image, null, 6);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (! is_string($bytes) || strlen($bytes) > 25_000_000) {
            throw ValidationException::withMessages(['file' => 'Normalized image is too large.']);
        }
        $id = (string) Str::uuid();
        $path = 'settings/'.$id.'.png';
        Storage::disk('studio_private')->put($path, $bytes);
        try {
            $asset = StudioAsset::create(['id' => $id, 'path' => $path, 'mime' => 'image/png', 'bytes' => strlen($bytes), 'width' => $info[0], 'height' => $info[1], 'checksum' => hash('sha256', $bytes), 'uploaded_by' => $user->id, 'created_at' => now()]);
        } catch (\Throwable $e) {
            Storage::disk('studio_private')->delete($path);
            throw $e;
        }
        // Existing audit subjects use integer IDs; keep UUID asset IDs in properties.
        AdminActivityLogger::log('studio.asset.uploaded', properties: ['asset_id' => $id, 'checksum' => $asset->checksum], admin: $user);

        return $asset;
    }

    public function metadata(StudioAsset $asset): array
    {
        return ['id' => $asset->id, 'revision' => $asset->checksum, 'mime_type' => $asset->mime, 'byte_size' => (int) $asset->bytes,
            'width' => (int) $asset->width, 'height' => (int) $asset->height, 'sha256' => $asset->checksum,
            'download_url' => '/api/studio/settings/assets/'.$asset->id];
    }

    public function intact(StudioAsset $asset): bool
    {
        $disk = Storage::disk('studio_private');

        return $disk->exists($asset->path) && $disk->size($asset->path) === (int) $asset->bytes
            && hash_equals($asset->checksum, app(MediaStorage::class)->checksum('studio_private', $asset->path));
    }

    public function publishedAsset(string $id): StudioAsset
    {
        $asset = StudioAsset::findOrFail($id);
        // Assets in any published immutable revision remain available for staged downloads/rollback.
        $published = DB::table('studio_revision_assets')->where('asset_id', $id)->exists();
        abort_unless($published, 404);
        abort_unless($this->intact($asset), 503, 'Published asset unavailable.');

        return $asset;
    }

    public function checksum(array $settings, array $assets): string
    {
        return hash('sha256', json_encode($this->canonical([$settings, $assets]), JSON_THROW_ON_ERROR));
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->canonical($entry);
            }
        }

        return $value;
    }
}
