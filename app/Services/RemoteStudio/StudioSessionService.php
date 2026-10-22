<?php

namespace App\Services\RemoteStudio;

use App\Models\StudioSession;
use App\Models\User;
use App\Services\AgentApi\AgentTokenService;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class StudioSessionService
{
    public const ABILITY = 'studio:session';

    public const EMPLOYEE_PERMISSIONS = 'studio:employee-permissions';

    public function issue(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            // Serialize login with grant edits and cap live desktop sessions.
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($user->hasPermission('studio.access'), 403);
            $grant = DB::table('studio_employee_grants')->where('user_id', $user->id)->first();
            $source = $this->source($user, $grant?->agent_token_id);
            // New employees use their existing admin permissions directly. An
            // explicit legacy grant (including a revoked/disabled one) remains
            // restrictive: never turn a missing source into unrestricted access.
            $abilities = $grant === null
                ? [self::ABILITY, self::EMPLOYEE_PERMISSIONS, ...$this->employeeAbilities($user)]
                : [self::ABILITY, ...$this->agentAbilities($user, $source)];
            $expires = now()->addMinutes((int) config('remote_studio.session_minutes'));
            if ($source?->expires_at && $source->expires_at->lt($expires)) {
                $expires = $source->expires_at;
            }
            $issued = $user->createToken('HeroKid Studio employee session', $abilities, $expires);
            $session = StudioSession::create([
                'user_id' => $user->id, 'personal_access_token_id' => $issued->accessToken->id,
                'agent_token_id' => $source?->id, 'grant_version' => $grant?->version ?? 0,
                'policy_fingerprint' => $source ? hash('sha256', json_encode($source->abilities)) : null,
                'password_fingerprint' => hash('sha256', $user->getAuthPassword()),
                'created_at' => now(), 'expires_at' => $expires,
            ]);
            $old = StudioSession::where('user_id', $user->id)->whereNull('revoked_at')->where('expires_at', '>', now())->latest('id')->skip(5)->take(100)->get();
            foreach ($old as $previous) {
                $this->revoke($previous);
            }
            AdminActivityLogger::log('studio.login', properties: ['session_id' => $session->id], admin: $user);

            return ['token' => $issued->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toIso8601String(), 'refresh_token' => null, ...$this->identity($user, $issued->accessToken)];
        });
    }

    public function identity(User $user, PersonalAccessToken $token): array
    {
        return ['user' => ['id' => $user->id, 'name' => $user->name],
            'permissions' => $user->permissionKeys()->filter(fn ($key) => str_starts_with($key, 'studio.') || str_starts_with($key, 'orders.'))->values()->all(),
            'abilities' => array_values($token->abilities), 'expires_at' => $token->expires_at?->toIso8601String()];
    }

    public function validate(User $user, PersonalAccessToken $token): StudioSession
    {
        $session = StudioSession::where('personal_access_token_id', $token->id)->first();
        abort_unless($session && (int) $session->user_id === (int) $user->id && ! $session->revoked_at
            && $session->expires_at->isFuture() && $token->expires_at?->isFuture(), 401, 'Studio session expired or revoked.');
        abort_unless($user->hasPermission('studio.access'), 403, 'Studio access is not permitted.');
        abort_unless(hash_equals($session->password_fingerprint, hash('sha256', $user->getAuthPassword())), 401, 'Please sign in again.');
        $grant = DB::table('studio_employee_grants')->where('user_id', $user->id)->first();
        abort_unless((int) $session->grant_version === (int) ($grant?->version ?? 0), 401, 'Studio access policy changed.');
        if (in_array(self::EMPLOYEE_PERMISSIONS, $token->abilities, true)) {
            abort_unless($grant === null && $session->agent_token_id === null, 401, 'Studio access policy changed.');
            // Re-check on every API request and queued cover job. Existing
            // sessions can narrow immediately but cannot gain new abilities.
            $token->abilities = array_values(array_intersect($token->abilities, [self::ABILITY, self::EMPLOYEE_PERMISSIONS, ...$this->employeeAbilities($user)]));
        }
        if ($session->agent_token_id) {
            $source = $this->source($user, $session->agent_token_id);
            abort_unless($source, 401, 'Studio production policy was revoked.');
            abort_unless(hash_equals($session->policy_fingerprint, hash('sha256', json_encode($source->abilities))), 401, 'Studio production policy changed. Please sign in again.');
            // Never widen an existing session when permissions change. Narrow immediately.
            $token->abilities = array_values(array_intersect($token->abilities, [self::ABILITY, ...$this->agentAbilities($user, $source)]));
        }

        return $session;
    }

    public function source(User $user, mixed $id): ?PersonalAccessToken
    {
        if (! $id || ! $user->agent_api_enabled) {
            return null;
        }
        $source = $user->tokens()->find($id);

        return $source && $this->isPolicySource($source) ? $source : null;
    }

    /** Used by the admin picker and session validation; never expose the token secret. */
    public function isPolicySource(PersonalAccessToken $source): bool
    {
        if ($source->expires_at && $source->expires_at->isPast()) {
            return false;
        }
        $abilities = $source->abilities ?? [];

        // Wildcard and other Studio sessions are not production policy sources.
        return in_array('agent', $abilities, true) && ! in_array('*', $abilities, true)
            && ! in_array(self::ABILITY, $abilities, true);
    }

    private function agentAbilities(User $user, ?PersonalAccessToken $source): array
    {
        if (! $source) {
            return [];
        }
        $abilities = ['agent'];
        foreach (AgentTokenService::ABILITY_DEFINITIONS as $ability => $definition) {
            if (in_array($ability, $source->abilities, true) && $user->hasAllPermissions($definition['permissions'])) {
                $abilities[] = $ability;
            }
        }
        // Preserve the exact existing catalog restrictions, including legacy absence.
        foreach ($source->abilities as $ability) {
            if (in_array($ability, ['agent:catalog.stories', 'agent:catalog.products'], true)
                || preg_match('/^agent:catalog\.product\.[1-9][0-9]*$/D', $ability)) {
                $abilities[] = $ability;
            }
        }

        return array_values(array_unique($abilities));
    }

    private function employeeAbilities(User $user): array
    {
        $abilities = [];
        foreach (AgentTokenService::ABILITY_DEFINITIONS as $ability => $definition) {
            // Routine production only. Identity queues and rework remain opt-in
            // through existing restricted Agent policies.
            if ($definition['mode'] === 'operation' && $user->hasAllPermissions($definition['permissions'])) {
                $abilities[] = $ability;
            }
        }

        return $abilities === [] ? [] : ['agent', ...$abilities, 'agent:catalog.stories', 'agent:catalog.products'];
    }

    public function revoke(StudioSession $session): void
    {
        $session->forceFill(['revoked_at' => now()])->save();
        PersonalAccessToken::whereKey($session->personal_access_token_id)->delete();
    }
}
