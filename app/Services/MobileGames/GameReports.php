<?php

namespace App\Services\MobileGames;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GameReports
{
    public function read(Request $request): array
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'platform' => ['nullable', Rule::in(['ios', 'android'])], 'app_version' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z.+-]+$/'],
            'game_id' => ['nullable', Rule::in(array_keys(GameRegistry::definitions()))], 'level' => ['nullable', 'integer', Rule::in([1, 2, 3, 4, 6, 8, 9, 16])],
        ]);
        $filters['from'] = $filters['from'] ?? now('Africa/Cairo')->subDays(29)->toDateString();
        $filters['to'] = $filters['to'] ?? now('Africa/Cairo')->toDateString();
        $from = CarbonImmutable::parse($filters['from'], 'Africa/Cairo');
        $to = CarbonImmutable::parse($filters['to'], 'Africa/Cairo');
        if ($to->lt($from) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => 'اختر فترة مرتبة لا تتجاوز 366 يومًا.']);
        }
        if (! empty($filters['game_id']) && ! empty($filters['level']) && ! in_array((int) $filters['level'], GameRegistry::definitions()[$filters['game_id']][2], true)) {
            throw ValidationException::withMessages(['level' => 'المستوى غير متاح لهذه اللعبة.']);
        }
        $base = DB::table('mobile_game_daily')->whereBetween('day', [$filters['from'], $filters['to']]);
        foreach (['platform', 'app_version'] as $field) {
            if (! empty($filters[$field])) {
                $base->where($field, $filters[$field]);
            }
        }
        // App/session metrics are explicitly independent of game and level filters.
        $activity = (clone $base)->where('game_id', '-')->selectRaw('SUM(app_opened) as app_opened, SUM(screen_viewed) as screen_viewed, SUM(sessions_started) as sessions_started')->first();
        $games = (clone $base)->where('game_id', '!=', '-');
        foreach (['game_id', 'level'] as $field) {
            if (! empty($filters[$field])) {
                $games->where($field, $filters[$field]);
            }
        }
        $sums = implode(', ', array_map(fn ($field) => "SUM($field) as $field", ['game_opened', 'game_started', 'game_completed', 'matched_completed', 'duration_sum', 'duration_count', 'moves_sum', 'moves_count']));
        $totals = (clone $games)->selectRaw($sums)->first();
        $comparison = (clone $games)->select('game_id', 'level')->selectRaw($sums)->groupBy('game_id', 'level')->orderBy('game_id')->orderBy('level')->get();
        $daily = (clone $games)->select('day')->selectRaw($sums)->groupBy('day')->orderBy('day')->get()->keyBy('day');
        $days = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $days[] = ['day' => $day->toDateString(), 'started' => (int) ($daily->get($day->toDateString())->game_started ?? 0), 'completed' => (int) ($daily->get($day->toDateString())->matched_completed ?? 0)];
        }

        return compact('filters', 'activity', 'totals', 'comparison', 'days') + ['lastReceived' => (clone $games)->max('last_received_at')];
    }
}
