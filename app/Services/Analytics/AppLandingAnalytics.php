<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppLandingAnalytics
{
    public function report(Request $request): array
    {
        $request->validate([
            'range' => ['nullable', Rule::in(['today', 'yesterday', 'last_7_days', 'last_30_days', 'custom'])],
            'start_date' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d'],
        ]);
        $key = $request->query('range', 'last_7_days');
        $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
        [$start, $end] = match ($key) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'last_30_days' => [$today->subDays(29), $today],
            'custom' => [CarbonImmutable::parse($request->query('start_date'), 'Africa/Cairo'), CarbonImmutable::parse($request->query('end_date'), 'Africa/Cairo')],
            default => [$today->subDays(6), $today],
        };
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['end_date' => 'اختر فترة لا تتجاوز 366 يومًا.']);
        }
        $query = DB::table('app_landing_events')->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()]);
        $columns = "COUNT(DISTINCT visitor_hash) AS visitors,
            COALESCE(SUM(CASE WHEN event_type = 'view' THEN 1 ELSE 0 END), 0) AS views,
            COUNT(DISTINCT CASE WHEN event_type = 'ios_click' THEN visitor_hash END) AS ios_visitors,
            COUNT(DISTINCT CASE WHEN event_type = 'android_click' THEN visitor_hash END) AS android_visitors,
            COALESCE(SUM(CASE WHEN event_type = 'ios_click' THEN 1 ELSE 0 END), 0) AS ios_clicks,
            COALESCE(SUM(CASE WHEN event_type = 'android_click' THEN 1 ELSE 0 END), 0) AS android_clicks,
            COUNT(DISTINCT CASE WHEN event_type IN ('ios_click', 'android_click') THEN visitor_hash END) AS clicked_visitors";
        $summary = (array) (clone $query)->selectRaw($columns)->first();
        $grouped = (clone $query)->select('occurred_on')->selectRaw($columns)->groupBy('occurred_on')->get()->keyBy('occurred_on');
        $daily = [];
        for ($day = $end; $day->gte($start); $day = $day->subDay()) {
            $daily[] = ['date' => $day->toDateString(), ...(array) ($grouped[$day->toDateString()] ?? array_fill_keys(array_keys($summary), 0))];
        }

        return [
            'range' => $key, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'summary' => array_map('intval', $summary), 'daily' => $daily,
            'first_recorded_date' => DB::table('app_landing_events')->min('occurred_on'),
        ];
    }
}
