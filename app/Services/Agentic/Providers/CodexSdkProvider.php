<?php

namespace App\Services\Agentic\Providers;

use RuntimeException;

class CodexSdkProvider implements DecisionProvider
{
    public function name(): string
    {
        return 'codex';
    }

    public function health(): array
    {
        try {
            $health = $this->request(['operation' => 'health'], 3);
            $ready = ($health['ready'] ?? false) === true && ($health['authentication'] ?? null) === 'chatgpt'
                && ($health['isolation_verified'] ?? false) === true && ($health['tool_count'] ?? null) === 0;

            return ['ready' => $ready, 'reason' => $ready ? null : 'codex_isolation_or_authentication_unverified'];
        } catch (\Throwable) {
            return ['ready' => false, 'reason' => 'codex_bridge_unavailable'];
        }
    }

    public function decide(array $input): array
    {
        $response = $this->request(['operation' => 'decide', 'input' => $input], (int) config('agentic.timeout_seconds', 90));
        if (isset($response['error']) || ! isset($response['decision'], $response['metadata'])
            || ($response['metadata']['authentication'] ?? null) !== 'chatgpt' || ($response['metadata']['tools_used'] ?? null) !== 0) {
            throw new RuntimeException('codex_result_rejected');
        }

        return ['decision' => $response['decision'], 'metadata' => array_intersect_key($response['metadata'], array_flip([
            'provider', 'model', 'sdk_version', 'cli_version', 'authentication', 'tools_used', 'usage', 'isolation_digest',
        ]))];
    }

    private function request(array $payload, int $timeout): array
    {
        $socket = (string) config('agentic.bridge_socket');
        if (! str_starts_with($socket, '/') || str_contains($socket, '..') || strlen($socket) > 100) {
            throw new RuntimeException('invalid_bridge_socket');
        }
        $stream = @stream_socket_client('unix://'.$socket, $errno, $error, min(3, $timeout));
        if (! $stream) {
            throw new RuntimeException('codex_bridge_unavailable');
        }
        try {
            stream_set_timeout($stream, $timeout);
            $body = json_encode($payload, JSON_THROW_ON_ERROR)."\n";
            if (strlen($body) > config('agentic.max_context_bytes') || fwrite($stream, $body) !== strlen($body)) {
                throw new RuntimeException('codex_request_rejected');
            }
            $line = fgets($stream, 65537);
            if (! is_string($line) || ! str_ends_with($line, "\n") || stream_get_meta_data($stream)['timed_out']) {
                throw new RuntimeException('codex_result_uncertain');
            }
            $result = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($result)) {
                throw new RuntimeException('codex_result_rejected');
            }

            return $result;
        } finally {
            fclose($stream);
        }
    }
}
