<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faq_items', function (Blueprint $table): void {
            $table->boolean('show_on_packages')->default(false)->after('active')->index();
        });

        $faqs = [
            ['question' => 'هل السعر شامل الشحن؟', 'answer' => 'رسوم الشحن تُحسب في السلة حسب محافظتك، وتظهر لك بوضوح قبل تأكيد الطلب.', 'sort_order' => 10],
            ['question' => 'متى يتم الدفع؟', 'answer' => 'يتم الدفع بعد مراجعة الطلب وإرسال رابط الدفع. لن يُطلب منك الدفع قبل رؤية القصة أولًا.', 'sort_order' => 20],
            ['question' => 'ما هي طرق الدفع المتاحة؟', 'answer' => 'يتم تأكيد طريقة الدفع المتاحة والمناسبة معك قبل بدء الإنتاج.', 'sort_order' => 30],
            ['question' => 'هل يمكن تعديل التصميم قبل الطباعة؟', 'answer' => 'نرسل لك المعاينة أولًا، ويمكنك طلب التعديلات قبل اعتماد التصميم للطباعة.', 'sort_order' => 40],
        ];

        foreach ($faqs as $faq) {
            $existingId = DB::table('faq_items')->where('question', $faq['question'])->value('id');
            if ($existingId) {
                DB::table('faq_items')->where('id', $existingId)->update(['show_on_packages' => true]);

                continue;
            }

            DB::table('faq_items')->insert($faq + [
                'active' => true,
                'show_on_packages' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('faq_items', function (Blueprint $table): void {
            $table->dropIndex(['show_on_packages']);
            $table->dropColumn('show_on_packages');
        });
    }
};
