<?php

namespace App\Services\ProductionStudio;

class ProductionAutomationVisualValidator
{
    private const BASE_CRITERIA = [
        'age_consistency',
        'face_structure',
        'hair_color_style',
        'skin_tone',
        'eye_characteristics',
        'glasses_or_accessories',
        'gender_presentation',
        'correct_number_of_children',
        'no_unrelated_characters',
        'no_adult_looking_child',
        'no_text_logos_watermarks',
        'safe_content',
    ];

    private const COVER_CRITERIA = [
        'story_relevance',
        'cover_composition',
        'portrait_orientation',
        'safe_crop_trim',
    ];

    private const SCENE_CRITERIA = [
        'scene_action',
        'scene_environment',
        'story_moment',
        'landscape_composition',
        'safe_text_area',
        'safe_crop_trim',
        'visual_continuity',
    ];

    public function evaluate(array $result, string $type, int $identityThreshold = 85, int $adherenceThreshold = 80): array
    {
        $criteria = data_get($result, 'criteria');
        $identityScore = (int) data_get($result, 'identity_score', -1);
        $adherenceKey = $type === 'cover' ? 'story_relevance_score' : 'scene_adherence_score';
        $adherenceScore = (int) data_get($result, $adherenceKey, -1);
        $required = $this->criteriaFor($type);

        if (! is_array($criteria)) {
            return $this->failClosed('missing_visual_criteria', 'Structured visual validation did not include criteria.');
        }

        $missing = array_values(array_diff($required, array_keys($criteria)));
        if ($missing !== []) {
            return $this->failClosed('missing_required_visual_fields', 'Visual validation is missing required criteria.', [
                'missing' => $missing,
            ]);
        }

        $blockingFlags = collect($criteria)
            ->filter(fn ($criterion): bool => is_array($criterion) && (bool) ($criterion['blocking'] ?? false))
            ->keys()
            ->values()
            ->all();

        if ($blockingFlags !== []) {
            return [
                'decision' => 'fail',
                'safe_failure_code' => 'visual_blocking_flags',
                'safe_failure_summary' => 'Visual validation found blocking issues.',
                'identity_score' => max(0, $identityScore),
                $adherenceKey => max(0, $adherenceScore),
                'blocking_flags' => $blockingFlags,
                'criteria' => $this->sanitizeCriteria($criteria),
            ];
        }

        if ($identityScore < 0 || $identityScore > 100) {
            return $this->failClosed('invalid_identity_score', 'Visual validation returned an invalid identity score.');
        }

        if ($adherenceScore < 0 || $adherenceScore > 100) {
            return $this->failClosed('invalid_adherence_score', 'Visual validation returned an invalid story/scene score.');
        }

        if ($identityScore < $identityThreshold) {
            return [
                'decision' => 'review',
                'safe_failure_code' => 'identity_score_below_threshold',
                'safe_failure_summary' => 'Identity score is below automation threshold.',
                'identity_score' => $identityScore,
                $adherenceKey => $adherenceScore,
                'blocking_flags' => [],
                'criteria' => $this->sanitizeCriteria($criteria),
            ];
        }

        if ($adherenceScore < $adherenceThreshold) {
            return [
                'decision' => 'review',
                'safe_failure_code' => $type === 'cover' ? 'story_relevance_below_threshold' : 'scene_adherence_below_threshold',
                'safe_failure_summary' => $type === 'cover'
                    ? 'Cover story relevance is below automation threshold.'
                    : 'Scene adherence is below automation threshold.',
                'identity_score' => $identityScore,
                $adherenceKey => $adherenceScore,
                'blocking_flags' => [],
                'criteria' => $this->sanitizeCriteria($criteria),
            ];
        }

        return [
            'decision' => 'pass',
            'safe_failure_code' => null,
            'safe_failure_summary' => null,
            'identity_score' => $identityScore,
            $adherenceKey => $adherenceScore,
            'blocking_flags' => [],
            'criteria' => $this->sanitizeCriteria($criteria),
        ];
    }

    public function schema(string $type): array
    {
        $criterion = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pass', 'review', 'fail']],
                'evidence' => ['type' => 'string'],
                'blocking' => ['type' => 'boolean'],
            ],
            'required' => ['status', 'evidence', 'blocking'],
        ];

        $adherenceKey = $type === 'cover' ? 'story_relevance_score' : 'scene_adherence_score';
        $criteria = $this->criteriaFor($type);

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'identity_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                $adherenceKey => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'summary' => ['type' => 'string'],
                'criteria' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => collect($criteria)
                        ->mapWithKeys(fn (string $key): array => [$key => $criterion])
                        ->all(),
                    'required' => $criteria,
                ],
            ],
            'required' => ['identity_score', $adherenceKey, 'summary', 'criteria'],
        ];
    }

    public function criteriaFor(string $type): array
    {
        return array_values(array_unique(array_merge(
            self::BASE_CRITERIA,
            $type === 'cover' ? self::COVER_CRITERIA : self::SCENE_CRITERIA,
        )));
    }

    private function failClosed(string $code, string $summary, array $extra = []): array
    {
        return array_merge([
            'decision' => 'review',
            'safe_failure_code' => $code,
            'safe_failure_summary' => $summary,
            'identity_score' => null,
            'story_relevance_score' => null,
            'scene_adherence_score' => null,
            'blocking_flags' => [],
            'criteria' => [],
        ], $extra);
    }

    private function sanitizeCriteria(array $criteria): array
    {
        return collect($criteria)
            ->map(fn ($criterion): array => [
                'status' => in_array($criterion['status'] ?? null, ['pass', 'review', 'fail'], true) ? $criterion['status'] : 'review',
                'evidence' => mb_substr((string) ($criterion['evidence'] ?? ''), 0, 500),
                'blocking' => (bool) ($criterion['blocking'] ?? false),
            ])
            ->all();
    }
}
