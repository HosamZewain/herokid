<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Schema;

class PersistentMediaReferenceRegistry
{
    /**
     * Database-backed persistent media references that record their disk.
     * Multiple path columns may share the same disk column.
     *
     * @return array<int, array{table: string, model: string, disk: string, paths: array<int, string>}>
     */
    public function references(): array
    {
        return array_values(array_filter([
            $this->reference('order_attachments', 'OrderAttachment', 'disk', 'path'),
            $this->reference('temporary_photo_uploads', 'TemporaryPhotoUpload', 'disk', 'path'),
            $this->reference('temporary_photo_uploads', 'TemporaryPhotoUpload prepared', 'prepared_disk', 'prepared_path'),
            $this->reference('booklet_preview_versions', 'BookletPreviewVersion', 'disk', 'file_path'),
            $this->reference('child_identity_photos', 'ChildIdentityPhoto', 'disk', 'path'),
            $this->reference('child_identity_photos', 'ChildIdentityPhoto AI input', 'ai_input_disk', 'ai_input_path'),
            $this->reference('child_identity_generation_attempts', 'ChildIdentityGenerationAttempt', 'output_disk', ['output_storage_path', 'preview_storage_path']),
            $this->reference('child_identity_shares', 'ChildIdentityShare', 'card_disk', ['feed_card_path', 'story_card_path', 'og_card_path']),
            $this->reference('child_identity_attempt_photo', 'ChildIdentityAttemptPhoto', 'disk', 'path'),
            $this->reference('order_previews', 'OrderPreview', 'disk', 'file_path'),
            $this->reference('mobile_uploads', 'MobileUpload', 'disk', 'path'),
            $this->reference('child_profile_photos', 'ChildProfilePhoto', 'disk', 'path'),
            $this->reference('child_profiles', 'ChildProfile profile photo', 'profile_photo_disk', 'profile_photo_path'),
            $this->reference('order_payment_proofs', 'OrderPaymentProof', 'disk', 'file_path'),
            $this->reference('admin_dashboard_note_attachments', 'AdminDashboardNoteAttachment', 'disk', 'path'),
            $this->reference('admin_media_files', 'AdminMediaFile', 'disk', 'path'),
        ]));
    }

    /**
     * Persistent references whose historical schema has no disk column.
     * These are verification-only: the application resolves their disk through
     * media.private_disk or media.public_disk and there is no database value to migrate.
     *
     * @return array<int, array{table: string, model: string, scope: string, paths: array<int, string>, json_paths: array<int, string>}>
     */
    public function implicitReferences(): array
    {
        return array_values(array_filter([
            $this->implicit('orders', 'Order photos', 'private', [], ['uploaded_photos']),
            $this->implicit('story_attachments', 'StoryAttachment', 'private', ['path']),
            $this->implicit('expense_transactions', 'ExpenseTransaction', 'private', ['attachment_path']),
            $this->implicit('child_identity_generation_attempts', 'ChildIdentityGenerationAttempt share cards', 'private', [
                'share_feed_card_path', 'share_story_card_path', 'share_og_card_path',
            ]),
            $this->implicit('production_scenes', 'ProductionScene', 'private', [
                'base_scene_image_path', 'generated_child_image_path', 'approved_final_image_path',
            ]),
            $this->implicit('scene_generation_jobs', 'SceneGenerationJob', 'private', ['output_asset_path']),
            $this->implicit('production_project_assets', 'ProductionProjectAsset', 'private', ['file_path']),
            $this->implicit('production_print_layouts', 'ProductionPrintLayout', 'private', [
                'reader_pdf_path', 'print_pdf_path', 'manifest_path', 'proof_checklist_path',
            ]),
            $this->implicit('production_automation_proofs', 'ProductionAutomationProof', 'private', ['report_path']),
            $this->implicit('stories', 'Story catalog media', 'public', ['cover_image'], ['gallery_images']),
            $this->implicit('product_categories', 'ProductCategory media', 'public', ['cover_image']),
            $this->implicit('products', 'Product media', 'public', ['featured_image'], ['gallery_images']),
            $this->implicit('product_variants', 'ProductVariant media', 'public', ['image'], ['gallery_images']),
            $this->implicit('pricing_packages', 'PricingPackage media', 'public', ['image_path']),
        ]));
    }

    private function reference(string $table, string $model, string $disk, string|array $paths): ?array
    {
        $paths = (array) $paths;
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'id')
            || ! Schema::hasColumn($table, $disk)
            || collect($paths)->contains(fn (string $path): bool => ! Schema::hasColumn($table, $path))) {
            return null;
        }

        return compact('table', 'model', 'disk', 'paths');
    }

    private function implicit(string $table, string $model, string $scope, array $paths, array $jsonPaths = []): ?array
    {
        $columns = array_merge($paths, $jsonPaths);
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'id')
            || collect($columns)->contains(fn (string $path): bool => ! Schema::hasColumn($table, $path))) {
            return null;
        }

        return [
            'table' => $table,
            'model' => $model,
            'scope' => $scope,
            'paths' => $paths,
            'json_paths' => $jsonPaths,
        ];
    }
}
