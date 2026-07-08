<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionCharacterProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reference_photo_selection' => 'array',
        'approved_reference_photos' => 'array',
        'primary_face_reference_index' => 'integer',
        'body_reference_index' => 'integer',
        'style_reference_index' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function isReadyForAiGeneration(): bool
    {
        return $this->missingAiGenerationFields() === [];
    }

    public function missingAiGenerationFields(): array
    {
        $fields = [
            'appearance_summary' => 'ملخص المظهر',
            'hair_details' => 'تفاصيل الشعر',
            'skin_tone' => 'لون البشرة',
            'eye_color_traits' => 'العين والملامح الظاهرة',
            'typical_expression' => 'التعبير المعتاد',
            'identity_rules' => 'قواعد الحفاظ على الهوية',
            'negative_instructions' => 'تعليمات سلبية',
        ];

        $missing = [];

        foreach ($fields as $field => $label) {
            if (blank($this->{$field})) {
                $missing[$field] = $label;
            }
        }

        if ($this->approvedReferenceIndices() === []) {
            $missing['approved_reference_photos'] = 'صورة مرجعية واضحة';
        }

        if ($this->primaryFaceReferenceIndex() === null) {
            $missing['primary_face_reference_index'] = 'الصورة الأساسية للوجه';
        }

        return $missing;
    }

    public function approvedReferenceIndices(): array
    {
        return array_values(array_unique(array_map('intval', $this->approved_reference_photos ?? [])));
    }

    public function primaryFaceReferenceIndex(): ?int
    {
        if ($this->primary_face_reference_index !== null) {
            return (int) $this->primary_face_reference_index;
        }

        return $this->approvedReferenceIndices()[0] ?? null;
    }

    public function bodyReferenceIndex(): ?int
    {
        return $this->body_reference_index !== null ? (int) $this->body_reference_index : null;
    }

    public function styleReferenceIndex(): ?int
    {
        return $this->style_reference_index !== null ? (int) $this->style_reference_index : null;
    }
}
