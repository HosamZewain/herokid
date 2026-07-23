<?php

namespace App\Services\ChildIdentity;

use App\Models\ChildIdentityRequest;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChildIdentityDeletionService
{
    public function softDelete(
        ChildIdentityRequest $identity,
        string $reason,
        User $admin,
        Request $request,
        ChildIdentityEventLogger $events,
    ): void {
        DB::transaction(function () use ($identity, $reason, $admin, $request, $events): void {
            $locked = ChildIdentityRequest::query()->lockForUpdate()->findOrFail($identity->id);
            $events->record(
                $locked,
                'request.deleted',
                'تم نقل طلب الهوية إلى سلة المحذوفات.',
                ['reason' => $reason],
                actor: $admin,
                actorType: 'admin',
                source: 'admin',
                fromStatus: $locked->status,
                toStatus: $locked->status,
            );
            $locked->delete();

            AdminActivityLogger::log(
                'child_identity.deleted',
                'نقل طلب هوية طفل إلى سلة المحذوفات.',
                $locked,
                ['uuid' => $locked->uuid, 'reason' => $reason],
                $admin,
                $request,
            );
        });
    }

    public function restore(
        ChildIdentityRequest $identity,
        User $admin,
        Request $request,
        ChildIdentityEventLogger $events,
    ): void {
        DB::transaction(function () use ($identity, $admin, $request, $events): void {
            $identity->restore();
            $events->record(
                $identity,
                'request.restored',
                'تمت استعادة طلب الهوية من سلة المحذوفات.',
                actor: $admin,
                actorType: 'admin',
                source: 'admin',
            );
            AdminActivityLogger::log(
                'child_identity.restored',
                'استعادة طلب هوية طفل.',
                $identity,
                ['uuid' => $identity->uuid],
                $admin,
                $request,
            );
        });
    }

    public function forceDelete(
        ChildIdentityRequest $identity,
        string $reason,
        User $admin,
        Request $request,
    ): void {
        $identity->load(['photos', 'attempts']);
        $media = $identity->photos
            ->map(fn ($photo) => [
                'disk' => $photo->disk,
                'path' => $photo->path,
                'checksum' => $photo->checksum,
                'kind' => 'original',
            ])
            ->merge($identity->attempts->flatMap(fn ($attempt) => collect([
                $attempt->output_storage_path ? [
                    'disk' => $attempt->output_disk ?: 'local',
                    'path' => $attempt->output_storage_path,
                    'checksum' => $attempt->output_checksum,
                    'kind' => 'output',
                ] : null,
                $attempt->preview_storage_path ? [
                    'disk' => $attempt->output_disk ?: 'local',
                    'path' => $attempt->preview_storage_path,
                    'checksum' => null,
                    'kind' => 'preview',
                ] : null,
            ])->filter()))
            ->values();
        $manifest = [
            'identity_id' => $identity->id,
            'uuid' => $identity->uuid,
            'reason' => $reason,
            'linked_order_ids' => $identity->orders()->withTrashed()->pluck('id')->all(),
            'files' => $media->map(fn (array $file) => [
                'kind' => $file['kind'],
                'checksum' => $file['checksum'],
            ])->all(),
        ];
        $attemptIds = $identity->attempts->pluck('id');

        DB::transaction(function () use ($identity, $manifest, $attemptIds, $admin, $request): void {
            AdminActivityLogger::log(
                'child_identity.force_deleted',
                'حذف نهائي مصرح به لطلب هوية طفل ووسائطه.',
                null,
                $manifest,
                $admin,
                $request,
            );
            DB::table('child_identity_attempt_photos')
                ->whereIn('child_identity_generation_attempt_id', $attemptIds)
                ->delete();
            $identity->forceDelete();
        });

        $media->each(function (array $file): void {
            if (! str_contains($file['path'], '..')) {
                Storage::disk($file['disk'])->delete($file['path']);
            }
        });
    }
}
