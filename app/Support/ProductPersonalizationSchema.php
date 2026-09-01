<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Validation\Rule;

class ProductPersonalizationSchema
{
    public const VERSION = 1;

    /**
     * @return array<string, array{label: string, type: string, max?: int}>
     */
    public static function definitions(): array
    {
        return [
            'child_name' => ['label' => 'اسم الطفل', 'type' => 'text', 'max' => 100],
            'school_name' => ['label' => 'اسم المدرسة', 'type' => 'text', 'max' => 150],
            'class_name' => ['label' => 'اسم الفصل / الكلاس', 'type' => 'text', 'max' => 100],
            'child_age' => ['label' => 'عمر الطفل', 'type' => 'age'],
            'child_gender' => ['label' => 'جنس الطفل', 'type' => 'gender'],
            'interests' => ['label' => 'اهتمامات الطفل', 'type' => 'textarea', 'max' => 500],
            'parent_notes' => ['label' => 'ملاحظات ولي الأمر', 'type' => 'textarea', 'max' => 1000],
            'photos' => ['label' => 'صور الطفل', 'type' => 'photos'],
        ];
    }

    /**
     * Preserve the behavior of personalized products created before field-level configuration existed.
     *
     * @return array{version: int, fields: array<string, array<string, mixed>>}
     */
    public static function legacyDefault(): array
    {
        return self::normalize([
            'version' => self::VERSION,
            'fields' => [
                'child_name' => ['enabled' => true, 'required' => true],
                'child_age' => ['enabled' => true, 'required' => true],
                'child_gender' => ['enabled' => true, 'required' => true],
                'interests' => ['enabled' => true, 'required' => false],
                'photos' => [
                    'enabled' => true,
                    'required' => true,
                    'min_files' => (int) config('photo_uploads.min_files', 2),
                    'max_files' => (int) config('photo_uploads.max_files', 3),
                ],
            ],
        ]);
    }

    /**
     * @return array{version: int, fields: array<string, array<string, mixed>>}
     */
    public static function forProduct(Product $product): array
    {
        if ($product->personalization_mode !== 'collect_child_details') {
            return self::empty();
        }

        return self::normalize($product->personalization_fields ?: self::legacyDefault());
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{version: int, fields: array<string, array<string, mixed>>}
     */
    public static function fromAdminInput(array $input): array
    {
        return self::normalize(['version' => self::VERSION, 'fields' => $input]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{version: int, fields: array<string, array<string, mixed>>}
     */
    public static function normalize(array $schema): array
    {
        $submittedFields = is_array($schema['fields'] ?? null)
            ? $schema['fields']
            : array_intersect_key($schema, self::definitions());
        $fields = [];

        foreach (self::definitions() as $key => $definition) {
            $submitted = is_array($submittedFields[$key] ?? null) ? $submittedFields[$key] : [];
            $enabled = filter_var($submitted['enabled'] ?? false, FILTER_VALIDATE_BOOL);
            $required = $enabled && filter_var($submitted['required'] ?? false, FILTER_VALIDATE_BOOL);
            $label = trim((string) ($submitted['label'] ?? $definition['label']));

            $field = [
                'enabled' => $enabled,
                'required' => $required,
                'label' => mb_substr($label !== '' ? $label : $definition['label'], 0, 100),
                'type' => $definition['type'],
            ];

            if ($definition['type'] === 'photos') {
                $maximumLimit = max(1, (int) config('photo_uploads.max_files', 3));
                $minimum = max(1, min($maximumLimit, (int) ($submitted['min_files'] ?? config('photo_uploads.min_files', 2))));
                $maximum = max($minimum, min($maximumLimit, (int) ($submitted['max_files'] ?? $maximumLimit)));
                $field['min_files'] = $minimum;
                $field['max_files'] = $maximum;
            }

            $fields[$key] = $field;
        }

        return ['version' => self::VERSION, 'fields' => $fields];
    }

    /**
     * @return array{version: int, fields: array<string, array<string, mixed>>}
     */
    public static function empty(): array
    {
        return self::normalize(['version' => self::VERSION, 'fields' => []]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    public static function enabledFields(array $schema): array
    {
        return array_filter(
            self::normalize($schema)['fields'],
            fn (array $field): bool => (bool) $field['enabled'],
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<int, mixed>>
     */
    public static function validationRules(array $schema): array
    {
        $rules = [];

        foreach (self::enabledFields($schema) as $key => $field) {
            $presence = $field['required'] ? 'required' : 'nullable';

            $rules[$key] = match ($field['type']) {
                'text' => [$presence, 'string', 'max:'.(self::definitions()[$key]['max'] ?? 255)],
                'textarea' => [$presence, 'string', 'max:'.(self::definitions()[$key]['max'] ?? 1000)],
                'age' => [$presence, 'integer', Rule::in(StoryAgeOptions::forPersonalization())],
                'gender' => [$presence, Rule::in(['boy', 'girl'])],
                'photos' => [
                    $presence,
                    'array',
                    ...($field['required'] ? ['min:'.$field['min_files']] : []),
                    'max:'.$field['max_files'],
                ],
                default => [$presence],
            };

            if ($field['type'] === 'photos') {
                $rules['photo_upload_ids'] = $rules[$key];
                unset($rules[$key]);
                $rules['photo_upload_ids.*'] = ['required', 'string', 'uuid', 'distinct'];
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, string>
     */
    public static function validationMessages(array $schema): array
    {
        $messages = [];

        foreach (self::enabledFields($schema) as $key => $field) {
            $input = $field['type'] === 'photos' ? 'photo_upload_ids' : $key;
            $label = $field['label'];
            $messages[$input.'.required'] = $field['type'] === 'photos'
                ? 'يرجى رفع الصور المطلوبة للمنتج.'
                : 'يرجى إدخال '.$label.'.';
            $messages[$input.'.string'] = 'يرجى إدخال '.$label.' بطريقة صحيحة.';
            $messages[$input.'.max'] = $field['type'] === 'photos'
                ? 'يمكنك رفع '.$field['max_files'].' صور كحد أقصى.'
                : $label.' أطول من الحد المسموح.';

            if ($field['type'] === 'photos') {
                $messages[$input.'.array'] = 'يرجى رفع الصور بطريقة صحيحة.';
                $messages[$input.'.min'] = 'يرجى رفع '.$field['min_files'].' صور على الأقل.';
                $messages[$input.'.*.uuid'] = 'بعض الصور المرفوعة غير صالحة. احذفها وارفعها مرة أخرى.';
                $messages[$input.'.*.distinct'] = 'لا يمكن استخدام الصورة نفسها أكثر من مرة.';
            } elseif ($field['type'] === 'age') {
                $messages[$input.'.integer'] = 'يرجى اختيار عمر صحيح للطفل.';
                $messages[$input.'.in'] = 'يرجى اختيار عمر الطفل من ٢ إلى ١٦ سنة.';
            } elseif ($field['type'] === 'gender') {
                $messages[$input.'.in'] = 'يرجى اختيار جنس صحيح للطفل.';
            }
        }

        return $messages;
    }

    /**
     * Rules for files submitted directly from the admin order form.
     * Public checkout uses temporary upload UUIDs, while admins upload the
     * actual files together with the order.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, array<int, mixed>>
     */
    public static function adminOrderValidationRules(array $schema): array
    {
        $rules = [];

        foreach (self::enabledFields($schema) as $key => $field) {
            $presence = $field['required'] ? 'required' : 'nullable';

            $rules[$key] = match ($field['type']) {
                'text' => [$presence, 'string', 'max:'.(self::definitions()[$key]['max'] ?? 255)],
                'textarea' => [$presence, 'string', 'max:'.(self::definitions()[$key]['max'] ?? 1000)],
                'age' => [$presence, 'integer', Rule::in(StoryAgeOptions::forPersonalization())],
                'gender' => [$presence, Rule::in(['boy', 'girl'])],
                'photos' => [
                    $presence,
                    'array',
                    ...($field['required'] ? ['min:'.$field['min_files']] : []),
                    'max:'.$field['max_files'],
                ],
                default => [$presence],
            };

            if ($field['type'] === 'photos') {
                $rules['photos.*'] = [
                    'required',
                    'file',
                    'max:'.((int) config('photo_uploads.max_size_mb', 15) * 1024),
                ];
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, string>
     */
    public static function adminOrderValidationMessages(array $schema): array
    {
        $messages = self::validationMessages($schema);

        if (array_key_exists('photo_upload_ids.required', $messages)) {
            foreach ($messages as $key => $message) {
                if (str_starts_with($key, 'photo_upload_ids')) {
                    $messages['photos'.substr($key, strlen('photo_upload_ids'))] = $message;
                    unset($messages[$key]);
                }
            }
        }

        $messages['photos.*.file'] = 'تعذر قراءة إحدى الصور المرفوعة. أعد اختيارها وحاول مرة أخرى.';
        $messages['photos.*.max'] = 'حجم كل صورة يجب ألا يزيد عن '.(int) config('photo_uploads.max_size_mb', 15).' ميجا.';

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function snapshot(array $schema, array $validated, int $photoCount): array
    {
        $normalized = self::normalize($schema);
        $fields = [];

        foreach (self::enabledFields($normalized) as $key => $field) {
            $value = $field['type'] === 'photos'
                ? $photoCount
                : ($validated[$key] ?? null);

            $fields[$key] = [
                'label' => $field['label'],
                'type' => $field['type'],
                'required' => $field['required'],
                'value' => $value,
            ];
        }

        return [
            'schema_version' => self::VERSION,
            'schema' => $normalized,
            'fields' => $fields,
            'child_name' => $validated['child_name'] ?? null,
            'school_name' => $validated['school_name'] ?? null,
            'class_name' => $validated['class_name'] ?? null,
            'child_age' => isset($validated['child_age']) ? (int) $validated['child_age'] : null,
            'child_gender' => $validated['child_gender'] ?? null,
            'interests' => $validated['interests'] ?? null,
            'parent_notes' => $validated['parent_notes'] ?? null,
            'uploaded_photos_count' => $photoCount,
        ];
    }

    /**
     * Recover editable values from both current snapshots and the legacy
     * snapshots that stored field values directly under `fields`.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function formValues(array $snapshot): array
    {
        $values = [];
        $snapshotFields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];

        foreach (self::definitions() as $key => $definition) {
            if ($definition['type'] === 'photos') {
                continue;
            }

            $value = $snapshot[$key] ?? null;
            if (array_key_exists($key, $snapshotFields)) {
                $field = $snapshotFields[$key];
                $value = is_array($field) && array_key_exists('value', $field)
                    ? $field['value']
                    : $field;
            }

            if ($value !== null && $value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * Validate the immutable personalization data already stored in a cart item.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $item
     */
    public static function cartItemIsComplete(array $schema, array $item): bool
    {
        foreach (self::enabledFields($schema) as $key => $field) {
            if ($field['type'] === 'photos') {
                $count = count(array_values(array_filter($item['uploaded_photos'] ?? [])));

                if (($field['required'] && $count < (int) $field['min_files']) || $count > (int) $field['max_files']) {
                    return false;
                }

                continue;
            }

            $value = $item[$key] ?? data_get($item, 'personalization_snapshot.'.$key);

            if ($field['required'] && trim((string) $value) === '') {
                return false;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if ($field['type'] === 'age' && ! in_array((int) $value, StoryAgeOptions::forPersonalization(), true)) {
                return false;
            }

            if ($field['type'] === 'gender' && ! in_array($value, ['boy', 'girl'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public static function displayValues(array $snapshot): array
    {
        $values = [];
        $fields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];

        foreach ($fields as $key => $field) {
            if (! is_array($field)) {
                continue;
            }

            $value = $field['value'] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (($field['type'] ?? null) === 'gender') {
                $value = $value === 'boy' ? 'ولد' : ($value === 'girl' ? 'بنت' : $value);
            } elseif (($field['type'] ?? null) === 'age') {
                $value .= ' سنوات';
            } elseif (($field['type'] ?? null) === 'photos') {
                $value .= ' صورة';
            }

            $values[] = [
                'key' => (string) $key,
                'label' => (string) ($field['label'] ?? self::definitions()[$key]['label'] ?? $key),
                'value' => (string) $value,
            ];
        }

        if ($values !== []) {
            return $values;
        }

        foreach (['child_name', 'school_name', 'class_name', 'child_age', 'child_gender', 'interests', 'parent_notes'] as $key) {
            $value = $snapshot[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $definition = self::definitions()[$key] ?? ['label' => $key];
            $values[] = [
                'key' => $key,
                'label' => $definition['label'],
                'value' => $key === 'child_gender'
                    ? ($value === 'boy' ? 'ولد' : ($value === 'girl' ? 'بنت' : (string) $value))
                    : (string) $value,
            ];
        }

        if (($snapshot['uploaded_photos_count'] ?? 0) > 0) {
            $values[] = [
                'key' => 'photos',
                'label' => self::definitions()['photos']['label'],
                'value' => $snapshot['uploaded_photos_count'].' صورة',
            ];
        }

        return $values;
    }
}
