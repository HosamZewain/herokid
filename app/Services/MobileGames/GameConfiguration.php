<?php

namespace App\Services\MobileGames;

use App\Support\AdminActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GameConfiguration
{
    public function current(): array
    {
        $row = DB::table('mobile_game_configs')->find(1);

        return ['version' => (int) $row->version, 'updated_at' => $row->updated_at, 'data' => json_decode($row->data, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function publicData(): array
    {
        $config = $this->current();
        $data = $config['data'];
        $games = collect($data['games'])->sortBy('sort_order')->values()->map(function ($game) {
            $game['card_image'] = ['url' => $game['card_image']['path'] ? secure_url('storage/'.$game['card_image']['path']) : null,
                'version' => $game['card_image']['version']];

            return $game;
        })->all();

        return ['schema_version' => 1, 'config_version' => $config['version'], 'updated_at' => CarbonImmutable::parse($config['updated_at'], 'UTC')->toIso8601String(),
            'games_enabled' => $data['games_enabled'], 'games_position' => $data['games_position'], 'games' => $games];
    }

    public function validateGame(string $id, array $data): array
    {
        abort_unless(isset(GameRegistry::definitions()[$id]), 404);
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $rules = ['enabled' => ['required', 'boolean'], 'sort_order' => ['required', 'integer', 'between:1,99'],
            'settings' => ['required', 'array:enabled_levels,default_level,celebration_enabled,retry_feedback_enabled,sound_enabled,motion_enabled'.($id === 'memory' ? ',themes,default_theme' : '').($id === 'puzzle' ? ',scenes,default_scene' : '')],
            'settings.enabled_levels' => ['required', 'array', 'min:1', 'max:3'],
            'settings.enabled_levels.*' => ['required', 'integer', 'distinct', Rule::in(GameRegistry::definitions()[$id][2])],
            'settings.default_level' => ['required', 'integer', Rule::in(is_array($settings['enabled_levels'] ?? null) ? $settings['enabled_levels'] : [])],
        ];
        foreach (['title' => 80, 'description' => 240, 'instructions' => 1000] as $field => $limit) {
            $rules[$field] = ['required', 'array:ar,en'];
            foreach (['ar', 'en'] as $language) {
                $rules["$field.$language"] = ['required', 'string', "max:$limit", 'not_regex:/[<>]/u'];
            }
        }
        foreach (['celebration_enabled', 'retry_feedback_enabled', 'sound_enabled', 'motion_enabled'] as $field) {
            $rules["settings.$field"] = ['required', 'boolean'];
        }
        foreach (['memory' => ['themes', 'default_theme', ['animals', 'colors', 'letters']], 'puzzle' => ['scenes', 'default_scene', ['forest', 'space', 'ocean']]] as $game => [$list, $default, $values]) {
            if ($id !== $game) {
                continue;
            }
            $rules["settings.$list"] = ['required', 'array', 'min:1', 'max:3'];
            $rules["settings.$list.*"] = ['string', 'distinct', Rule::in($values)];
            $rules["settings.$default"] = ['required', 'string', Rule::in(is_array($settings[$list] ?? null) ? $settings[$list] : [])];
        }
        $valid = Validator::make($data, $rules)->validate();
        $valid['enabled'] = (bool) $valid['enabled'];
        $valid['sort_order'] = (int) $valid['sort_order'];
        $valid['settings']['enabled_levels'] = array_map('intval', $valid['settings']['enabled_levels']);
        sort($valid['settings']['enabled_levels']);
        $valid['settings']['default_level'] = (int) $valid['settings']['default_level'];
        foreach (['celebration_enabled', 'retry_feedback_enabled', 'sound_enabled', 'motion_enabled'] as $field) {
            $valid['settings'][$field] = (bool) $valid['settings'][$field];
        }

        return $valid;
    }

    public function save(Request $request, callable $change, ?int $restoredFrom = null): void
    {
        $version = $request->validate(['config_version' => ['required', 'integer', 'min:1']])['config_version'];
        DB::transaction(function () use ($request, $change, $restoredFrom, $version) {
            $row = DB::table('mobile_game_configs')->where('id', 1)->lockForUpdate()->first();
            abort_unless((int) $row->version === (int) $version, 409, 'الإعدادات اتغيرت بواسطة موظف آخر؛ حدّث الصفحة قبل الحفظ.');
            $before = json_decode($row->data, true, flags: JSON_THROW_ON_ERROR);
            $data = $change($before);
            foreach ($data['games'] as $index => &$game) {
                $old = $before['games'][$index];
                $game['card_image']['version'] = $old['card_image']['version'] + (int) ($old['card_image']['path'] !== $game['card_image']['path']);
                // Cosmetic and feedback changes never invalidate a saved round.
                $fields = ['enabled_levels', 'default_level', 'themes', 'default_theme', 'scenes', 'default_scene'];
                $game['rules_version'] = $old['rules_version'] + (int) (Arr::only($old['settings'], $fields) != Arr::only($game['settings'], $fields));
            }
            unset($game);
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            DB::table('mobile_game_configs')->where('id', 1)->update(['version' => $row->version + 1, 'data' => $json, 'updated_at' => now()]);
            DB::table('mobile_game_config_versions')->insert(['version' => $row->version + 1, 'data' => $json, 'changed_by' => $request->user()->id, 'restored_from' => $restoredFrom, 'created_at' => now()]);
            AdminActivityLogger::log('mobile.games.config_updated', 'تحديث إعدادات ألعاب الموبايل.', properties: ['config_version' => $row->version + 1, 'restored_from' => $restoredFrom], request: $request);
        });
    }

    public function image(UploadedFile $file): string
    {
        Validator::make(['image' => $file], ['image' => ['required', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp']])->validate();
        $bytes = file_get_contents($file->getRealPath());
        $size = @getimagesizefromstring($bytes);
        if (! $size || ! in_array($size['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || min($size[0], $size[1]) < 128 || max($size[0], $size[1]) > 6000 || $size[0] * $size[1] > 16000000) {
            throw ValidationException::withMessages(['image' => 'اختر صورة JPEG/PNG/WebP صالحة، من 128 وحتى 6000 بكسل وبحد أقصى 16 مليون بكسل.']);
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            throw ValidationException::withMessages(['image' => 'الصورة غير قابلة للقراءة.']);
        }
        // Contain without stretching; re-encoding removes EXIF, metadata and embedded payloads.
        $scale = min(1, 1024 / max($size[0], $size[1]));
        $w = max(1, (int) round($size[0] * $scale));
        $h = max(1, (int) round($size[1] * $scale));
        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $size[0], $size[1]);
        ob_start();
        $webp = function_exists('imagewebp');
        $webp ? imagewebp($canvas, null, 90) : imagepng($canvas, null, 6);
        $output = ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);
        $path = 'mobile-games/cards/'.Str::uuid().($webp ? '.webp' : '.png');
        if (! Storage::disk('public')->put($path, $output)) {
            throw new \RuntimeException('Game card storage failed');
        }

        return $path;
    }
}
