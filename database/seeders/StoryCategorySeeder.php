<?php

namespace Database\Seeders;

use App\Models\StoryCategory;
use Illuminate\Database\Seeder;

class StoryCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'مغامرات',         'slug' => 'adventures'],
            ['name' => 'حيوانات',          'slug' => 'animals'],
            ['name' => 'أبطال خارقون',     'slug' => 'superheroes'],
            ['name' => 'علوم واكتشاف',     'slug' => 'science'],
            ['name' => 'قيم وأخلاق',      'slug' => 'values'],
            ['name' => 'مهن',              'slug' => 'professions'],
            ['name' => 'طبيعة',           'slug' => 'nature'],
            ['name' => 'عائلة وأصدقاء',   'slug' => 'family'],
            ['name' => 'فضاء',            'slug' => 'space'],
            ['name' => 'رياضة',           'slug' => 'sports'],
        ];

        foreach ($categories as $cat) {
            StoryCategory::firstOrCreate(['slug' => $cat['slug']], $cat);
        }
    }
}
