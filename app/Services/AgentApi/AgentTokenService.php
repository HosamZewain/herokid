<?php

namespace App\Services\AgentApi;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AgentTokenService
{
    public const OPERATION_ABILITIES = [
        'agent',
        'agent:orders.read',
        'agent:orders.acquire',
        'agent:orders.update-status',
        'agent:orders.upload-attachment',
        'agent:orders.upload-preview',
    ];

    public const REWORK_ABILITIES = [
        'agent:orders.edit-personalization',
        'agent:orders.rework',
    ];

    public const IDENTITY_ONLY_ABILITIES = [
        'agent',
        'agent:orders.identity',
    ];

    public const REQUIRED_PERMISSIONS = [
        'orders.view',
        'orders.assign',
        'orders.update',
        'orders.photos.view',
        'orders.preview.upload',
    ];

    public function issue(
        User $agent,
        string $name,
        int $expiresInDays,
        string $catalogScope,
        bool $allowRework = false,
        bool $identityOnly = false,
    ): NewAccessToken {
        if (! $agent->isAdmin()) {
            throw ValidationException::withMessages(['agent_user_id' => 'يجب اختيار حساب مشرف نشط ومخصص للـAgent.']);
        }

        if (! in_array($catalogScope, [AgentCatalogScope::ALL, AgentCatalogScope::STORIES, AgentCatalogScope::PRODUCTS], true)) {
            throw ValidationException::withMessages(['catalog_scope' => 'نطاق المنتجات المحدد غير صحيح.']);
        }

        if ($identityOnly && $catalogScope !== AgentCatalogScope::STORIES) {
            throw ValidationException::withMessages(['catalog_scope' => 'توكن هويات القصص فقط يجب أن يكون نطاقه القصص فقط.']);
        }

        if ($identityOnly && $allowRework) {
            throw ValidationException::withMessages(['allow_rework' => 'لا يمكن جمع وضع هويات القصص فقط مع صلاحية إعادة الإنتاج.']);
        }

        $permissionIds = Permission::query()->whereIn('key', self::REQUIRED_PERMISSIONS)->pluck('id');
        if ($permissionIds->count() !== count(self::REQUIRED_PERMISSIONS)) {
            throw ValidationException::withMessages(['agent_user_id' => 'صلاحيات الطلبات المطلوبة غير مكتملة. شغّل migrations أولًا.']);
        }

        return DB::transaction(function () use ($agent, $name, $expiresInDays, $catalogScope, $allowRework, $identityOnly, $permissionIds): NewAccessToken {
            $agent->permissions()->syncWithoutDetaching($permissionIds);
            $agent->forceFill(['agent_api_enabled' => true])->save();

            return $agent->createToken(
                $name,
                $identityOnly
                    ? [...self::IDENTITY_ONLY_ABILITIES, ...AgentCatalogScope::abilities(AgentCatalogScope::STORIES)]
                    : [
                        ...self::OPERATION_ABILITIES,
                        ...AgentCatalogScope::abilities($catalogScope),
                        ...($allowRework ? self::REWORK_ABILITIES : []),
                    ],
                now()->addDays($expiresInDays),
            );
        });
    }

    public function revoke(User $agent, PersonalAccessToken $token): void
    {
        if ($token->tokenable_type !== User::class
            || (int) $token->tokenable_id !== (int) $agent->id
            || ! in_array('agent', $token->abilities ?? [], true)) {
            abort(404);
        }

        $token->delete();
    }
}
