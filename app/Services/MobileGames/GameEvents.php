<?php

namespace App\Services\MobileGames;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GameEvents
{
    public const MAX_DELAY_DAYS = 7;

    public const MAX_DURATION_MS = 14400000; // Four hours of foreground play.

    public function validate(array $event): ?string
    {
        $fields = ['id', 'name', 'occurred_at', 'game_id', 'round_id', 'level', 'rules_version', 'screen', 'duration_ms', 'moves', 'session_id'];
        if (array_diff(array_keys($event), $fields)) {
            return 'unknown_field';
        }
        $validator = Validator::make($event, [
            'id' => ['required', 'uuid'], 'name' => ['required', Rule::in(GameRegistry::EVENTS)],
            'occurred_at' => ['required', 'string', 'max:40', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,3})?(Z|[+-]\d{2}:\d{2})$/', 'date'],
            'game_id' => ['sometimes', 'required', Rule::in(array_keys(GameRegistry::definitions()))],
            'round_id' => ['sometimes', 'required', 'uuid'], 'session_id' => ['sometimes', 'required', 'uuid'],
            'level' => ['sometimes', 'required', 'integer'], 'rules_version' => ['sometimes', 'required', 'integer', 'between:1,1000000'],
            'screen' => ['sometimes', 'required', Rule::in(GameRegistry::SCREENS)],
            'duration_ms' => ['sometimes', 'required', 'integer', 'between:1,'.self::MAX_DURATION_MS],
            'moves' => ['sometimes', 'required', 'integer', 'between:1,100000'],
        ]);
        if ($validator->fails()) {
            return 'invalid_event';
        }
        $name = $event['name'];
        $game = str_starts_with($name, 'game_');
        $round = in_array($name, ['game_started', 'game_completed'], true);
        if ($game !== isset($event['game_id']) || ($name === 'screen_viewed') !== isset($event['screen'])
            || ($round && (! isset($event['round_id'], $event['level'], $event['rules_version'])))
            || (! $round && array_intersect(['round_id', 'level', 'rules_version'], array_keys($event)))
            || ($name !== 'game_completed' && array_intersect(['duration_ms', 'moves'], array_keys($event)))) {
            return 'invalid_fields_for_event';
        }
        if ($round && ! in_array((int) $event['level'], GameRegistry::definitions()[$event['game_id']][2], true)) {
            return 'unsupported_level';
        }
        $time = CarbonImmutable::parse($event['occurred_at'])->utc();
        if ($time->lt(now()->subDays(self::MAX_DELAY_DAYS)) || $time->gt(now()->addMinutes(5))) {
            return 'event_outside_window';
        }
        if ($round) {
            // Older rules remain reportable after an admin edit/offline sync.
            $current = collect(app(GameConfiguration::class)->current()['data']['games'])->firstWhere('id', $event['game_id']);
            if ($event['rules_version'] > $current['rules_version']) {
                return 'unsupported_rules_version';
            }
        }

        return null;
    }

    public function record(array $event, string $platform, string $appVersion): string
    {
        $time = CarbonImmutable::parse($event['occurred_at'])->utc();
        $data = ['id' => strtolower($event['id']), 'name' => $event['name'], 'platform' => $platform, 'app_version' => $appVersion,
            'game_id' => $event['game_id'] ?? null, 'level' => isset($event['level']) ? (int) $event['level'] : null,
            'rules_version' => isset($event['rules_version']) ? (int) $event['rules_version'] : null,
            'round_id' => isset($event['round_id']) ? strtolower($event['round_id']) : null,
            'session_id' => isset($event['session_id']) ? strtolower($event['session_id']) : null,
            'screen' => $event['screen'] ?? null, 'duration_ms' => isset($event['duration_ms']) ? (int) $event['duration_ms'] : null,
            'moves' => isset($event['moves']) ? (int) $event['moves'] : null, 'occurred_at' => $time->format('Y-m-d H:i:s.v')];
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $time, $fingerprint) {
            $inserted = DB::table('mobile_game_events')->insertOrIgnore($data + ['fingerprint' => $fingerprint, 'received_at' => now()]);
            if (! $inserted) {
                $existing = DB::table('mobile_game_events')->where('id', $data['id'])->first();
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new DomainException('event_id_conflict');
                }

                return 'duplicate';
            }
            if ($data['round_id']) {
                $dimensions = array_intersect_key($data, array_flip(['game_id', 'level', 'rules_version', 'platform', 'app_version']));
                DB::table('mobile_game_rounds')->insertOrIgnore(['id' => $data['round_id'], 'received_at' => now()] + $dimensions);
                $round = DB::table('mobile_game_rounds')->where('id', $data['round_id'])->lockForUpdate()->first();
                foreach ($dimensions as $key => $value) {
                    if ((string) $round->$key !== (string) $value) {
                        throw new DomainException('round_conflict');
                    }
                }
                $field = $data['name'] === 'game_started' ? 'started_at' : 'completed_at';
                if ($round->$field) {
                    // A new event UUID cannot count the same round twice.
                    return 'duplicate';
                }
                $update = [$field => $data['occurred_at'], 'received_at' => now()];
                if ($field === 'completed_at') {
                    $update += ['duration_ms' => $data['duration_ms'], 'moves' => $data['moves']];
                }
                DB::table('mobile_game_rounds')->where('id', $round->id)->update($update);
                $round = DB::table('mobile_game_rounds')->where('id', $round->id)->first();
                if ($round->started_at && $round->completed_at && ! $round->paired) {
                    $start = CarbonImmutable::parse($round->started_at, 'UTC');
                    $finish = CarbonImmutable::parse($round->completed_at, 'UTC');
                    if ($finish->gte($start)) {
                        $counts = ['matched_completed' => 1];
                        // Exclude impossible measurements from averages, never fabricate zeroes.
                        if ($round->duration_ms && $round->duration_ms <= $start->diffInMilliseconds($finish) + 5000) {
                            $counts += ['duration_sum' => $round->duration_ms, 'duration_count' => 1];
                        }
                        if ($round->moves) {
                            $counts += ['moves_sum' => $round->moves, 'moves_count' => 1];
                        }
                        $this->aggregate($dimensions, $start, $counts);
                        DB::table('mobile_game_rounds')->where('id', $round->id)->update(['paired' => true]);
                    }
                }
            }
            $this->session($data, $time);
            $this->aggregate($data, $time, [$data['name'] => 1]);

            return 'accepted';
        }, 5);
    }

    private function session(array $data, CarbonImmutable $time): void
    {
        if (! $data['session_id']) {
            return;
        }
        $dimensions = ['platform' => $data['platform'], 'app_version' => $data['app_version']];
        $new = DB::table('mobile_game_sessions')->insertOrIgnore(['id' => $data['session_id'], 'started_at' => $data['occurred_at'], 'latest_at' => $data['occurred_at'], 'received_at' => now()] + $dimensions);
        $session = DB::table('mobile_game_sessions')->where('id', $data['session_id'])->lockForUpdate()->first();
        $start = CarbonImmutable::parse($session->started_at, 'UTC')->min($time);
        $end = CarbonImmutable::parse($session->latest_at, 'UTC')->max($time);
        if ($session->platform !== $data['platform'] || $session->app_version !== $data['app_version'] || $start->diffInHours($end) > 24) {
            throw new DomainException('session_conflict');
        }
        if ($new) {
            $this->aggregate($dimensions, $time, ['sessions_started' => 1]);
        } elseif ($start->setTimezone('Africa/Cairo')->toDateString() !== CarbonImmutable::parse($session->started_at, 'UTC')->setTimezone('Africa/Cairo')->toDateString()) {
            $this->aggregate($dimensions, CarbonImmutable::parse($session->started_at, 'UTC'), ['sessions_started' => -1]);
            $this->aggregate($dimensions, $start, ['sessions_started' => 1]);
        }
        DB::table('mobile_game_sessions')->where('id', $session->id)->update(['started_at' => $start->format('Y-m-d H:i:s.v'), 'latest_at' => $end->format('Y-m-d H:i:s.v'), 'received_at' => now()]);
    }

    private function aggregate(array $dimensions, CarbonImmutable $time, array $counts): void
    {
        $key = ['day' => $time->setTimezone('Africa/Cairo')->toDateString(), 'platform' => $dimensions['platform'], 'app_version' => $dimensions['app_version'],
            'game_id' => $dimensions['game_id'] ?? '-', 'level' => $dimensions['level'] ?? 0, 'rules_version' => $dimensions['rules_version'] ?? 0];
        DB::table('mobile_game_daily')->insertOrIgnore($key + ['last_received_at' => now()]);
        // Database increments and unique dimensions make concurrent batches additive.
        $updates = ['last_received_at' => now()];
        foreach ($counts as $field => $value) {
            $updates[$field] = DB::raw($field.' + '.(int) $value);
        }
        DB::table('mobile_game_daily')->where($key)->update($updates);
    }
}
