<?php

namespace App\Services\ChildIdentity;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareEventService;
use App\Support\AdminActivityLogger;

class ChildIdentityEventLogger
{
    public function __construct(private readonly ChildIdentityShareEventService $shareEvents) {}

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
        $this->mirrorShareFunnel($request, $type, $order);
    }

    private function mirrorShareFunnel(ChildIdentityRequest $identity, string $identityEventType, ?Order $order): void
    {
        $shareEventType = match ($identityEventType) {
            'request.created' => 'share.identity_started',
            'photos.batch_uploaded' => 'share.photos_uploaded',
            'generation.succeeded' => 'share.identity_generated',
            'attempt.approved', 'attempt.approved_by_admin' => 'share.identity_approved',
            'story.selected' => 'share.story_selected',
            'cart.added' => 'share.checkout_started',
            'request.converted' => 'share.order_created',
            default => null,
        };

        if (! $shareEventType) {
            return;
        }

        $share = $identity->referredByShare()->withTrashed()->first();

        if ($share) {
            $this->shareEvents->recordFunnelOnce($share, $shareEventType, $identity, $order);
        }
    }
}
