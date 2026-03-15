<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class StorySeeder extends Seeder
{
    public function run(): void
    {
        $stories = [
            // ===== Boys =====
            [
                'title'        => 'بطل الفضاء الصغير',
                'slug'         => 'little-space-hero',
                'short_desc'   => 'رحلة مشوقة في الفضاء الخارجي لاستكشاف النجوم والكواكب.',
                'full_desc'    => 'تبدأ القصة عندما يجد طفلك سفينة فضائية صغيرة في حديقة المنزل، وينطلق في رحلة لإنقاذ كوكب بعيد تعلمه الشجاعة وحل المشكلات.',
                'age_range'    => '٤ - ٨ سنوات',
                'language'     => 'ar',
                'gender'       => 'boy',
                'lesson_value' => 'الشجاعة والتعاون',
                'cover_image'  => 'https://images.unsplash.com/photo-1446776811953-b23d57bd21aa?w=800&auto=format&fit=crop&q=80',
                'active'       => true,
                'price'        => 99,
            ],
            [
                'title'        => 'المخترع العبقري',
                'slug'         => 'genius-inventor',
                'short_desc'   => 'قصة تلهم طفلك ليصبح عالماً ومخترعاً صغيراً.',
                'full_desc'    => 'يصنع طفلك آلة زمن صغيرة من أدوات منزلية ويسافر عبر الزمن لمقابلة علماء كبار ويتعلم منهم.',
                'age_range'    => '٦ - ١٠ سنوات',
                'language'     => 'ar',
                'gender'       => 'boy',
                'lesson_value' => 'الفضول العلمي والمثابرة',
                'cover_image'  => 'https://images.unsplash.com/photo-1581091226825-a6a2a5aee158?w=800&auto=format&fit=crop&q=80',
                'active'       => true,
                'price'        => 149,
            ],
            [
                'title'        => 'الفارس الشجاع والتنين اللطيف',
                'slug'         => 'brave-knight-gentle-dragon',
                'short_desc'   => 'بطولة غير تقليدية حيث الصداقة أقوى من السيوف.',
                'full_desc'    => 'يتم تكليف طفلك كفارس صغير بمحاربة تنين، لكنه يكتشف أن التنين يبحث فقط عن صديق ليلعب معه.',
                'age_range'    => '٣ - ٧ سنوات',
                'language'     => 'ar',
                'gender'       => 'boy',
                'lesson_value' => 'عدم الحكم على الآخرين من مظهرهم',
                'cover_image'  => 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=800&auto=format&fit=crop&q=80',
                'active'       => true,
                'price'        => 99,
            ],
            [
                'title'        => 'بطل ملعب كرة القدم',
                'slug'         => 'football-pitch-hero',
                'short_desc'   => 'مباراة حاسمة وطفلك هو نجم الفريق.',
                'full_desc'    => 'يتعلم طفلك أهمية اللعب الجماعي وكيف أن الفوز الحقيقي هو بالروح الرياضية والتعاون مع الزملاء.',
                'age_range'    => '٥ - ١٠ سنوات',
                'language'     => 'ar',
                'gender'       => 'boy',
                'lesson_value' => 'الروح الرياضية والعمل الجماعي',
                'cover_image'  => 'https://images.unsplash.com/photo-1575361204480-aadea25e6e68?w=800&auto=format&fit=crop&q=80',
                'active'       => true,
                'price'        => 149,
            ],

            // ===== Girls =====
            [
                'title'        => 'أميرة الحديقة السحرية',
                'slug'         => 'princess-magic-garden',
                'short_desc'   => 'مغامرة ساحرة في حديقة مليئة بالزهور الناطقة والفراشات المضيئة.',
                'full_desc'    => 'تكتشف طفلتك بذرة ذهبية تزرعها فتنبت حديقة سحرية فيها حيوانات صغيرة تحتاج مساعدتها لإنقاذ الربيع.',
                'age_range'    => '٣ - ٧ سنوات',
                'language'     => 'ar',
                'gender'       => 'girl',
                'lesson_value' => 'حب الطبيعة ومسؤولية العناية',
                'cover_image'  => 'https://images.unsplash.com/photo-1490750967868-88df5691cc4a?w=800&auto=format&fit=crop&q=80',
                'active'       => true,
                'price'        => 99,
            ],
            [
                'title'        => 'الطباخة الصغيرة',
                'slug'         => 'little-chef',
                'short_desc'   => 'وصفة سحرية تصنعها طفلتك لإسعاد عائلتها.',
                'full_desc'    => 'تجد طفلتك كتاب وصفات قديماً لجدتها، وتقرر تحضير حفل عشاء لكل العائلة مع مفاجآت لا تُنسى.',
                'age_range'    => '٤ - ٨ سنوات',
                'language'     => 'ar',
                'gender'       => 'girl',
                'lesson_value' => 'حب العائلة والإبداع',
                'cover_image'  => 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=800&auto=format&fit=crop&q=80',
                'active'       => true,
                'price'        => 99,
            ],

            // ===== Both =====
            [
                'title'        => 'مغامرة في الغابة السحرية',
                'slug'         => 'magic-forest-adventure',
                'short_desc'   => 'استكشف غابة مليئة بالحيوانات المتكلمة والأشجار المضيئة.',
                'full_desc'    => 'طفلك يكتشف خريطة قديمة تقوده إلى غابة سحرية حيث يساعد الحيوانات في العثور على ماء النبع الضائع.',
                'age_range'    => '٣ - ٦ سنوات',
                'language'     => 'ar',
                'gender'       => 'both',
                'lesson_value' => 'الرفق بالحيوان ومساعدة الآخرين',
                'cover_image'  => 'https://images.unsplash.com/photo-1448375240586-882707db888b?w=800&auto=format&fit=crop&q=80',
                'active'       => true,
                'price'        => 99,
            ],
            [
                'title'        => 'طبيب الحيوانات الأليفة',
                'slug'         => 'pet-doctor',
                'short_desc'   => 'قصة دافئة عن رعاية الكائنات الضعيفة والعطف عليها.',
                'full_desc'    => 'يحول طفلك غرفته إلى عيادة صغيرة ليعالج دبدوبه المريض وعصفوراً بكسر في جناحه، ويتعلم الرحمة.',
                'age_range'    => '٢ - ٦ سنوات',
                'language'     => 'ar',
                'gender'       => 'both',
                'lesson_value' => 'الرحمة والعطف على الضعفاء',
                'cover_image'  => 'https://images.unsplash.com/photo-1548199973-03cce0bbc87b?w=800&auto=format&fit=crop&q=80',
                'active'       => true,
                'price'        => 99,
            ],
        ];

        foreach ($stories as $story) {
            \App\Models\Story::firstOrCreate(['slug' => $story['slug']], $story);
        }
    }
}
