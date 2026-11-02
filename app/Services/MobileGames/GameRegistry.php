<?php

namespace App\Services\MobileGames;

class GameRegistry
{
    public const SCREENS = ['home', 'games', 'library', 'catalog', 'settings'];

    public const EVENTS = ['app_opened', 'screen_viewed', 'game_opened', 'game_started', 'game_completed'];

    public static function definitions(): array
    {
        return [
            'memory' => ['لعبة الذاكرة', 'Memory game', [3, 6, 8], 'اقلب الكروت ولاقي كل اتنين شبه بعض', 'Flip cards and match each pair.'],
            'puzzle' => ['البازل', 'Puzzle', [4, 9, 16], 'ركّب القطع علشان تكمل الصورة', 'Arrange the pieces to complete the picture.'],
            'differences' => ['اكتشف الاختلافات', 'Spot the differences', [1, 2, 3], 'دور على الاختلافات بين الصورتين', 'Find the differences between the pictures.'],
            'hidden_objects' => ['الحاجة المستخبية', 'Hidden objects', [1, 2, 3], 'دور على الأشياء المستخبية', 'Find the hidden objects.'],
            'patterns' => ['كمّل النمط', 'Complete the pattern', [1, 2, 3], 'اختار الشكل اللي يكمّل النمط', 'Choose the shape that completes the pattern.'],
            'sequence' => ['رتّب الحكاية', 'Story sequence', [1, 2, 3], 'رتب الصور علشان تحكي الحكاية', 'Put the pictures in story order.'],
            'sounds' => ['محقق الأصوات', 'Sound detective', [1, 2, 3], 'اسمع الصوت واختار الإجابة', 'Listen and choose the matching answer.'],
            'problem_solving' => ['حل المشكلة', 'Problem solving', [1, 2, 3], 'فكر واختار حل للموقف', 'Think and choose a solution.'],
            'maze' => ['متاهة الحكاية', 'Story maze', [1, 2, 3], 'وصل لنهاية المتاهة', 'Find the way through the maze.'],
        ];
    }

    public static function defaults(): array
    {
        $games = [];
        foreach (self::definitions() as $id => [$ar, $en, $levels, $instructionsAr, $instructionsEn]) {
            $settings = ['enabled_levels' => $levels, 'default_level' => $levels[0], 'celebration_enabled' => true,
                'retry_feedback_enabled' => true, 'sound_enabled' => true, 'motion_enabled' => true];
            if ($id === 'memory') {
                $settings += ['themes' => ['animals', 'colors', 'letters'], 'default_theme' => 'animals'];
            }
            if ($id === 'puzzle') {
                $settings += ['scenes' => ['forest', 'space', 'ocean'], 'default_scene' => 'forest'];
            }
            $games[] = ['id' => $id, 'enabled' => true, 'sort_order' => count($games) + 1,
                'title' => ['ar' => $ar, 'en' => $en], 'description' => ['ar' => $ar, 'en' => $en],
                'instructions' => ['ar' => $instructionsAr, 'en' => $instructionsEn],
                'card_image' => ['path' => null, 'version' => 1], 'rules_version' => 1, 'settings' => $settings];
        }

        return ['games_enabled' => true, 'games_position' => 1, 'raw_retention_days' => 90, 'games' => $games];
    }
}
