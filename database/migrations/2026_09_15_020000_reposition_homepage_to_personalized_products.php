<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Repositions the public homepage copy from "personalized stories" to
 * "personalized products for your child", with stories as the flagship line.
 *
 * Existing values are only rewritten when they still match the previous default,
 * so any copy the team has already customised in the admin is left untouched.
 */
return new class extends Migration
{
    /**
     * @return array<string, array{0: string, 1: string}> key => [old default, new value]
     */
    private function rewrites(): array
    {
        return [
            'seo_home_title' => [
                'قصص أطفال مخصصة تجعل طفلك بطل القصة بوجهه الحقيقي',
                'منتجات أطفال مخصصة بوجه طفلك واسمه — قصص وكتب أنشطة وهدايا',
            ],
            'seo_home_description' => [
                'HeroKid يحول طفلك إلى بطل قصة مطبوعة بوجهه واسمه. اختر القصة، أرسل صورة طفلك، واستلم كتاباً فاخراً.',
                'HeroKid يصنع منتجات مخصصة بوجه طفلك واسمه: قصص مطبوعة، كتب أنشطة، وهدايا. ارفع صور طفلك مرة واحدة واستخدمها في كل طلب.',
            ],
            'footer_brand_description' => [
                'قصص أطفال مخصصة تجعل طفلك بطل القصة بوجهه الحقيقي. نهدف لنشر الحب والقيم الجميلة عبر القصص المطبوعة.',
                'منتجات أطفال مخصصة بوجه طفلك واسمه الحقيقي: قصص، كتب أنشطة، وهدايا. نهدف لنشر الحب والقيم الجميلة بعيدًا عن الشاشات.',
            ],
            'home_badge_text' => [
                'أول قصة أطفال بوجه طفلك الحقيقي في مصر',
                'أول منتجات أطفال بوجه طفلك الحقيقي في مصر',
            ],
            'home_feature_values' => [
                'قصص بقيم تربوية',
                'قصص وأنشطة بقيم',
            ],
            'hero_title_1' => [
                'طفلك ليس قارئاً…',
                'كل حاجة لطفلك',
            ],
            'hero_title_2' => [
                'هو البطل الحقيقي!',
                'بوجهه واسمه الحقيقي',
            ],
            'hero_subtitle' => [
                'نحوّل خيال طفلك إلى كتاب مطبوع يحمل اسمه ووجهه الحقيقي في كل صفحة.',
                'قصص مخصصة، كتب أنشطة، وهدايا تحمل وجه طفلك واسمه. ارفع صوره مرة واحدة واستخدمها في كل منتج تطلبه له.',
            ],
            'home_child_identity_title' => [
                'اصنع هوية طفلك قبل اختيار القصة',
                'هوية واحدة لطفلك… تستخدمها في كل منتجاته',
            ],
            'home_child_identity_subtitle' => [
                'ارفع صور طفلك مرة واحدة، واحصل على هوية بصرية جاهزة لتختار بعدها القصة المناسبة له.',
                'ارفع صور طفلك مرة واحدة، واحصل على هوية بصرية جاهزة تستخدمها في القصص المخصصة وكتب الأنشطة والهدايا — بدون رفع الصور من جديد في كل طلب.',
            ],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->rewrites() as $key => [$oldDefault, $newValue]) {
            $existing = DB::table('settings')->where('key', $key)->first();

            if ($existing === null) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $newValue,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            if (trim((string) $existing->value) === $oldDefault) {
                DB::table('settings')->where('key', $key)->update([
                    'value' => $newValue,
                    'updated_at' => $now,
                ]);
            }
        }

        // New homepage section toggle: the product family strip under the hero.
        if (! DB::table('settings')->where('key', 'home_section_categories_enabled')->exists()) {
            DB::table('settings')->insert([
                'key' => 'home_section_categories_enabled',
                'value' => '1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Cache::forget('site_settings');
    }

    public function down(): void
    {
        $now = now();

        foreach ($this->rewrites() as $key => [$oldDefault, $newValue]) {
            DB::table('settings')
                ->where('key', $key)
                ->where('value', $newValue)
                ->update([
                    'value' => $oldDefault,
                    'updated_at' => $now,
                ]);
        }

        DB::table('settings')->where('key', 'home_section_categories_enabled')->delete();

        Cache::forget('site_settings');
    }
};
