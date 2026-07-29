<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_countries')) {
            DB::table('delivery_countries')
                ->where('code', 'EG')
                ->where('name', 'Egypt')
                ->update(['name' => 'مصر', 'updated_at' => now()]);
        }

        if (Schema::hasTable('faq_items')) {
            DB::table('faq_items')
                ->where('question', 'ما هي أنواع الصور المطلوبة؟')
                ->update([
                    'answer' => 'نحتاج صورتين أو ٣ صور واضحة وحديثة لوجه طفلك في إضاءة جيدة. اختر الصور معاً وسيبدأ رفعها تلقائياً. يُفضّل أن يظهر الوجه بوضوح من الأمام ومن زاوية أخرى، مع تجنّب النظارات الشمسية والإضاءة الخلفية القوية.',
                    'updated_at' => now(),
                ]);

            DB::table('faq_items')
                ->where('question', 'ما الفرق بين الغلاف الناعم والغلاف الصلب؟')
                ->update([
                    'answer' => 'تفاصيل النسخة المتاحة والسعر الحالي موضحة في صفحة كل قصة قبل إدخال بيانات الطفل. نعرض سعر القصة والخصم الفعّال بوضوح، وتظهر مصاريف التوصيل والإجمالي في السلة قبل إدخال العنوان.',
                    'updated_at' => now(),
                ]);

            DB::table('faq_items')
                ->where('question', 'هل يمكن طلب قصة بالإنجليزية؟')
                ->update([
                    'answer' => 'لغة كل قصة موضحة بوضوح على بطاقتها وصفحة تفاصيلها قبل الطلب. اختر القصة باللغة المعروضة عليها؛ القصص واللغات المتاحة تتغير بحسب الكتالوج الحالي.',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Commercial copy is intentionally not reverted to outdated information.
    }
};
