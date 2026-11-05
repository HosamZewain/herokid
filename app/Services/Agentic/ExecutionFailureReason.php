<?php

namespace App\Services\Agentic;

use App\Exceptions\BookAdmissionException;
use App\Models\PuzzleGeneration;
use App\Services\ProductAutomation\ProductImageAdmission;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Exact allowlist: never persist exception text, SQL, provider replies or customer inputs. */
final class ExecutionFailureReason
{
    private const CODES = ['EXECUTION_SCOPE_EXCLUDED', 'EXECUTION_ATTESTATION_REQUIRED', 'EXECUTION_STALE_CONTEXT',
        'EXECUTION_POLICY_BLOCKED', 'EXECUTION_UNIT_REQUIRED', 'EXECUTION_MESSAGE_CHANGED', 'EXECUTION_ALREADY_RECORDED',
        'EXECUTION_DECISION_EXPIRED', 'EXISTING_AUTOMATION_OWNER', 'MANUAL_HOLD', 'AUTOMATION_PAUSED',
        'EXISTING_PRODUCT_ATTEMPT_REQUIRES_REVIEW', 'EXECUTOR_OR_STAGE_UNAVAILABLE'];

    public static function from(Throwable $error): string
    {
        if ($error instanceof BookAdmissionException) {
            return $error->reasonCode;
        }
        $message = $error->getMessage();
        if (in_array($message, [...self::CODES, ...ProductImageAdmission::REASONS], true)
            || array_key_exists($message, PuzzleGeneration::ERRORS)) {
            return $message;
        }
        if ($error instanceof LockTimeoutException) {
            return 'EXECUTION_LOCK_BUSY';
        }
        if ($error instanceof ModelNotFoundException) {
            return 'EXECUTION_REFERENCE_UNAVAILABLE';
        }
        if ($error instanceof QueryException && in_array((int) ($error->errorInfo[1] ?? 0), [1062, 1205, 1213], true)) {
            return (int) $error->errorInfo[1] === 1062 ? 'EXECUTION_CONCURRENT_WORK' : 'EXECUTION_LOCK_BUSY';
        }
        if ($error instanceof ValidationException) {
            return array_key_exists('codex_scenes', $error->errors()) ? 'STORY_EXECUTION_REQUIRES_REVIEW' : 'EXECUTOR_VALIDATION_FAILED';
        }

        return match ($message) {
            'Book approval unavailable', 'Book preview revoked' => 'BOOK_CURRENT_APPROVAL_REQUIRED',
            'Book inputs changed after approval' => 'BOOK_APPROVAL_INPUTS_CHANGED',
            'Book pages incomplete' => 'BOOK_PRODUCTION_BRIEF_INCOMPLETE',
            'Book production disabled' => 'BOOK_WORKER_UNAVAILABLE',
            'Book not eligible' => 'BOOK_STAGE_OR_EXISTING_WORK',
            'book_waiting_linked_story', 'book_story_pages_incomplete' => 'BOOK_LINKED_STORY_REQUIRED',
            'book_story_link_ambiguous', 'book_story_reference_changed', 'book_reference_checksum' => 'BOOK_REFERENCE_REQUIRES_REVIEW',
            'Automation paused' => 'AUTOMATION_PAUSED',
            'autopilot_unit_mismatch' => 'EXECUTION_UNIT_REQUIRED',
            default => 'EXECUTOR_PREFLIGHT_FAILED',
        };
    }

    /** Recheck permits a fresh decision only; no admission receipt or provider job is replayed. */
    public static function allowsRecheck(string $code): bool
    {
        return in_array($code, ['EXECUTOR_PREFLIGHT_FAILED', 'EXECUTOR_VALIDATION_FAILED', 'EXECUTION_LOCK_BUSY',
            'EXECUTION_CONCURRENT_WORK', 'EXECUTION_REFERENCE_UNAVAILABLE', 'BOOK_CURRENT_APPROVAL_REQUIRED',
            'BOOK_APPROVAL_INPUTS_CHANGED', 'BOOK_PRODUCTION_BRIEF_INCOMPLETE', 'BOOK_WORKER_UNAVAILABLE',
            'BOOK_STAGE_OR_EXISTING_WORK', 'BOOK_LINKED_STORY_REQUIRED', 'BOOK_REFERENCE_REQUIRES_REVIEW',
            'STORY_EXECUTION_REQUIRES_REVIEW', ...ProductImageAdmission::REASONS], true)
            || in_array($code, ['puzzle_no_usable_photos', 'puzzle_photos_required', 'puzzle_invalid_photo', 'puzzle_photo_missing',
                'puzzle_prompt_incomplete', 'puzzle_budget_required', 'puzzle_single_image_required', 'puzzle_book_child_requires_review',
                'puzzle_book_story_requires_review', 'puzzle_book_variant_requires_review', 'puzzle_book_structure_invalid', 'puzzle_ineligible'], true);
    }
}
