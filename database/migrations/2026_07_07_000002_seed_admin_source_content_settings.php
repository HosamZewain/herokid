<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defaults = [
            'currency_label' => 'ج.م',
            'shipping_coverage_text' => 'شحن لجميع محافظات مصر',
            'payment_methods' => json_encode(['فودافون كاش', 'انستاباي', 'فيزا/ماستركارد', 'الدفع عند الاستلام'], JSON_UNESCAPED_UNICODE),
            'shop_enabled' => '1',

            'seo_home_title' => 'قصص أطفال مخصصة تجعل طفلك بطل القصة بوجهه الحقيقي',
            'seo_home_description' => 'HeroKid يحول طفلك إلى بطل قصة مطبوعة بوجهه واسمه. اختر القصة، أرسل صورة طفلك، واستلم كتاباً فاخراً.',
            'seo_stories_title' => 'مكتبة قصص الأطفال المخصصة',
            'seo_stories_description' => 'استعرض مكتبة HeroKid من قصص الأطفال المخصصة المطبوعة بوجه طفلك واسمه، واختر القصة المناسبة لعمره واهتماماته.',
            'seo_pricing_title' => 'أسعار قصص الأطفال المخصصة',
            'seo_pricing_description' => 'اكتشف باقات HeroKid لقصص الأطفال المخصصة المطبوعة باسم طفلك ووجهه مع أسعار واضحة ورسوم شحن تظهر في السلة.',
            'seo_how_it_works_title' => 'كيف يعمل HeroKid؟',
            'seo_how_it_works_description' => 'اكتشف رحلة طلب قصة HeroKid المخصصة من اختيار القصة وإضافة بيانات الطفل حتى المراجعة والطباعة والشحن.',
            'seo_shop_title' => 'متجر HeroKid للأطفال',
            'seo_shop_description' => 'تسوق كتب أنشطة، قصص جاهزة، وهدايا مخصصة تكمل تجربة قصة طفلك من HeroKid.',

            'home_badge_text' => 'أول قصة أطفال بوجه طفلك الحقيقي في مصر',
            'home_feature_face' => 'وجه طفلك في كل رسمة',
            'home_feature_values' => 'قصص بقيم تربوية',
            'home_feature_delivery' => 'توصيل لبابك',
            'home_feature_languages' => 'عربي وإنجليزي',
            'home_story_section_title' => 'قصص يعشقها الأطفال',
            'home_story_section_subtitle' => 'كل قصة تغرس قيمة وتصنع ذكرى. طفلك هو البطل الحقيقي في كل صفحة.',
            'home_store_section_title' => 'منتجات تكمل تجربة طفلك',
            'home_store_section_subtitle' => 'كتب أنشطة، قصص جاهزة، وهدايا يمكن شراؤها مباشرة أو إضافتها مع القصة المخصصة.',
            'footer_brand_description' => 'قصص أطفال مخصصة تجعل طفلك بطل القصة بوجهه الحقيقي. نهدف لنشر الحب والقيم الجميلة عبر القصص المطبوعة.',

            'hiw_step1_title' => 'اختر القصة المثالية لطفلك',
            'hiw_step1_desc' => 'تصفح مكتبتنا المتنوعة واختر القصة التي تناسب عمر طفلك واهتماماته وتغرس قيمة إنسانية أصيلة.',
            'hiw_step1_bullet1' => 'فلتر حسب العمر والجنس واللغة',
            'hiw_step1_bullet2' => 'كل قصة تحمل درساً تربوياً واضحاً',
            'hiw_step1_bullet3' => 'متاحة بالعربية والإنجليزية',
            'hiw_step2_title' => 'خصّص القصة لطفلك',
            'hiw_step2_desc' => 'أخبرنا باسم طفلك وعمره واهتماماته، وأرفق صوراً واضحة لوجهه ليصبح الشخصية الرئيسية في كل صفحة.',
            'hiw_step2_bullet1' => 'اسم الطفل في كل صفحة',
            'hiw_step2_bullet2' => 'وجه الطفل الحقيقي في رسومات القصة',
            'hiw_step2_bullet3' => 'إهداء مخصص في الصفحة الأولى',
            'hiw_step3_title' => 'نولّد القصة ونراجعها لك',
            'hiw_step3_desc' => 'يستخدم فريقنا أدوات الإنتاج لإعداد الرسومات ونص القصة بشكل مخصص، ثم يراجع التفاصيل يدوياً قبل إرسال المعاينة.',
            'hiw_step3_bullet1' => 'رسومات احترافية مخصصة بالكامل',
            'hiw_step3_bullet2' => 'مراجعة يدوية من فريق متخصص',
            'hiw_step3_bullet3' => 'تسلمك النسخة النهائية ضمن مدة التوصيل المحددة',
            'hiw_step4_title' => 'راجع ووافق على التصميم',
            'hiw_step4_desc' => 'نرسل لك معاينة من القصة قبل الطباعة لتراجعها وتطمئن على كل تفصيلة قبل الموافقة.',
            'hiw_step4_bullet1' => 'تراجع القصة كاملة قبل الطباعة',
            'hiw_step4_bullet2' => 'يحق لك طلب تعديلات معقولة',
            'hiw_step4_bullet3' => 'لن نطبع بدون موافقتك الصريحة',
            'hiw_step5_title' => 'اطبع واستلم الكتاب بباب منزلك',
            'hiw_step5_desc' => 'بعد الموافقة نطبع الكتاب بجودة عالية ونشحنه إلى عنوانك حسب منطقة التوصيل المختارة.',
            'hiw_step5_bullet1' => 'طباعة احترافية عالية الدقة',
            'hiw_step5_bullet2' => 'تغليف هدايا فاخر',
            'hiw_step5_bullet3' => 'شحن لجميع محافظات مصر',
        ];

        foreach ($defaults as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table('settings')->where('key', 'hero_subtitle')->where('value', 'like', '%199%')->update([
            'value' => 'نحوّل خيال طفلك إلى كتاب مطبوع يحمل اسمه ووجهه الحقيقي في كل صفحة.',
            'updated_at' => $now,
        ]);

        Cache::forget('site_settings');
    }

    public function down(): void
    {
        $keys = [
            'currency_label',
            'shipping_coverage_text',
            'payment_methods',
            'shop_enabled',
            'seo_home_title',
            'seo_home_description',
            'seo_stories_title',
            'seo_stories_description',
            'seo_pricing_title',
            'seo_pricing_description',
            'seo_how_it_works_title',
            'seo_how_it_works_description',
            'seo_shop_title',
            'seo_shop_description',
            'home_badge_text',
            'home_feature_face',
            'home_feature_values',
            'home_feature_delivery',
            'home_feature_languages',
            'home_story_section_title',
            'home_story_section_subtitle',
            'home_store_section_title',
            'home_store_section_subtitle',
            'footer_brand_description',
            'hiw_step1_title',
            'hiw_step1_desc',
            'hiw_step1_bullet1',
            'hiw_step1_bullet2',
            'hiw_step1_bullet3',
            'hiw_step2_title',
            'hiw_step2_desc',
            'hiw_step2_bullet1',
            'hiw_step2_bullet2',
            'hiw_step2_bullet3',
            'hiw_step3_title',
            'hiw_step3_desc',
            'hiw_step3_bullet1',
            'hiw_step3_bullet2',
            'hiw_step3_bullet3',
            'hiw_step4_title',
            'hiw_step4_desc',
            'hiw_step4_bullet1',
            'hiw_step4_bullet2',
            'hiw_step4_bullet3',
            'hiw_step5_title',
            'hiw_step5_desc',
            'hiw_step5_bullet1',
            'hiw_step5_bullet2',
            'hiw_step5_bullet3',
        ];

        DB::table('settings')->whereIn('key', $keys)->delete();
        Cache::forget('site_settings');
    }
};
