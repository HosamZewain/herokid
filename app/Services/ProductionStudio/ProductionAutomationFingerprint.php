<?php

namespace App\Services\ProductionStudio;

use App\Models\ProductionAutomationRun;
use App\Models\ProductionProject;
use App\Models\ProductionProjectAsset;
use App\Models\ProductionScene;
use Illuminate\Support\Facades\Storage;

class ProductionAutomationFingerprint
{
    public function forArtifact(ProductionAutomationRun $run, string $artifactType, array $inputs = [], ?ProductionScene $scene = null): string
    {
        $run->loadMissing(['project.order.story', 'project.characterProfile', 'project.storyVersions']);
        $project = $run->project;
        $storyVersion = $artifactType === 'story_preparation'
            ? null
            : $project->storyVersions->sortByDesc('version_number')->first();

        return $this->hash([
            'fingerprint_version' => config('production_studio.automation.fingerprint_version', 'automation-fingerprint-v1'),
            'artifact_type' => $artifactType,
            'project_id' => $project->id,
            'order_id' => $project->order_id,
            'story_template_id' => $project->order?->story_id,
            'story_template_hash' => $this->storyTemplateHash($project),
            'production_story_version_id' => $storyVersion?->id,
            'production_story_version_number' => $storyVersion?->version_number,
            'production_story_hash' => $storyVersion ? $this->storyVersionHash($storyVersion) : null,
            'scene_id' => $scene?->id,
            'scene_number' => $scene?->scene_number,
            'scene_hash' => $scene ? $this->sceneHash($scene) : null,
            'child_photos' => $this->childPhotoFingerprints($project),
            'character_profile_id' => $project->characterProfile?->id,
            'character_profile_hash' => $this->characterProfileHash($project),
            'prompt_template_version' => config('production_studio.automation.prompt_template_version', 'production-prompt-v1'),
            'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
            'layout_template_version' => config('production_studio.automation.layout_template_version', 'layout-print-v1'),
            'inputs' => $inputs,
        ]);
    }

    public function assetCompatible(ProductionProjectAsset $asset, string $fingerprint): bool
    {
        return hash_equals((string) $asset->output_fingerprint, $fingerprint)
            || hash_equals((string) data_get($asset->metadata_json, 'automation.output_fingerprint'), $fingerprint);
    }

    public function hash(array $payload): string
    {
        return 'sha256:'.hash('sha256', json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function childPhotoFingerprints(ProductionProject $project): array
    {
        return collect($project->order?->uploaded_photos ?? [])
            ->filter(fn ($path): bool => is_string($path) && ! str_contains($path, '..'))
            ->values()
            ->map(fn (string $path, int $index): array => [
                'index' => $index,
                'path_hash' => hash('sha256', $path),
                'content_hash' => $this->fileHash($path),
            ])
            ->all();
    }

    private function fileHash(string $path): ?string
    {
        foreach (['local', 'public'] as $diskName) {
            $disk = Storage::disk($diskName);

            if ($disk->exists($path)) {
                return hash('sha256', $disk->get($path));
            }
        }

        $legacyPath = storage_path('app/'.ltrim($path, '/'));

        return is_file($legacyPath) ? hash_file('sha256', $legacyPath) : null;
    }

    private function storyTemplateHash(ProductionProject $project): ?string
    {
        $story = $project->order?->story;

        return $story ? $this->hash([
            'id' => $story->id,
            'title' => $story->title,
            'age_range' => $story->age_range,
            'full_story' => $story->full_story,
            'full_desc' => $story->full_desc,
            'short_desc' => $story->short_desc,
            'lesson_value' => $story->lesson_value,
        ]) : null;
    }

    private function storyVersionHash($storyVersion): string
    {
        return $this->hash([
            'id' => $storyVersion->id,
            'version_number' => $storyVersion->version_number,
            'title' => $storyVersion->title,
            'target_age_group' => $storyVersion->target_age_group,
            'educational_values_json' => $storyVersion->educational_values_json,
            'full_story_content' => $storyVersion->full_story_content,
            'status' => $storyVersion->status,
            'output_fingerprint' => $storyVersion->output_fingerprint,
        ]);
    }

    private function sceneHash(ProductionScene $scene): string
    {
        return $this->hash([
            'title' => $scene->title,
            'story_text' => $scene->story_text,
            'visual_direction' => $scene->visual_direction,
            'child_action_pose' => $scene->child_action_pose,
            'environment' => $scene->environment,
            'mood_lighting' => $scene->mood_lighting,
            'supporting_characters' => $scene->supporting_characters,
            'key_objects' => $scene->key_objects,
            'continuity_notes' => $scene->continuity_notes,
            'text_safe_area_notes' => $scene->text_safe_area_notes,
            'educational_value' => $scene->educational_value,
        ]);
    }

    private function characterProfileHash(ProductionProject $project): ?string
    {
        $profile = $project->characterProfile;

        return $profile ? $this->hash([
            'appearance_summary' => $profile->appearance_summary,
            'hair_details' => $profile->hair_details,
            'skin_tone' => $profile->skin_tone,
            'eye_color_traits' => $profile->eye_color_traits,
            'typical_expression' => $profile->typical_expression,
            'face_shape_notes' => $profile->face_shape_notes,
            'body_proportion_notes' => $profile->body_proportion_notes,
            'identity_rules' => $profile->identity_rules,
            'negative_instructions' => $profile->negative_instructions,
            'approved_reference_photos' => $profile->approved_reference_photos,
            'output_fingerprint' => $profile->output_fingerprint,
        ]) : null;
    }

    private function sortRecursive(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortRecursive($value);
            }
        }

        return $payload;
    }
}
