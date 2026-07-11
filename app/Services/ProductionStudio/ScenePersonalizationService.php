<?php

namespace App\Services\ProductionStudio;

use App\Models\ProductionProject;
use App\Models\ProductionScene;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ScenePersonalizationService
{
    private const PERSONALIZED_FIELDS = [
        'scene_title',
        'written_text',
        'visual_direction',
        'child_action_pose',
        'environment',
        'supporting_characters',
        'continuity_notes',
    ];

    public function analyze(ProductionProject $project, array $data, ?string $heroOverride = null): array
    {
        $childName = trim((string) $project->order?->child_name);
        $metadataName = trim((string) ($data['template_hero_name'] ?? ''));
        $detected = $this->detectTemplateHero($data);
        $templateHero = trim((string) ($heroOverride ?: $metadataName ?: $detected['name']));
        $confidence = $heroOverride
            ? 'confirmed'
            : (string) ($data['hero_detection_confidence'] ?? $detected['confidence']);
        $templateGender = $this->normalizeGender((string) ($data['template_hero_gender'] ?? $detected['gender']));
        $childGender = $this->normalizeGender((string) $project->order?->child_gender);
        $genderAdaptationNeeded = $templateGender !== null
            && $childGender !== null
            && $templateGender !== $childGender;
        $openAiPersonalized = (bool) ($data['personalization_applied'] ?? false);
        $genderAdaptationApplied = (bool) ($data['gender_adaptation_applied'] ?? false);
        $supportingCharacters = array_values(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            Arr::wrap($data['supporting_character_names'] ?? $detected['supporting_characters'])
        )));
        $warnings = array_values(array_filter(array_merge(
            Arr::wrap($data['personalization_warnings'] ?? []),
            $templateHero === '' ? ['تعذر اكتشاف اسم بطل القالب بثقة.'] : [],
            $childName === '' ? ['اسم الطفل غير متاح في الطلب.'] : [],
            $genderAdaptationNeeded && ! $genderAdaptationApplied
                ? ['اختلاف جنس بطل القالب عن الطفل يحتاج إعادة صياغة عبر OpenAI قبل الحفظ.']
                : [],
        )));

        return [
            'template_hero_name' => $templateHero ?: null,
            'template_hero_gender' => $templateGender,
            'child_hero_name' => $childName ?: null,
            'child_gender' => $childGender,
            'confidence' => $confidence ?: 'low',
            'supporting_characters' => $supportingCharacters,
            'replacement_strategy' => (string) ($data['replacement_strategy'] ?? 'replace_template_hero_with_child_name'),
            'child_story_role' => $this->storyRole($data, $templateHero, $childName, $childGender),
            'gender_adaptation_needed' => $genderAdaptationNeeded,
            'gender_adaptation_applied' => $genderAdaptationApplied,
            'openai_personalized' => $openAiPersonalized,
            'requires_openai' => ! $openAiPersonalized && ($genderAdaptationNeeded || in_array($confidence, ['low', 'unknown'], true) || $templateHero === ''),
            'warnings' => $warnings,
        ];
    }

    public function decoratePreview(ProductionProject $project, ?array $preview, ?string $heroOverride = null, bool $skip = false): ?array
    {
        if (! is_array($preview) || ! is_array(data_get($preview, 'data.scenes'))) {
            return $preview;
        }

        $data = $preview['data'];
        $analysis = $this->analyze($project, $data, $heroOverride);
        $personalized = $skip ? $data : $this->personalizeData($project, $data, $analysis);
        $remaining = $this->oldHeroRemainingInData($personalized, (string) $analysis['template_hero_name']);
        $status = match (true) {
            $skip => 'skipped',
            $analysis['requires_openai'] => 'needs_review',
            $remaining => 'needs_review',
            blank($analysis['template_hero_name']) || blank($analysis['child_hero_name']) => 'needs_review',
            default => 'personalized',
        };

        $analysis['old_hero_name_remaining'] = $remaining;
        $analysis['status'] = $status;
        $analysis['warnings'] = array_values(array_unique(array_filter(array_merge(
            $analysis['warnings'],
            $remaining ? ['ما زال اسم بطل القالب موجودًا في بعض حقول المشاهد الرئيسية.'] : [],
        ))));

        $preview['personalization'] = $analysis;
        $preview['personalized_data'] = $personalized;

        return $preview;
    }

    public function refreshSceneStatus(ProductionScene $scene): void
    {
        $scene->loadMissing('project.order');
        $templateHero = trim((string) ($scene->template_hero_name ?: $scene->project?->template_hero_name));
        $childName = trim((string) ($scene->personalized_hero_name ?: $scene->project?->personalized_hero_name ?: $scene->project?->order?->child_name));
        $conflicts = $scene->oldHeroConflicts($templateHero);
        $missingHeroFields = collect(['visual_direction', 'child_action_pose'])
            ->reject(fn (string $field): bool => $scene->fieldMentionsHero($field, $childName))
            ->values()
            ->all();
        $warnings = [];

        if ($conflicts !== []) {
            $warnings[] = 'يحتوي المشهد على اسم بطل القالب الأصلي: '.$templateHero;
        }

        if ($missingHeroFields !== []) {
            $fieldLabels = [
                'visual_direction' => 'التوجيه البصري',
                'child_action_pose' => 'وضع الطفل',
            ];
            $warnings[] = 'لا تشير الحقول التالية إلى الطفل بصفته بطل المشهد: '.collect($missingHeroFields)
                ->map(fn (string $field): string => $fieldLabels[$field] ?? $field)
                ->implode('، ');
        }

        $scene->forceFill([
            'personalized_hero_name' => $childName ?: null,
            'personalization_status' => $conflicts === [] && $missingHeroFields === [] ? 'personalized' : 'needs_review',
            'personalization_warnings' => $warnings,
        ])->saveQuietly();
    }

    private function personalizeData(ProductionProject $project, array $data, array $analysis): array
    {
        $templateHero = (string) $analysis['template_hero_name'];
        $childName = (string) $analysis['child_hero_name'];

        if ($templateHero === '' || $childName === '' || $templateHero === $childName) {
            return $data;
        }

        foreach ($data['scenes'] as $index => $scene) {
            foreach (self::PERSONALIZED_FIELDS as $field) {
                if (isset($scene[$field]) && is_string($scene[$field])) {
                    $data['scenes'][$index][$field] = $this->replaceHeroName($scene[$field], $templateHero, $childName);
                }
            }

            $visualDirection = (string) ($data['scenes'][$index]['visual_direction'] ?? '');
            if (! $this->containsName($visualDirection, $childName)) {
                $childGender = $this->normalizeGender((string) $project->order?->child_gender);
                $identityWord = $childGender === 'girl' ? 'ملامحها' : 'ملامحه';
                $data['scenes'][$index]['visual_direction'] = trim($visualDirection.' البطل الرئيسي هو '.$childName.'، بعمر ظاهري '.($project->order?->child_age ?: 'مناسب للطلب').' سنوات، مع الحفاظ على '.$identityWord.' من الصور المرجعية المعتمدة فقط. تعبير الوجه والملابس والوضعية يحددها سياق هذا المشهد، ولا تُنسخ من صورة الطفل الأصلية.');
            }

            $pose = (string) ($data['scenes'][$index]['child_action_pose'] ?? '');
            if (! $this->containsName($pose, $childName)) {
                $data['scenes'][$index]['child_action_pose'] = $childName.': '.$pose;
            }
        }

        foreach (['story_title', 'story_summary'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = $this->replaceHeroName($data[$field], $templateHero, $childName);
            }
        }

        $data['personalization_applied'] = true;
        $data['personalized_hero_name'] = $childName;
        $data['personalization_context'] = [
            'child_name' => $childName,
            'child_gender' => $project->order?->child_gender,
            'child_age' => $project->order?->child_age,
            'selected_story_age_range' => $project->order?->story?->age_range,
            'parent_interests' => $project->order?->interests,
            'educational_value' => $project->order?->lesson ?: $project->order?->story?->lesson_value,
        ];

        return $data;
    }

    private function detectTemplateHero(array $data): array
    {
        $roleCandidates = [];
        $tokenCounts = [];
        $genderByName = [];
        $texts = collect($data['scenes'] ?? [])
            ->flatMap(fn (array $scene): array => array_values(Arr::only($scene, self::PERSONALIZED_FIELDS)))
            ->filter(fn ($value): bool => is_string($value) && filled($value));

        foreach ($texts as $text) {
            preg_match_all('/(?:الأميرة|البطلة|الطفلة|الفتاة)\s+([\p{Arabic}]{2,20})/u', $text, $femaleMatches);
            preg_match_all('/(?:الأمير|البطل|الطفل|الولد)\s+([\p{Arabic}]{2,20})/u', $text, $maleMatches);

            foreach ($femaleMatches[1] ?? [] as $name) {
                $roleCandidates[$name] = ($roleCandidates[$name] ?? 0) + 5;
                $genderByName[$name] = 'girl';
            }

            foreach ($maleMatches[1] ?? [] as $name) {
                $roleCandidates[$name] = ($roleCandidates[$name] ?? 0) + 5;
                $genderByName[$name] = 'boy';
            }

            preg_match_all('/[\p{Arabic}]{2,20}/u', $text, $tokens);
            foreach ($tokens[0] ?? [] as $token) {
                if ($this->isLikelyNonName($token)) {
                    continue;
                }

                $tokenCounts[$token] = ($tokenCounts[$token] ?? 0) + 1;
            }
        }

        $scores = $tokenCounts;
        foreach ($roleCandidates as $name => $score) {
            $scores[$name] = ($scores[$name] ?? 0) + $score;
        }
        arsort($scores);
        $name = (string) array_key_first($scores);
        $score = (int) ($scores[$name] ?? 0);
        $confidence = $score >= 8 ? 'high' : ($score >= 4 ? 'medium' : 'low');
        $supporting = array_values(array_slice(array_keys(array_filter(
            $scores,
            fn (int $candidateScore, string $candidate): bool => $candidate !== $name && $candidateScore >= 3,
            ARRAY_FILTER_USE_BOTH
        )), 0, 8));

        return [
            'name' => $name,
            'confidence' => $confidence,
            'gender' => $genderByName[$name] ?? null,
            'supporting_characters' => $supporting,
        ];
    }

    private function oldHeroRemainingInData(array $data, string $templateHero): bool
    {
        if ($templateHero === '') {
            return false;
        }

        foreach ($data['scenes'] ?? [] as $scene) {
            foreach (self::PERSONALIZED_FIELDS as $field) {
                if (is_string($scene[$field] ?? null) && $this->containsName($scene[$field], $templateHero)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function storyRole(array $data, string $templateHero, string $childName, ?string $childGender): ?string
    {
        if ($childName === '') {
            return null;
        }

        $haystack = collect($data['scenes'] ?? [])->pluck('written_text')->filter()->implode("\n");
        if ($templateHero !== '' && preg_match('/الأميرة\s+'.preg_quote($templateHero, '/').'/u', $haystack)) {
            return 'الأميرة '.$childName;
        }
        if ($templateHero !== '' && preg_match('/الأمير\s+'.preg_quote($templateHero, '/').'/u', $haystack)) {
            return 'الأمير '.$childName;
        }

        return $childGender === 'girl' ? 'البطلة '.$childName : ($childGender === 'boy' ? 'البطل '.$childName : $childName);
    }

    private function replaceHeroName(string $text, string $templateHero, string $childName): string
    {
        return preg_replace(
            '/(?<![\p{L}\p{N}_])'.preg_quote($templateHero, '/').'(?![\p{L}\p{N}_])/u',
            $childName,
            $text
        ) ?? $text;
    }

    private function containsName(string $text, string $name): bool
    {
        return $name !== '' && preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($name, '/').'(?![\p{L}\p{N}_])/u', $text) === 1;
    }

    private function normalizeGender(string $gender): ?string
    {
        return match (Str::lower(trim($gender))) {
            'girl', 'female', 'f', 'بنت', 'أنثى', 'انثى' => 'girl',
            'boy', 'male', 'm', 'ولد', 'ذكر' => 'boy',
            default => null,
        };
    }

    private function isLikelyNonName(string $token): bool
    {
        static $stopWords = [
            'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'على', 'إلى', 'الى', 'من', 'في', 'عن', 'مع', 'ثم', 'كان', 'كانت',
            'كل', 'بعد', 'قبل', 'حتى', 'عند', 'بين', 'فوق', 'تحت', 'داخل', 'خارج', 'أمام', 'خلف', 'نحو', 'عبر',
            'مشهد', 'المشهد', 'القصة', 'الطفل', 'الطفلة', 'البطل', 'البطلة', 'الأميرة', 'الأمير', 'الفتاة', 'الولد',
            'تقف', 'يقف', 'تجلس', 'يجلس', 'تنظر', 'ينظر', 'تتحرك', 'يتحرك', 'تذهب', 'يذهب', 'تعود', 'يعود',
            'ضوء', 'ليل', 'الليل', 'صباح', 'المملكة', 'القصر', 'طريق', 'تظهر', 'يظهر', 'كبير', 'صغير', 'قديم', 'هادئ',
            'واحد', 'نفس', 'دون', 'غير', 'يجب', 'يكون', 'تكون', 'وهي', 'وهو', 'حول', 'خلال', 'هناك', 'هنا',
        ];

        return mb_strlen($token) < 2 || in_array($token, $stopWords, true);
    }
}
