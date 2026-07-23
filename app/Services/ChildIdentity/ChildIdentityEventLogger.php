<?php

namespace App\Services\ChildIdentity;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\AdminActivityLogger;

class ChildIdentityEventLogger
{
    public function record(
        ChildIdentityRequest $request,
        string $type,
        ?string $description = null,
        array $metadata = [],
        ?ChildIdentityGenerationAttempt $attempt = null,
        ?Order $order = null,
        ?User $actor = null,
        string $actorType = 'customer',
        string $source = 'web',
        ?string $fromStatus = null,
        ?string $toStatus = null,
    ): void {
        $safeMetadata = AdminActivityLogger::sanitize($metadata);
        unset($safeMetadata['resume_token'], $safeMetadata['token'], $safeMetadata['path']);

        $request->events()->create([
            'child_identity_generation_attempt_id' => $attempt?->id,
            'order_id' => $order?->id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'source' => $source,
            'event_type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'description' => $description,
            'metadata' => $safeMetadata,
        ]);

        $request->forceFill(['last_activity_at' => now()])->saveQuietly();
    }
}
