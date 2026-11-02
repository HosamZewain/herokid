<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MobileGames\GameConfiguration;
use App\Services\MobileGames\GameRegistry;
use App\Services\MobileGames\GameReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileGamesController extends Controller
{
    public function index(GameConfiguration $configuration)
    {
        return view('admin.mobile-operations.games', [
            'config' => $configuration->current(), 'definitions' => GameRegistry::definitions(),
            'history' => DB::table('mobile_game_config_versions')->leftJoin('users', 'users.id', '=', 'changed_by')
                ->select('version', 'restored_from', 'mobile_game_config_versions.created_at', 'users.name as actor')
                ->orderByDesc('version')->paginate(20),
        ]);
    }

    public function update(Request $request, string $game, GameConfiguration $configuration)
    {
        $valid = $configuration->validateGame($game, $request->only(['enabled', 'sort_order', 'title', 'description', 'instructions', 'settings']));
        $request->validate(['remove_image' => ['sometimes', 'boolean'], 'image' => ['sometimes', 'file', 'max:5120']]);
        $configuration->save($request, function ($data) use ($request, $game, $valid, $configuration) {
            foreach ($data['games'] as &$item) {
                if ($item['id'] !== $game) {
                    continue;
                }
                $item = array_replace($item, $valid);
                if ($request->boolean('remove_image')) {
                    $item['card_image']['path'] = null;
                }
                if ($request->hasFile('image')) {
                    $item['card_image']['path'] = $configuration->image($request->file('image'));
                }
            }

            return $data;
        });

        return back()->with('success', 'تم حفظ إعدادات اللعبة.');
    }

    public function general(Request $request, GameConfiguration $configuration)
    {
        $valid = $request->validate(['games_enabled' => ['required', 'boolean'], 'games_position' => ['required', 'integer', 'between:1,99'], 'raw_retention_days' => ['required', 'integer', 'between:30,365']]);
        $valid = ['games_enabled' => (bool) $valid['games_enabled'], 'games_position' => (int) $valid['games_position'], 'raw_retention_days' => (int) $valid['raw_retention_days']];
        $configuration->save($request, fn ($data) => array_replace($data, $valid));

        return back()->with('success', 'تم حفظ إعدادات قسم الألعاب. سياسة الحذف تحتاج تفعيلًا تشغيليًا منفصلًا.');
    }

    public function restore(Request $request, int $version, GameConfiguration $configuration)
    {
        $row = DB::table('mobile_game_config_versions')->where('version', $version)->first();
        abort_unless($row, 404);
        $configuration->save($request, fn () => json_decode($row->data, true, flags: JSON_THROW_ON_ERROR), $version);

        return back()->with('success', 'تمت استعادة الإعدادات في إصدار جديد مع الاحتفاظ بالسجل.');
    }

    public function reports(Request $request, GameReports $reports)
    {
        return view('admin.mobile-operations.game-reports', $reports->read($request) + ['definitions' => GameRegistry::definitions()]);
    }
}
