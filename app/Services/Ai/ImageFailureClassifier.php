<?php

namespace App\Services\Ai;

/** Persist codes and a bounded decision, never provider text/customer data. */
final class ImageFailureClassifier
{
    public static function rejected(int $status, array $body): array
    {
        $safe = fn ($v) => is_string($v) && preg_match('/\A[A-Za-z0-9_.:\[\]-]{1,100}\z/', $v) ? $v : null;
        $code = $safe(data_get($body, 'error.code'));
        $type = $safe(data_get($body, 'error.type'));
        $param = $safe(data_get($body, 'error.param'));
        // Text is inspected only in memory to identify the failure class. It is
        // never returned, logged, stored, or used to rewrite a rejected prompt.
        $message = data_get($body, 'error.message');
        $diagnostic = strtolower(implode(' ', [$code, $type, is_string($message) ? substr($message, 0, 4000) : '']));
        $category = match (true) {
            preg_match('/safety|content.?policy|moderation|guardrail/', $diagnostic) === 1 => 'content_refusal',
            preg_match('/billing|quota|credit.?balance/', $diagnostic) === 1 => 'billing',
            in_array($status, [401, 403], true) => 'credentials',
            $status === 429 => 'rate_limit',
            in_array($status, [400, 422], true) && preg_match('/invalid image file|unsupported image|image.*mode|invalid_image/', $diagnostic) === 1 => 'reference_format',
            in_array($status, [400, 404], true) && preg_match('/model.*(not found|does not exist|not supported|access)|access.*model/', $diagnostic) === 1 => 'model_configuration',
            in_array($status, [400, 404, 422], true) => 'request_rejected',
            default => 'result_uncertain',
        };

        return ['version' => 1, 'category' => $category, 'provider_code' => $code,
            'provider_type' => $type, 'provider_param' => $param, 'http_status' => $status,
            'definitively_rejected' => in_array($status, [400, 401, 403, 404, 422, 429], true)];
    }
}
