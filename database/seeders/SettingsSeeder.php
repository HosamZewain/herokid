<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareSettings;
use App\Support\SiteImages;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Business Info
            ['key' => 'site_name',          'value' => 'HeroKid'],
            ['key' => 'site_email',         'value' => 'hello@herokid.eg'],
            ['key' => 'support_email',      'value' => 'support@herokid.eg'],
            ['key' => 'privacy_email',      'value' => 'privacy@herokid.eg'],
            ['key' => 'whatsapp_number',    'value' => '201000000000'],
            ['key' => 'whatsapp_url',       'value' => 'https://wa.me/201000000000'],

            // Pricing
            ['key' => 'price_soft_cover',   'value' => '299'],
            ['key' => 'price_hard_cover',   'value' => '399'],
            ['key' => 'story_global_price_enabled', 'value' => '1'],
            ['key' => 'story_regular_price', 'value' => '399'],
            ['key' => 'story_offer_enabled', 'value' => '1'],
            ['key' => 'story_offer_price', 'value' => '349'],
            ['key' => 'story_offer_label', 'value' => 'عرض خاص'],
            ['key' => 'currency_symbol',    'value' => 'ج.م'],
            ['key' => 'currency_label',     'value' => 'ج.م'],
            ['key' => 'delivery_fee',        'value' => '0'],

            // Delivery
            ['key' => 'delivery_days_min',  'value' => '7'],
            ['key' => 'delivery_days_max',  'value' => '10'],
            ['key' => 'production_days',    'value' => '3'],
            ['key' => 'shipping_coverage_text', 'value' => 'شحن لجميع محافظات مصر'],
            ['key' => 'payment_methods', 'value' => json_encode(['فودافون كاش', 'انستاباي', 'فيزا/ماستركارد', 'الدفع عند الاستلام'], JSON_UNESCAPED_UNICODE)],
            ['key' => 'shop_enabled', 'value' => '1'],

            // Social Media
            ['key' => 'instagram_url',      'value' => 'https://instagram.com/herokid.eg'],
            ['key' => 'facebook_url',       'value' => 'https://facebook.com/herokid.eg'],
            ['key' => 'tiktok_url',         'value' => 'https://tiktok.com/@herokid.eg'],

            // Operational
            ['key' => 'orders_open',        'value' => '1'],
            ['key' => 'maintenance_mode',   'value' => '0'],

            // Editable page SEO and marketing copy
            ['key' => 'seo_home_title', 'value' => 'قصص أطفال مخصصة تجعل طفلك بطل القصة بوجهه الحقيقي'],
            ['key' => 'seo_home_description', 'value' => 'HeroKid يحول طفلك إلى بطل قصة مطبوعة بوجهه واسمه. اختر القصة، أرسل صورة طفلك، واستلم كتاباً فاخراً.'],
            ['key' => 'seo_stories_title', 'value' => 'مكتبة قصص الأطفال المخصصة'],
            ['key' => 'seo_stories_description', 'value' => 'استعرض مكتبة HeroKid من قصص الأطفال المخصصة المطبوعة بوجه طفلك واسمه، واختر القصة المناسبة لعمره واهتماماته.'],
            ['key' => 'seo_pricing_title', 'value' => 'أسعار قصص الأطفال المخصصة'],
            ['key' => 'seo_pricing_description', 'value' => 'اكتشف باقات HeroKid لقصص الأطفال المخصصة المطبوعة باسم طفلك ووجهه مع أسعار واضحة ورسوم شحن تظهر في السلة.'],
            ['key' => 'seo_how_it_works_title', 'value' => 'كيف يعمل HeroKid؟'],
            ['key' => 'seo_how_it_works_description', 'value' => 'اكتشف رحلة طلب قصة HeroKid المخصصة من اختيار القصة وإضافة بيانات الطفل حتى المراجعة والطباعة والشحن.'],
            ['key' => 'seo_shop_title', 'value' => 'متجر القصص والمنتجات'],
            ['key' => 'seo_shop_description', 'value' => 'Browse personalized children’s stories, activity books, coloring books, mazes, posters, and gifts from HeroKid.'],
            ['key' => 'unified_store_title', 'value' => 'متجر القصص والمنتجات'],
            ['key' => 'unified_store_subtitle', 'value' => 'كل قصص HeroKid المخصصة وكتب الأنشطة والهدايا في مكان واحد.'],
            ['key' => 'unified_store_default_sort', 'value' => 'best_selling'],

            ['key' => 'home_badge_text', 'value' => 'أول قصة أطفال بوجه طفلك الحقيقي في مصر'],
            ['key' => 'home_feature_face', 'value' => 'وجه طفلك في كل رسمة'],
            ['key' => 'home_feature_values', 'value' => 'قصص بقيم تربوية'],
            ['key' => 'home_feature_delivery', 'value' => 'توصيل لبابك'],
            ['key' => 'home_feature_languages', 'value' => 'لغة القصة موضحة قبل الطلب'],
            ['key' => 'home_story_section_title', 'value' => 'قصص يعشقها الأطفال'],
            ['key' => 'home_story_section_subtitle', 'value' => 'كل قصة تغرس قيمة وتصنع ذكرى. طفلك هو البطل الحقيقي في كل صفحة.'],
            ['key' => 'home_store_section_title', 'value' => 'منتجات تكمل تجربة طفلك'],
            ['key' => 'home_store_section_subtitle', 'value' => 'كتب أنشطة، قصص جاهزة، وهدايا يمكن شراؤها مباشرة أو إضافتها مع القصة المخصصة.'],
            ['key' => 'home_section_hero_enabled', 'value' => '1'],
            ['key' => 'home_section_child_identity_enabled', 'value' => '1'],
            ['key' => 'home_section_stories_enabled', 'value' => '1'],
            ['key' => 'home_section_store_enabled', 'value' => '1'],
            ['key' => 'home_section_how_it_works_enabled', 'value' => '1'],
            ['key' => 'home_section_benefits_enabled', 'value' => '1'],
            ['key' => 'home_section_testimonials_enabled', 'value' => '0'],
            ['key' => 'home_section_pricing_enabled', 'value' => '1'],
            ['key' => 'home_section_faq_enabled', 'value' => '1'],
            ['key' => 'home_section_contact_enabled', 'value' => '1'],
            ['key' => 'home_section_final_cta_enabled', 'value' => '1'],
            ['key' => 'home_child_identity_title', 'value' => 'اصنع هوية طفلك قبل اختيار القصة'],
            ['key' => 'home_child_identity_subtitle', 'value' => 'ارفع صور طفلك مرة واحدة، واحصل على هوية بصرية جاهزة لتختار بعدها القصة المناسبة له.'],
            ['key' => 'home_child_identity_cta', 'value' => 'ابدأ مجانًا'],
            ['key' => 'child_identity_processing_heading', 'value' => 'نجهز هوية :child'],
            ['key' => 'child_identity_processing_description', 'value' => 'تم حفظ البيانات والصور. لا تحتاج إلى تنفيذ أي خطوة أخرى الآن؛ ستظهر النتيجة هنا تلقائيًا.'],
            ['key' => 'child_identity_processing_received_title', 'value' => 'تم استلام البيانات والصور'],
            ['key' => 'child_identity_processing_received_description', 'value' => ':count صور محفوظة بأمان'],
            ['key' => 'child_identity_processing_queued_title', 'value' => 'تجهيز طلب الإنشاء'],
            ['key' => 'child_identity_processing_queued_waiting_description', 'value' => 'في قائمة الانتظار الآن'],
            ['key' => 'child_identity_processing_queued_completed_description', 'value' => 'اكتملت هذه الخطوة'],
            ['key' => 'child_identity_processing_generating_title', 'value' => 'إنشاء هوية الطفل'],
            ['key' => 'child_identity_processing_generating_active_description', 'value' => 'OpenAI ينشئ الهوية الآن'],
            ['key' => 'child_identity_processing_generating_waiting_description', 'value' => 'تبدأ بعد تجهيز الطلب'],
            ['key' => 'child_identity_processing_result_title', 'value' => 'عرض النتيجة'],
            ['key' => 'child_identity_processing_result_description', 'value' => 'ستظهر الهوية تلقائيًا عند اكتمالها'],
            ['key' => 'child_identity_sharing_enabled', 'value' => '1'],
            ['key' => 'child_identity_share_channel_native', 'value' => '1'],
            ['key' => 'child_identity_share_channel_whatsapp', 'value' => '1'],
            ['key' => 'child_identity_share_channel_facebook', 'value' => '1'],
            ['key' => 'child_identity_share_channel_instagram', 'value' => '1'],
            ['key' => 'child_identity_share_channel_copy_link', 'value' => '1'],
            ['key' => 'child_identity_share_channel_copy_caption', 'value' => '1'],
            ['key' => 'child_identity_share_channel_download', 'value' => '1'],
            ['key' => 'child_identity_share_caption_ar', 'value' => ChildIdentityShareSettings::DEFAULT_CAPTION],
            ['key' => 'child_identity_share_caption_en', 'value' => ''],
            ['key' => 'child_identity_share_hashtags', 'value' => ChildIdentityShareSettings::DEFAULT_HASHTAGS],
            ['key' => 'child_identity_share_card_headline', 'value' => 'شوفوا هوية طفلي من HeroKid ✨'],
            ['key' => 'child_identity_share_card_cta', 'value' => 'اصنع هوية طفلك مجانًا'],
            ['key' => 'child_identity_share_card_footer', 'value' => 'شاركها وخلي أصحابك يجربوا'],
            ['key' => 'child_identity_share_landing_title', 'value' => 'شوفوا هوية طفلي من HeroKid ✨'],
            ['key' => 'child_identity_share_landing_description', 'value' => 'اصنع هوية طفلك مجانًا، وشوفه بطلًا في قصة مخصصة له.'],
            ['key' => 'child_identity_share_landing_cta', 'value' => 'اصنع هوية طفلك مجانًا'],
            ['key' => 'child_identity_share_attribution_days', 'value' => '30'],
            ['key' => 'child_identity_share_allow_first_name', 'value' => '1'],
            ['key' => 'child_identity_share_feed_quality', 'value' => '90'],
            ['key' => 'child_identity_share_story_quality', 'value' => '88'],
            ['key' => 'child_identity_share_template_version', 'value' => 'identity-share-v1'],
            ['key' => 'footer_brand_description', 'value' => 'قصص أطفال مخصصة تجعل طفلك بطل القصة بوجهه الحقيقي. نهدف لنشر الحب والقيم الجميلة عبر القصص المطبوعة.'],

        ];

        $howItWorksDefaults = [
            1 => [
                'title' => 'اختر القصة المثالية لطفلك',
                'desc' => 'تصفح مكتبتنا المتنوعة واختر القصة التي تناسب عمر طفلك واهتماماته وتغرس قيمة إنسانية أصيلة.',
                'bullet1' => 'فلتر حسب العمر والجنس واللغة',
                'bullet2' => 'كل قصة تحمل درساً تربوياً واضحاً',
                'bullet3' => 'لغة كل قصة موضحة قبل الطلب',
            ],
            2 => [
                'title' => 'خصّص القصة لطفلك',
                'desc' => 'أخبرنا باسم طفلك وعمره واهتماماته، وأرفق صوراً واضحة لوجهه ليصبح الشخصية الرئيسية في كل صفحة.',
                'bullet1' => 'اسم الطفل في كل صفحة',
                'bullet2' => 'وجه الطفل الحقيقي في رسومات القصة',
                'bullet3' => 'إهداء مخصص في الصفحة الأولى',
            ],
            3 => [
                'title' => 'نولّد القصة ونراجعها لك',
                'desc' => 'يستخدم فريقنا أدوات الإنتاج لإعداد الرسومات ونص القصة بشكل مخصص، ثم يراجع التفاصيل يدوياً قبل إرسال المعاينة.',
                'bullet1' => 'رسومات احترافية مخصصة بالكامل',
                'bullet2' => 'مراجعة يدوية من فريق متخصص',
                'bullet3' => 'تسلمك النسخة النهائية ضمن مدة التوصيل المحددة',
            ],
            4 => [
                'title' => 'راجع ووافق على التصميم',
                'desc' => 'نرسل لك معاينة من القصة قبل الطباعة لتراجعها وتطمئن على كل تفصيلة قبل الموافقة.',
                'bullet1' => 'تراجع القصة كاملة قبل الطباعة',
                'bullet2' => 'يحق لك طلب تعديلات معقولة',
                'bullet3' => 'لن نطبع بدون موافقتك الصريحة',
            ],
            5 => [
                'title' => 'اطبع واستلم الكتاب بباب منزلك',
                'desc' => 'بعد الموافقة نطبع الكتاب بجودة عالية ونشحنه إلى عنوانك حسب منطقة التوصيل المختارة.',
                'bullet1' => 'طباعة احترافية عالية الدقة',
                'bullet2' => 'تغليف هدايا فاخر',
                'bullet3' => 'شحن لجميع محافظات مصر',
            ],
        ];

        foreach ($howItWorksDefaults as $step => $values) {
            foreach ($values as $field => $value) {
                $settings[] = ['key' => "hiw_step{$step}_{$field}", 'value' => $value];
            }
        }

        foreach (SiteImages::settingsDefaults() as $key => $value) {
            $settings[] = ['key' => $key, 'value' => $value];
        }

        foreach ($settings as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], ['value' => $setting['value']]);
        }
    }
}
