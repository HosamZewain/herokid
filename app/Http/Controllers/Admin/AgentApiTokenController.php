<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\AgentApi\AgentCatalogScope;
use App\Services\AgentApi\AgentProductScope;
use App\Services\AgentApi\AgentTokenService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
                'identity_only' => in_array('agent:orders.identity', $token->abilities ?? [], true),
                'product_ids' => AgentProductScope::productIdsFromAbilities($token->abilities ?? []),
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ]))
            ->sortByDesc('created_at')
            ->values();

        $products = Product::query()
            ->where('is_active', true)
            ->whereNotNull('production_prompt_template')
            ->where('production_prompt_template', '!=', '')
            ->orderBy('name_ar')
            ->orderBy('id')
            ->get(['id', 'name_ar', 'name_en', 'slug', 'sku']);
        $productsById = Product::query()
            ->whereIn('id', $tokens->pluck('product_ids')->flatten()->unique())
            ->get(['id', 'name_ar', 'name_en', 'slug', 'sku'])
            ->keyBy('id');

        $tokens = $tokens->map(function (array $token) use ($productsById): array {
            $token['allowed_products'] = collect($token['product_ids'])
                ->map(fn (int $id): string => ($productsById->get($id)?->name_ar ?: $productsById->get($id)?->name_en ?: $productsById->get($id)?->slug) ?? '#'.$id)
                ->all();

            return $token;
        });

        return view('admin.agent-api-tokens.index', compact('agents', 'tokens', 'products'));
    }

    public function store(Request $request, AgentTokenService $tokens): RedirectResponse
    {
        $validated = $request->validate([
            'agent_user_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:365'],
            'catalog_scope' => ['required', 'in:all,stories,products'],
            'allow_rework' => ['nullable', 'boolean'],
            'identity_only' => ['nullable', 'boolean'],
            'restrict_products' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array', 'max:50'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
        ]);

        $restrictProducts = (bool) ($validated['restrict_products'] ?? false);
        $productIds = $restrictProducts ? array_values($validated['product_ids'] ?? []) : [];
        if ($restrictProducts && $validated['catalog_scope'] !== AgentCatalogScope::PRODUCTS) {
            throw ValidationException::withMessages(['catalog_scope' => 'اختر نطاق المنتجات فقط عند تقييد التوكن بمنتجات محددة.']);
        }
        if ($restrictProducts && $productIds === []) {
            throw ValidationException::withMessages(['product_ids' => 'اختر منتجًا واحدًا على الأقل.']);
        }

        $agent = User::query()->findOrFail($validated['agent_user_id']);
        $token = $tokens->issue(
            $agent,
            trim($validated['name']),
            (int) $validated['expires_in_days'],
            $validated['catalog_scope'],
            (bool) ($validated['allow_rework'] ?? false),
            (bool) ($validated['identity_only'] ?? false),
            $productIds,
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
                'identity_only' => (bool) ($validated['identity_only'] ?? false),
                'allowed_product_ids' => $productIds,
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
            'identity_only' => in_array('agent:orders.identity', $token->abilities ?? [], true),
            'allowed_product_ids' => AgentProductScope::productIdsFromAbilities($token->abilities ?? []),
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
