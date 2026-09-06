<?php

namespace App\Services\RoboDesk;

use JsonException;

/**
 * Renders an admin-supplied JSON payload template against a set of variables.
 *
 * Placeholders are written as {{ name }}. A placeholder that is the entire
 * string value keeps the variable's native type ("{{ total }}" -> 12.5), which
 * matters because RoboDesk templates take ordered arrays and numeric fields.
 * Anywhere else the value is interpolated as text.
 */
class RoboDeskPayloadRenderer
{
    public function render(string $template, array $variables): array
    {
        $template = trim($template);

        if ($template === '') {
            return $variables;
        }

        try {
            $decoded = json_decode($template, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A malformed template must never take the queue down. The raw
            // variables still reach RoboDesk and the admin sees the bad
            // template flagged on the settings screen.
            return array_merge($variables, ['_template_error' => 'Payload template is not valid JSON.']);
        }

        if (! is_array($decoded)) {
            return $variables;
        }

        return (array) $this->walk($decoded, $variables);
    }

    public function placeholders(string $template): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', $template, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /** @return array<int,string> placeholders used but not offered by the action */
    public function unknownPlaceholders(string $template, array $available): array
    {
        return array_values(array_diff($this->placeholders($template), $available));
    }

    private function walk(mixed $node, array $variables): mixed
    {
        if (is_array($node)) {
            $result = [];

            foreach ($node as $key => $value) {
                $result[$key] = $this->walk($value, $variables);
            }

            return $result;
        }

        return is_string($node) ? $this->substitute($node, $variables) : $node;
    }

    private function substitute(string $value, array $variables): mixed
    {
        // Whole-value placeholder: preserve the variable's native type.
        if (preg_match('/^\{\{\s*([A-Za-z0-9_.]+)\s*\}\}$/', $value, $match) === 1) {
            return data_get($variables, $match[1]);
        }

        return preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/',
            function (array $match) use ($variables): string {
                $resolved = data_get($variables, $match[1]);

                if (is_array($resolved)) {
                    return implode(', ', array_map(fn ($item): string => (string) $item, $resolved));
                }

                return $resolved === null ? '' : (string) $resolved;
            },
            $value,
        ) ?? $value;
    }
}
