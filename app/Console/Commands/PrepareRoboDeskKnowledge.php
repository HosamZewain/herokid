<?php

namespace App\Console\Commands;

use App\Models\FaqItem;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\Setting;
use App\Support\PublicExperienceCopy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PrepareRoboDeskKnowledge extends Command
{
    protected $signature = 'robodesk:prepare-knowledge {--apply : Apply the approved public-content corrections once}';

    protected $description = 'Review/apply approved catalog and CMS corrections; never changes order, payment or selling-price data';

    public function handle(): int
    {
        if (Setting::where('key', 'robodesk_content_revision')->value('value') === '2026-10-28-v1') {
            $this->info('Approved content corrections already applied; subsequent admin edits preserved.');

            return self::SUCCESS;
        }
        $plans = [];
        $set = function ($model, array $attributes) use (&$plans): void {
            $plans[] = [$model, $attributes];
        };
        $policies = [
            'payment' => 'بتختار طريقة الدفع المتاحة عند إتمام الطلب. للمنتجات المخصصة يشترط دفع ديبوزت بعد تأكيد المعاينة على واتساب، وقبل بدء الإنتاج. فريقنا بيأكد معاك قيمة الديبوزت وطريقة دفعه.',
            'delivery' => 'الإنتاج من 2 إلى 3 أيام عمل بعد تأكيد المعاينة ودفع الديبوزت، وبعدها الشحن من 2 إلى 4 أيام عمل. الشحن داخل مصر فقط، ورسومه بتظهر حسب المحافظة قبل تأكيد الطلب.',
            'privacy' => 'صور طفلك بتُستخدم لتنفيذ الخدمة وبتتحفظ بشكل آمن لدعم الطلب وإعادة الطلب. تقدر تطلب حذف صور طفلك وبياناته من صفحة حذف الحساب والبيانات أو بالتواصل معنا.',
            'photos' => 'للقصص نحتاج {{photo_min}}–{{photo_max}} صور واضحة وحديثة لوجه الطفل، من الأمام ومن زاوية تانية، بإضاءة جيدة وبدون نظارة شمس. الصيغ المقبولة: {{photo_formats}}. أقصى حجم للصورة {{photo_mb}} ميجابايت. المنتجات الأخرى حسب عدد الصور الموضح في صفحة المنتج.',
            'preview' => 'بنبعتلك المعاينة على واتساب لتراجع الاسم والصورة والتصميم، وتقدر تطلب تعديلات قبل اعتمادها. الطباعة بعد موافقتك على المعاينة. المنتجات الجاهزة لا تحتاج معاينة.',
            'tracking' => 'تابع طلبك من صفحة تتبع الطلب باستخدام بيانات الطلب المطلوبة في الصفحة، أو تواصل معنا برقم الطلب.',
            'library' => 'مكتبة القصص المجانية فيها قصص كاملة جاهزة للقراءة بقارئ PDF على الموقع والتطبيق، بدون شراء قصة مخصصة. افتح https://hero-kid.com/story-library',
            'identity' => 'تقدر تستخدم أداة هوية الطفل المجانية من الموقع لإنشاء صورة هوية لطفلك ببياناته وصوره. افتح https://hero-kid.com/child-identity',
        ];
        foreach ($policies as $key => $value) {
            $set(Setting::firstOrNew(['key' => 'customer_policy_'.$key]), ['value' => $value]);
        }
        foreach (PublicExperienceCopy::DEFAULTS as $key => $value) {
            if (str_starts_with($key, 'hiw_step') && ! in_array($key, ['hiw_step3_desc', 'hiw_step4_desc', 'hiw_step5_desc'], true) && ! Setting::where('key', $key)->exists()) {
                $set(Setting::firstOrNew(['key' => $key]), ['value' => $value]);
            }
        }
        foreach (['hiw_step3_desc' => 'راجع السلة والإجمالي، وأدخل بيانات التواصل والتوصيل واختار طريقة الدفع المتاحة. {{policy:payment}}',
            'hiw_step4_desc' => '{{policy:preview}}', 'hiw_step5_desc' => '{{policy:delivery}}'] as $key => $value) {
            $set(Setting::firstOrNew(['key' => $key]), ['value' => $value]);
        }
        foreach (FaqItem::all() as $faq) {
            $question = $faq->question;
            $answer = null;
            if (str_contains($question, 'الغلاف الناعم') || str_contains($question, 'الغلاف الصلب')) {
                $set($faq, ['active' => false]);

                continue;
            }
            if (str_contains($question, 'بالإنجليزية') || str_contains($question, 'بالانجليزية')) {
                $answer = 'نعم، كل القصص المخصصة متاحة بالعربي أو الإنجليزي. اختار لغة القصة من نموذج الطلب في صفحة القصة.';
            } elseif (str_contains($question, 'باقة') && (str_contains($question, 'قصة') || str_contains($question, 'قصص'))) {
                $answer = 'باقة 3 قصص بسعر {{package_price:bak-3-kss-mkhss}}. تختار القصص من الخيارات المتاحة للباقة، وتستخدم بيانات طفل واحد لكل الباقة.';
            } elseif (str_contains($question, 'يستغرق') || str_contains($question, 'خارج مصر')) {
                $answer = '{{policy:delivery}}';
            } elseif (str_contains($question, 'متى يتم الدفع') || str_contains($question, 'طرق الدفع')) {
                $answer = '{{policy:payment}}';
            } elseif (str_contains($question, 'أمان') || (str_contains($faq->answer, 'حذف') && preg_match('/(?<![0-9٠-٩])(?:90|٩٠)(?![0-9٠-٩])|تلقائ/u', $faq->answer))) {
                $answer = '{{policy:privacy}}';
            } elseif (str_contains($question, 'أنواع الصور')) {
                $answer = '{{policy:photos}}';
            } elseif (str_contains($question, 'أتتبع')) {
                $answer = '{{policy:tracking}}';
            } elseif (str_contains($question, 'معاينة') || str_contains($question, 'مراجعة القصة') || str_contains($question, 'تعديل التصميم')) {
                $answer = '{{policy:preview}}';
            }
            if ($answer !== null) {
                $set($faq, ['answer' => $answer]);
            }
        }
        foreach (['maze-book-level-1' => '3-5', 'maze-book-level-2' => '4-7', 'maze-book-level-4' => '8-12',
            'puzzle-book-level-1' => '3-5', 'puzzle-book-level-2' => '5-7', 'puzzle-book-level-3' => '7-9'] as $slug => $age) {
            if ($product = Product::where('slug', $slug)->first()) {
                $set($product, ['age_groups' => [$age]]);
            }
        }
        $descriptions = [
            'bostr-mkhss-bsor-altfl-mkas-a4' => 'بوستر مطبوع مع فريم، مخصص باسم الطفل وصورته حسب اهتماماته. المقاس والسعر حسب الاختيار المحدد في صفحة المنتج. نرسل معاينة قبل الإنتاج.',
            'school-sticker' => '40 استيكر وسط +40 استيكر صغير مخصص باسم وصورة طفلك وبيانات المدرسة، للكتب والكراسات والأدوات. مقاومة للمياه والقطع، مع معاينة قبل الطباعة.',
            'welcome-to-school-personalized-bundle' => 'باقة لطفل واحد تضم 20 استيكر كبير، و30 استيكر صغير، و10 استيكرات حرارية للملابس، وبطاقة هوية، وكارت شنطة. تخصيص بالاسم والصورة حسب متطلبات كل قطعة، ومعاينة قبل الطباعة.',
            '20-large-personalized-photo-stickers' => '20 استيكر كبير مقاس 5×9 سم، مخصص باسم وصورة طفل واحد للكتب والكراسات والملفات. مقاوم للمياه والقطع، ومعاينة قبل الطباعة.',
            '10-personalized-heat-transfer-clothing-labels' => '10 استيكرات حرارية باسم طفل واحد لتمييز الملابس واليونيفورم. تحتاج معدات كبس حراري مناسبة للتطبيق، ومعاينة قبل الإنتاج.',
            '20-large-personalized-photo-stickers-4-7cm' => '20 استيكر وسط مقاس 4×7 سم، مخصص باسم وصورة طفل واحد للكتب والكراسات والملفات. مقاوم للمياه والقطع، ومعاينة قبل الطباعة.',
            'personalized-5-school-notebooks-a5' => '5 كراسات A5، لكل كراسة 30 صفحة داخلية وغلاف مخصص بصورة الطفل واسمه ومدرسته وفصله. المواد: العربي، الرياضيات أو Math، الدراسات، English، والعلوم أو Science. حدد تسمية Math أو Science في ملاحظات الطلب.',
            '30-personalized-lunchbox-love-cards' => '30 كارت رسائل حب وتشجيع باسم وصورة الطفل للانش بوكس أو الشنطة، مقاس 5.5×8.5 سم. 10 تصميمات وكل تصميم مطبوع 3 مرات. رسائل تشجيع مع لغز أو تحدٍ أو مهمة، بطباعة ملونة على وجه واحد.',
            'personalized-a4-spiral-notebook-60-pages' => 'كشكول سلك A4 بغلاف مخصص باسم الطفل وصورته، مع إمكانية إضافة المدرسة والفصل. يحتوي على 60 ورقة (120 صفحة). اختار سطور عربي أو English، وهو غير مخصص لمادة بعينها.',
        ];
        foreach ($descriptions as $slug => $description) {
            if ($product = Product::where('slug', $slug)->first()) {
                $features = collect($product->features ?? [])->reject(fn ($text) => preg_match('/توفير|وفر|السعر|جنيه|ج\.م/u', $text))->values()->all();
                if ($slug === 'school-sticker') {
                    $features = ['40 استيكر وسط +40 استيكر صغير', 'مخصص باسم وصورة الطفل', 'معاينة قبل الطباعة'];
                }
                if ($slug === 'bostr-mkhss-bsor-altfl-mkas-a4') {
                    $features = ['بوستر مع فريم', 'المقاس حسب اختيار الشراء', 'معاينة قبل الإنتاج'];
                }
                if ($slug === '30-personalized-lunchbox-love-cards') {
                    $features = ['30 كارت: 10 تصميمات ×3 نسخ', '5.5×8.5 سم', 'طباعة ملونة على وجه واحد'];
                }
                if ($slug === 'personalized-a4-spiral-notebook-60-pages') {
                    $features = ['60 ورقة (120 صفحة)', 'مقاس A4', 'سطور عربي أو English'];
                }
                if ($slug === '20-large-personalized-photo-stickers-4-7cm') {
                    $features = ['20 استيكر وسط', 'مقاس 4×7 سم', 'مقاوم للمياه والقطع'];
                }
                $set($product, ['short_description_ar' => $description, 'description_ar' => $description, 'seo_description_ar' => $description, 'features' => $features]);
            }
        }
        foreach (['bak-3-kss-mkhss', 'bak-5-kss-tloyn-mtahat'] as $slug) {
            if ($package = PricingPackage::where('slug', $slug)->first()) {
                $set($package, ['description' => 'اختار '.$package->story_count.' قصص مخصصة من القصص المتاحة في الباقة. بيانات طفل واحد لكل الباقة، مع معاينة قبل الطباعة.',
                    'features' => collect($package->features ?? [])->reject(fn ($text) => str_contains($text, 'لكل طفل') || str_contains($text, 'أطفال'))->push('بيانات طفل واحد لكل الباقة')->unique()->values()->all()]);
            }
        }
        $this->info(count($plans).' public-content updates planned. No selling prices, orders or payments are modified.');
        if (! $this->option('apply')) {
            foreach ($plans as [$model, $values]) {
                $this->line(class_basename($model).': '.($model->slug ?? $model->key ?? $model->question).' ['.implode(', ', array_keys($values)).']');
            }

            return self::SUCCESS;
        }
        $backup = 'private/robodesk-content/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $written = Storage::disk('local')->put($backup, json_encode(array_map(fn ($plan) => [
            'table' => $plan[0]->getTable(), 'id' => $plan[0]->getKey(), 'key' => $plan[0]->key,
            'before' => $plan[0]->only(array_keys($plan[1])), 'after' => $plan[1],
        ], $plans), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if (! $written) {
            $this->error('Backup failed; nothing changed.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($plans): void {
            foreach ($plans as [$model, $values]) {
                $model->fill($values)->save();
            }
            Setting::updateOrCreate(['key' => 'robodesk_content_revision'], ['value' => '2026-10-28-v1']);
        });
        $this->info('Applied. Private rollback record: '.$backup);

        return self::SUCCESS;
    }
}
