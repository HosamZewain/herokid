<?php

namespace Database\Seeders;

use App\Models\Testimonial;
use Illuminate\Database\Seeder;

class TestimonialSeeder extends Seeder
{
    public function run(): void
    {
        $testimonials = [
            [
                'reviewer_name'     => 'أم عمر',
                'reviewer_location' => 'القاهرة',
                'reviewer_avatar'   => 'https://i.pravatar.cc/80?img=47',
                'review_text'       => 'ابني عمر (٥ سنوات) بكى من الفرح لما شاف نفسه في الكتاب! كان يعيد قراءته كل ليلة ويقول "ماما أنا البطل". لم أتوقع هذا الأثر الكبير على ثقته بنفسه.',
                'rating'            => 5,
                'sort_order'        => 1,
                'active'            => true,
            ],
            [
                'reviewer_name'     => 'أبو لين',
                'reviewer_location' => 'الإسكندرية',
                'reviewer_avatar'   => 'https://i.pravatar.cc/80?img=12',
                'review_text'       => 'أهدينا كتاباً لابنتي لين في عيد ميلادها. كانت قصة الغابة وظهرت هي البطلة في كل صفحة. الجودة ممتازة والتوصيل سريع. سأطلب للأخ الصغير قريباً.',
                'rating'            => 5,
                'sort_order'        => 2,
                'active'            => true,
            ],
            [
                'reviewer_name'     => 'نهى محمد',
                'reviewer_location' => 'الجيزة',
                'reviewer_avatar'   => 'https://i.pravatar.cc/80?img=32',
                'review_text'       => 'خدمة راقية ومتعاملون محترمون. أرسلوا لي Preview قبل الطباعة وعدّلوا ملاحظاتي برحابة. الكتاب جاء بجودة أفضل مما توقعت. شكراً HeroKid!',
                'rating'            => 5,
                'sort_order'        => 3,
                'active'            => true,
            ],
            [
                'reviewer_name'     => 'خالد السيد',
                'reviewer_location' => 'المنصورة',
                'reviewer_avatar'   => 'https://i.pravatar.cc/80?img=15',
                'review_text'       => 'أروع هدية يمكن تقديمها لطفل. ابني يأخذ الكتاب معه في كل مكان ويعرض على كل من يقابله "ده أنا". أنصح كل أب وأم بتجربة HeroKid.',
                'rating'            => 5,
                'sort_order'        => 4,
                'active'            => true,
            ],
        ];

        foreach ($testimonials as $t) {
            Testimonial::firstOrCreate(
                ['reviewer_name' => $t['reviewer_name'], 'reviewer_location' => $t['reviewer_location']],
                $t
            );
        }
    }
}
