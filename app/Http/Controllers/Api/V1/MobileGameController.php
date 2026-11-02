<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MobileGames\GameConfiguration;
use App\Services\MobileGames\GameEvents;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class MobileGameController extends Controller
{
    public function config(Request $request, GameConfiguration $config)
    {
        $data = $config->publicData();
        $response = response()->json(['data' => $data])->setEtag(hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)));
        $response->headers->set('Cache-Control', 'public, max-age=0, must-revalidate');
        $response->isNotModified($request);

        return $response;
    }

    public function events(Request $request, GameEvents $events)
    {
        $payload = $request->json()->all();
        $validator = Validator::make($payload, ['schema_version' => ['required', 'integer', Rule::in([1])],
            'app_version' => ['required', 'string', 'max:32', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}(?:[+-][A-Za-z0-9.-]{1,16})?$/'],
            'platform' => ['required', Rule::in(['ios', 'android'])], 'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*' => ['required', 'array'], 'events.*.id' => ['required', 'uuid']]);
        if (array_diff(array_keys($payload), ['schema_version', 'app_version', 'platform', 'events']) || $validator->fails() || ! array_is_list($payload['events'] ?? [])) {
            return response()->json(['error' => ['code' => 'schema_invalid']], 422);
        }
        $result = ['accepted_ids' => [], 'duplicate_ids' => [], 'rejected' => []];
        foreach ($payload['events'] as $event) {
            try {
                $code = $events->validate($event);
                if ($code) {
                    $result['rejected'][] = ['id' => $event['id'], 'code' => $code, 'retryable' => false];

                    continue;
                }
                $kind = $events->record($event, $payload['platform'], $payload['app_version']);
                $result[$kind === 'accepted' ? 'accepted_ids' : 'duplicate_ids'][] = $event['id'];
            } catch (DomainException $error) {
                $result['rejected'][] = ['id' => $event['id'], 'code' => $error->getMessage(), 'retryable' => false];
            } catch (Throwable) {
                // Do not log SQL/bindings, request bodies, tokens, headers or identifiers.
                Log::warning('Anonymous mobile game event storage unavailable.');
                $result['rejected'][] = ['id' => $event['id'], 'code' => 'temporarily_unavailable', 'retryable' => true];
            }
        }

        return response()->json(['data' => $result], 202)->header('Cache-Control', 'no-store');
    }
}
