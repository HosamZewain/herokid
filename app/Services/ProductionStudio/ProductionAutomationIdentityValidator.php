<?php

namespace App\Services\ProductionStudio;

class ProductionAutomationIdentityValidator
{
    private const REQUIRED_CRITERIA = [
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

    public function evaluate(array $result, int $threshold = 85): array
    {
        $criteria = data_get($result, 'criteria');
        $score = (int) data_get($result, 'score', -1);

        if (! is_array($criteria)) {
            return $this->failClosed('missing_criteria', 'Structured identity validation did not include criteria.');
        }

        $missing = array_values(array_diff(self::REQUIRED_CRITERIA, array_keys($criteria)));

        if ($missing !== []) {
            return $this->failClosed('missing_required_identity_fields', 'Identity validation is missing required criteria.', [
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
                'safe_failure_code' => 'identity_blocking_flags',
                'safe_failure_summary' => 'Identity validation found blocking visual issues.',
                'score' => max(0, $score),
                'blocking_flags' => $blockingFlags,
                'criteria' => $this->sanitizeCriteria($criteria),
            ];
        }

        if ($score < 0 || $score > 100) {
            return $this->failClosed('invalid_identity_score', 'Identity validation returned an invalid score.');
        }

        return [
            'decision' => $score >= $threshold ? 'pass' : 'review',
            'safe_failure_code' => $score >= $threshold ? null : 'identity_score_below_threshold',
            'safe_failure_summary' => $score >= $threshold ? null : 'Identity score is below automation threshold.',
            'score' => $score,
            'blocking_flags' => [],
            'criteria' => $this->sanitizeCriteria($criteria),
        ];
    }

    public function schema(): array
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

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'summary' => ['type' => 'string'],
                'criteria' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => collect(self::REQUIRED_CRITERIA)
                        ->mapWithKeys(fn (string $key): array => [$key => $criterion])
                        ->all(),
                    'required' => self::REQUIRED_CRITERIA,
                ],
            ],
            'required' => ['score', 'summary', 'criteria'],
        ];
    }

    public function requiredCriteria(): array
    {
        return self::REQUIRED_CRITERIA;
    }

    private function failClosed(string $code, string $summary, array $extra = []): array
    {
        return array_merge([
            'decision' => 'review',
            'safe_failure_code' => $code,
            'safe_failure_summary' => $summary,
            'score' => null,
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
