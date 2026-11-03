<?php

namespace App\Services\Agentic;

final class ContextFingerprint
{
    public static function hash(array $context): string
    {
        unset($context['context_version'], $context['fingerprint'], $context['observed_at'], $context['operational']);
        // Intermediate image collection updates the dashboard, not the next action.
        foreach ($context['units'] ?? [] as $index => $unit) {
            if ($unit['production']['active'] ?? false) {
                $context['units'][$index]['production']['completed_numbers'] = [];
                $context['units'][$index]['production']['missing_numbers'] = [];
                $context['units'][$index]['output_count'] = 0;
            }
        }

        return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function privateInputs(mixed $data): string
    {
        // Low-entropy names/notes must not become dictionary-guessable public hashes.
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (string) config('app.key'));
    }
}
