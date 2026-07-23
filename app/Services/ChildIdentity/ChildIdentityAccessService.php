<?php

namespace App\Services\ChildIdentity;

use App\Models\ChildIdentityRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChildIdentityAccessService
{
    public function issue(ChildIdentityRequest $identity, Request $request): string
    {
        $token = Str::random(80);
        $identity->forceFill(['resume_token_hash' => hash('sha256', $token)])->saveQuietly();
        $this->grant($identity, $request);

        return $token;
    }

    public function resume(ChildIdentityRequest $identity, string $token, Request $request): bool
    {
        if (! hash_equals((string) $identity->resume_token_hash, hash('sha256', $token))) {
            return false;
        }

        $this->grant($identity, $request);

        return true;
    }

    public function authorized(ChildIdentityRequest $identity, Request $request): bool
    {
        if ($identity->user_id && auth()->id() === $identity->user_id) {
            return true;
        }

        return in_array($identity->uuid, $request->session()->get('child_identity_grants', []), true);
    }

    public function grant(ChildIdentityRequest $identity, Request $request): void
    {
        $grants = collect($request->session()->get('child_identity_grants', []))
            ->push($identity->uuid)
            ->unique()
            ->take(-10)
            ->values()
            ->all();

        $request->session()->put('child_identity_grants', $grants);
    }
}
