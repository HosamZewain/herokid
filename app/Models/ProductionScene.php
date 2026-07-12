<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionScene extends Model
{
    protected $guarded = [];

    protected $casts = [
        'original_template_data_json' => 'array',
        'personalization_warnings' => 'array',
    ];

    public function hasImagePromptContext(): bool
    {
        return filled($this->story_text)
            && filled($this->visual_direction)
            && filled($this->child_action_pose);
    }

    public function oldHeroConflicts(?string $templateHero = null): array
    {
        $templateHero = trim((string) ($templateHero ?: $this->template_hero_name ?: $this->project?->template_hero_name));
        $childName = trim((string) ($this->personalized_hero_name ?: $this->project?->personalized_hero_name ?: $this->project?->order?->child_name));

        if ($templateHero === '' || $this->isChildNameAlias($templateHero, $childName)) {
            return [];
        }

        $conflicts = [];
        foreach (['title', 'story_text', 'visual_direction', 'child_action_pose', 'environment', 'continuity_notes'] as $field) {
            $value = (string) ($this->{$field} ?? '');
            if ($value !== '' && preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($templateHero, '/').'(?![\p{L}\p{N}_])/u', $value)) {
                $conflicts[] = $field;
            }
        }

        return $conflicts;
    }

    public function mentionsHero(?string $childName = null): bool
    {
        $childName = trim((string) ($childName ?: $this->personalized_hero_name ?: $this->project?->personalized_hero_name ?: $this->project?->order?->child_name));

        if ($childName === '') {
            return false;
        }

        $context = collect(['story_text', 'visual_direction', 'child_action_pose'])
            ->map(fn (string $field): string => (string) ($this->{$field} ?? ''))
            ->implode("\n");

        return preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($childName, '/').'(?![\p{L}\p{N}_])/u', $context) === 1;
    }

    public function fieldMentionsHero(string $field, ?string $childName = null): bool
    {
        $childName = trim((string) ($childName ?: $this->personalized_hero_name ?: $this->project?->personalized_hero_name ?: $this->project?->order?->child_name));
        $value = (string) ($this->{$field} ?? '');

        if ($childName === '' || $value === '') {
            return false;
        }

        foreach ($this->childNameAliases($childName) as $alias) {
            if (preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($alias, '/').'(?![\p{L}\p{N}_])/u', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    public function isPersonalizedForImageGeneration(): bool
    {
        $childHero = trim((string) ($this->personalized_hero_name ?: $this->project?->personalized_hero_name ?: $this->project?->order?->child_name));

        return $this->personalization_status !== 'skipped'
            && $childHero !== ''
            && $this->oldHeroConflicts() === []
            && $this->fieldMentionsHero('visual_direction', $childHero)
            && $this->fieldMentionsHero('child_action_pose', $childHero);
    }

    public function project()
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function storyVersion()
    {
        return $this->belongsTo(ProductionStoryVersion::class, 'production_story_version_id');
    }

    public function generationJobs()
    {
        return $this->hasMany(SceneGenerationJob::class);
    }

    public function assets()
    {
        return $this->hasMany(ProductionProjectAsset::class);
    }

    public function approvedFinalImage()
    {
        return $this->hasOne(ProductionProjectAsset::class)
            ->where('asset_type', 'scene_image')
            ->where('is_final', true);
    }

    private function isChildNameAlias(string $templateHero, string $childName): bool
    {
        if ($templateHero === '' || $childName === '') {
            return false;
        }

        return in_array($templateHero, $this->childNameAliases($childName), true);
    }

    private function childNameAliases(string $childName): array
    {
        $childName = trim($childName);
        if ($childName === '') {
            return [];
        }

        $aliases = [$childName];
        $parts = preg_split('/\s+/u', $childName) ?: [];
        $firstName = trim((string) ($parts[0] ?? ''));

        if (mb_strlen($firstName) >= 2) {
            $aliases[] = $firstName;
        }

        return array_values(array_unique($aliases));
    }
}
