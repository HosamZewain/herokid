<?php

namespace App\Services\AgentApi;

use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AgentTokenService
{
    public const BASE_ABILITY = 'agent';

    /**
     * Canonical catalog for editable Agent API abilities. Catalog and product
     * restriction abilities are managed by their dedicated controls.
     */
    public const ABILITY_DEFINITIONS = [
        'agent:orders.read' => [
            'label' => 'قراءة الطلبات',
            'short_label' => 'قراءة',
            'description' => 'قراءة سياق الطلب وملفات الإنتاج الخاصة به.',
            'permissions' => ['orders.view', 'orders.photos.view'],
            'mode' => 'operation',
            'default' => true,
        ],
        'agent:orders.acquire' => [
            'label' => 'استحواذ الطلبات',
            'short_label' => 'استحواذ',
            'description' => 'استلام عملية شراء جديدة من قائمة انتظار الإنتاج.',
            'permissions' => ['orders.assign'],
            'mode' => 'operation',
            'default' => true,
        ],
        'agent:orders.update-status' => [
            'label' => 'تحديث حالة الإنتاج',
            'short_label' => 'تحديث الحالة',
            'description' => 'إكمال الإنتاج وتحديث حالة عملية الشراء.',
            'permissions' => ['orders.update'],
            'mode' => 'operation',
            'default' => true,
        ],
        'agent:orders.upload-attachment' => [
            'label' => 'رفع ملفات الإنتاج',
            'short_label' => 'ملفات الإنتاج',
            'description' => 'رفع مرفقات الإنتاج للطلب بعد الاستحواذ عليه.',
            'permissions' => ['orders.update'],
            'mode' => 'operation',
            'default' => true,
        ],
        'agent:orders.upload-preview' => [
            'label' => 'رفع المعاينات',
            'short_label' => 'رفع المعاينة',
            'description' => 'رفع أو استبدال معاينات العميل دون اشتراط الاستحواذ.',
            'permissions' => ['orders.preview.upload'],
            'mode' => 'operation',
            'default' => true,
        ],
        'agent:orders.edit-personalization' => [
            'label' => 'تعديل بيانات التخصيص',
            'short_label' => 'تعديل التخصيص',
            'description' => 'تعديل بيانات تخصيص طلبات إعادة العمل.',
            'permissions' => ['orders.update'],
            'mode' => 'rework',
            'default' => false,
        ],
        'agent:orders.rework' => [
            'label' => 'إعادة إنتاج الطلبات السابقة',
            'short_label' => 'إعادة العمل',
            'description' => 'اختيار وتشغيل دورة إعادة عمل لطلب سابق.',
            'permissions' => ['orders.assign', 'orders.update'],
            'mode' => 'rework',
            'default' => false,
        ],
        'agent:orders.identity' => [
            'label' => 'هويات القصص فقط',
            'short_label' => 'هويات القصص',
            'description' => 'تشغيل قائمة انتظار هويات القصص المقيدة.',
            'permissions' => ['orders.assign', 'orders.update', 'orders.photos.view'],
            'mode' => 'identity',
            'default' => false,
        ],
    ];

    public static function abilityDefinitions(): array
    {
        return self::ABILITY_DEFINITIONS;
    }

    public static function editableOperationAbilities(): array
    {
        return array_keys(array_filter(
            self::ABILITY_DEFINITIONS,
            fn (array $definition): bool => $definition['mode'] === 'operation',
        ));
    }

    public static function reworkAbilities(): array
    {
        return array_keys(array_filter(
            self::ABILITY_DEFINITIONS,
            fn (array $definition): bool => $definition['mode'] === 'rework',
        ));
    }

    public static function identityAbilities(): array
    {
        return array_keys(array_filter(
            self::ABILITY_DEFINITIONS,
            fn (array $definition): bool => $definition['mode'] === 'identity',
        ));
    }

    public static function defaultOperationAbilities(): array
    {
        return array_keys(array_filter(
            self::ABILITY_DEFINITIONS,
            fn (array $definition): bool => $definition['mode'] === 'operation' && $definition['default'],
        ));
    }

    public static function requiredPermissions(): array
    {
        return collect(self::ABILITY_DEFINITIONS)
            ->flatMap(fn (array $definition): array => $definition['permissions'])
            ->unique()
            ->values()
            ->all();
    }

    public function issue(
        User $agent,
        string $name,
        int $expiresInDays,
        string $catalogScope,
        bool $allowRework = false,
        bool $identityOnly = false,
        array $allowedProductIds = [],
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

        $allowedProductIds = $this->validatedProductIds($catalogScope, $allowedProductIds);

        $requiredPermissions = self::requiredPermissions();
        $permissionIds = Permission::query()->whereIn('key', $requiredPermissions)->pluck('id');
        if ($permissionIds->count() !== count($requiredPermissions)) {
            throw ValidationException::withMessages(['agent_user_id' => 'صلاحيات الطلبات المطلوبة غير مكتملة. شغّل migrations أولًا.']);
        }

        return DB::transaction(function () use ($agent, $name, $expiresInDays, $catalogScope, $allowRework, $identityOnly, $allowedProductIds, $permissionIds): NewAccessToken {
            $agent->permissions()->syncWithoutDetaching($permissionIds);
            $agent->forceFill(['agent_api_enabled' => true])->save();

            return $agent->createToken(
                $name,
                $identityOnly
                    ? [self::BASE_ABILITY, ...self::identityAbilities(), ...AgentCatalogScope::abilities(AgentCatalogScope::STORIES)]
                    : [
                        self::BASE_ABILITY,
                        ...self::defaultOperationAbilities(),
                        ...AgentCatalogScope::abilities($catalogScope),
                        ...AgentProductScope::abilities($allowedProductIds),
                        ...($allowRework ? self::reworkAbilities() : []),
                    ],
                now()->addDays($expiresInDays),
            );
        });
    }

    /** @return array<string, mixed> */
    public function configuration(PersonalAccessToken $token): array
    {
        $abilities = is_array($token->abilities) ? $token->abilities : [];
        $productIds = AgentProductScope::productIdsFromAbilities($abilities);

        return [
            'name' => $token->name,
            'abilities' => array_values($abilities),
            'catalog_scope' => AgentCatalogScope::fromAbilities($abilities),
            'restrict_products' => $productIds !== [],
            'product_ids' => $productIds,
            'allow_rework' => collect(self::reworkAbilities())->every(fn (string $ability): bool => in_array($ability, $abilities, true)),
            'identity_only' => collect(self::identityAbilities())->every(fn (string $ability): bool => in_array($ability, $abilities, true)),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];
    }

    public function agentForToken(PersonalAccessToken $token): User
    {
        $abilities = is_array($token->abilities) ? $token->abilities : [];
        if ($token->tokenable_type !== User::class || ! in_array(self::BASE_ABILITY, $abilities, true)) {
            abort(404);
        }

        return User::query()->where('role', 'admin')->findOrFail($token->tokenable_id);
    }

    /** @param array<string, mixed> $attributes */
    public function update(PersonalAccessToken $token, array $attributes): PersonalAccessToken
    {
        $this->agentForToken($token);

        $catalogScope = (string) $attributes['catalog_scope'];
        if (! in_array($catalogScope, [AgentCatalogScope::ALL, AgentCatalogScope::STORIES, AgentCatalogScope::PRODUCTS], true)) {
            throw ValidationException::withMessages(['catalog_scope' => 'نطاق المنتجات المحدد غير صحيح.']);
        }

        $selectedAbilities = collect($attributes['abilities'] ?? [])
            ->filter(fn (mixed $ability): bool => is_string($ability))
            ->unique()
            ->values()
            ->all();
        $unknownAbilities = array_diff($selectedAbilities, self::editableOperationAbilities());
        if ($unknownAbilities !== []) {
            throw ValidationException::withMessages(['abilities' => 'إحدى صلاحيات التوكن المحددة غير مدعومة.']);
        }

        $identityOnly = (bool) ($attributes['identity_only'] ?? false);
        $allowRework = (bool) ($attributes['allow_rework'] ?? false);
        if ($identityOnly && $catalogScope !== AgentCatalogScope::STORIES) {
            throw ValidationException::withMessages(['catalog_scope' => 'توكن هويات القصص فقط يجب أن يكون نطاقه القصص فقط.']);
        }
        if ($identityOnly && $allowRework) {
            throw ValidationException::withMessages(['allow_rework' => 'لا يمكن جمع وضع هويات القصص فقط مع صلاحية إعادة الإنتاج.']);
        }
        if ($identityOnly && $selectedAbilities !== []) {
            throw ValidationException::withMessages(['abilities' => 'وضع هويات القصص فقط لا يقبل صلاحيات تشغيل الإنتاج.']);
        }

        $allowedProductIds = (bool) ($attributes['restrict_products'] ?? false)
            ? ($attributes['product_ids'] ?? [])
            : [];
        $allowedProductIds = $this->validatedProductIds($catalogScope, $allowedProductIds);
        if ((bool) ($attributes['restrict_products'] ?? false) && $allowedProductIds === []) {
            throw ValidationException::withMessages(['product_ids' => 'اختر منتجًا واحدًا على الأقل.']);
        }

        $abilities = $identityOnly
            ? [self::BASE_ABILITY, ...self::identityAbilities(), ...AgentCatalogScope::abilities(AgentCatalogScope::STORIES)]
            : [
                self::BASE_ABILITY,
                ...$selectedAbilities,
                ...AgentCatalogScope::abilities($catalogScope),
                ...AgentProductScope::abilities($allowedProductIds),
                ...($allowRework ? self::reworkAbilities() : []),
            ];

        $token->forceFill([
            'name' => trim((string) $attributes['name']),
            'abilities' => array_values(array_unique($abilities)),
            'expires_at' => Carbon::parse($attributes['expires_at']),
        ])->save();

        return $token->fresh();
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

    /** @param array<int, mixed> $allowedProductIds */
    private function validatedProductIds(string $catalogScope, array $allowedProductIds): array
    {
        $allowedProductIds = collect($allowedProductIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($allowedProductIds !== [] && $catalogScope !== AgentCatalogScope::PRODUCTS) {
            throw ValidationException::withMessages(['product_ids' => 'تحديد منتجات بعينها متاح فقط مع نطاق المنتجات فقط.']);
        }

        if ($allowedProductIds !== [] && Product::query()->whereIn('id', $allowedProductIds)->count() !== count($allowedProductIds)) {
            throw ValidationException::withMessages(['product_ids' => 'أحد المنتجات المحددة غير موجود.']);
        }

        return $allowedProductIds;
    }
}
