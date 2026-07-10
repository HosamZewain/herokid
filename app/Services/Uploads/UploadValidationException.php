<?php

namespace App\Services\Uploads;

use RuntimeException;

class UploadValidationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 422,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }
}
