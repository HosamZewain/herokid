<?php

namespace App\Exceptions;

use InvalidArgumentException;
use RuntimeException;

/** Preserve the native enrollment message while exposing a safe, specific reason. */
final class BookAdmissionException extends RuntimeException
{
    public const REASONS = ['BOOK_ORDER_UNAVAILABLE', 'MANUAL_HOLD', 'EXISTING_AUTOMATION_OWNER',
        'BOOK_ORDER_STAGE_REQUIRES_REVIEW', 'RECORDED_PAYMENT_REQUIRED', 'BOOK_ACTIVE_WORK',
        'BOOK_EXISTING_ATTEMPT', 'BOOK_LINKED_STORY_REQUIRED'];

    public function __construct(public readonly string $reasonCode)
    {
        if (! in_array($reasonCode, self::REASONS, true)) {
            throw new InvalidArgumentException('invalid_book_admission_reason');
        }
        parent::__construct('Book not eligible');
    }
}
