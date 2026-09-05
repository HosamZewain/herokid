<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AgentApi\AgentCatalogScope;
use App\Services\AgentApi\AgentTokenService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

class AgentApiTokenController extends Controller
{
    public function index(): View
    {
        $agents = User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->with('tokens')
            ->orderBy('name')
            ->get();

        $tokens = $agents->flatMap(fn (User $agent) => $agent->tokens
            ->filter(fn (PersonalAccessToken $token): bool => in_array('agent', $token->abilities ?? [], true))
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'agent' => $agent,
                'scope' => AgentCatalogScope::fromAbilities($token->abilities ?? []),
                'can_rework' => in_array('agent:orders.rework', $token->abilities ?? [], true)
                    && in_array('agent:orders.edit-personalization', $token->abilities ?? [], true),
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ]))
            ->sortByDesc('created_at')
            ->values();

        return view('admin.agent-api-tokens.index', compact('agents', 'tokens'));
    }

    public function store(Request $request, AgentTokenService $tokens): RedirectResponse
    {
        $validated = $request->validate([
            'agent_user_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:365'],
            'catalog_scope' => ['required', 'in:all,stories,products'],
            'allow_rework' => ['nullable', 'boolean'],
        ]);

        $agent = User::query()->findOrFail($validated['agent_user_id']);
        $token = $tokens->issue(
            $agent,
            trim($validated['name']),
            (int) $validated['expires_in_days'],
            $validated['catalog_scope'],
            (bool) ($validated['allow_rework'] ?? false),
        );

        AdminActivityLogger::log(
            action: 'agent_api.token_issued',
            description: 'تم إنشاء Agent API Token.',
            subject: $agent,
            properties: [
                'agent_user_id' => $agent->id,
                'token_id' => $token->accessToken->id,
                'token_name' => $token->accessToken->name,
                'catalog_scope' => $validated['catalog_scope'],
                'allow_rework' => (bool) ($validated['allow_rework'] ?? false),
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            ],
            request: $request,
        );

        return redirect()->route('admin.agent-api-tokens.index')
            ->with('success', 'تم إنشاء التوكن. انسخه الآن لأنه لن يظهر مرة أخرى.')
            ->with('new_agent_token', $token->plainTextToken);
    }

    public function destroy(Request $request, PersonalAccessToken $token, AgentTokenService $tokens): RedirectResponse
    {
        abort_unless($token->tokenable_type === User::class, 404);

        $agent = User::query()->where('role', 'admin')->findOrFail($token->tokenable_id);
        $metadata = [
            'agent_user_id' => $agent->id,
            'token_id' => $token->id,
            'token_name' => $token->name,
            'catalog_scope' => AgentCatalogScope::fromAbilities($token->abilities ?? []),
            'allow_rework' => in_array('agent:orders.rework', $token->abilities ?? [], true),
        ];

        $tokens->revoke($agent, $token);

        AdminActivityLogger::log(
            action: 'agent_api.token_revoked',
            description: 'تم إلغاء Agent API Token.',
            subject: $agent,
            properties: $metadata,
            request: $request,
        );

        return back()->with('success', 'تم إلغاء التوكن فورًا.');
    }
}
