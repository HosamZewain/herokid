<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('personalization_fields')->nullable()->after('personalization_mode');
        });

        $schoolStickerFields = [
            'version' => 1,
            'fields' => [
                'child_name' => [
                    'enabled' => true,
                    'required' => true,
                    'label' => 'اسم الطفل كاملًا',
                    'type' => 'text',
                ],
                'school_name' => [
                    'enabled' => true,
                    'required' => true,
                    'label' => 'اسم المدرسة',
                    'type' => 'text',
                ],
                'class_name' => [
                    'enabled' => true,
                    'required' => true,
                    'label' => 'اسم الفصل / الكلاس',
                    'type' => 'text',
                ],
                'child_age' => ['enabled' => false, 'required' => false, 'label' => 'عمر الطفل', 'type' => 'age'],
                'child_gender' => ['enabled' => false, 'required' => false, 'label' => 'جنس الطفل', 'type' => 'gender'],
                'interests' => ['enabled' => false, 'required' => false, 'label' => 'اهتمامات الطفل', 'type' => 'textarea'],
                'parent_notes' => ['enabled' => false, 'required' => false, 'label' => 'ملاحظات ولي الأمر', 'type' => 'textarea'],
                'photos' => [
                    'enabled' => true,
                    'required' => true,
                    'label' => 'صور الطفل',
                    'type' => 'photos',
                    'min_files' => 2,
                    'max_files' => 3,
                ],
            ],
        ];

        DB::table('products')
            ->where('slug', 'school-sticker')
            ->where('personalization_mode', 'collect_child_details')
            ->update(['personalization_fields' => json_encode($schoolStickerFields, JSON_UNESCAPED_UNICODE)]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('personalization_fields');
        });
    }
};
