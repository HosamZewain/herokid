<?php

namespace App\Services\AdminAssistant;

use RuntimeException;

/** Text classification only: the model receives no order records or report results. */
class AssistantProvider
{
    public function plan(array $input): array
    {
        $path = (string) config('admin_assistant.bridge_socket');
        if (! str_starts_with($path, '/') || str_contains($path, '..') || strlen($path) > 100 || $path === config('agentic.bridge_socket')) {
            throw new RuntimeException('assistant_socket_invalid');
        }
        $stream = @stream_socket_client('unix://'.$path, $errno, $error, 2);
        if (! $stream) {
            throw new RuntimeException('assistant_unavailable');
        }
        try {
            stream_set_timeout($stream, (int) config('admin_assistant.timeout_seconds', 30));
            $body = json_encode(['operation' => 'assistant_plan', 'input' => $input], JSON_THROW_ON_ERROR)."\n";
            if (strlen($body) > 16000 || fwrite($stream, $body) !== strlen($body)) {
                throw new RuntimeException('assistant_request_rejected');
            }
            $line = fgets($stream, 16385);
            if (! is_string($line) || ! str_ends_with($line, "\n") || stream_get_meta_data($stream)['timed_out']) {
                throw new RuntimeException('assistant_result_uncertain');
            }
            $result = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            if (($result['metadata']['authentication'] ?? null) !== 'chatgpt' || ($result['metadata']['tools_used'] ?? null) !== 0
                || ($result['metadata']['isolation_verified'] ?? null) !== true || ($result['metadata']['purpose'] ?? null) !== 'admin_assistant'
                || ! is_array($result['plan'] ?? null)) {
                throw new RuntimeException('assistant_result_rejected');
            }

            return $result['plan'];
        } finally {
            fclose($stream);
        }
    }
}
