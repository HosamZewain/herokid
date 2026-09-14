<?php

namespace App\Services\AgentApi;

use App\Models\User;
use Illuminate\Support\Collection;

class AgentProductScope
{
    private const ABILITY_PREFIX = 'agent:catalog.product.';

    /** @param array<int, int|string> $productIds */
    public static function abilities(array $productIds): array
    {
        return collect($productIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->map(fn (int $id): string => self::ABILITY_PREFIX.$id)
            ->values()
            ->all();
    }

    /** @param array<int, string> $abilities */
    public static function productIdsFromAbilities(array $abilities): array
    {
        return collect($abilities)
            ->filter(fn (mixed $ability): bool => is_string($ability) && str_starts_with($ability, self::ABILITY_PREFIX))
            ->map(fn (string $ability): int => (int) substr($ability, strlen(self::ABILITY_PREFIX)))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    public static function forUser(User $user): array
    {
        $token = $user->currentAccessToken();

        return self::productIdsFromAbilities(is_array($token?->abilities) ? $token->abilities : []);
    }

    /**
     * An empty list intentionally means unrestricted product access for legacy
     * and existing product-scoped tokens.
     *
     * @param  Collection<int, array<string, mixed>>  $units
     */
    public static function allowsEveryUnit(User $user, Collection $units): bool
    {
        $allowedProductIds = self::forUser($user);
        if ($allowedProductIds === []) {
            return true;
        }

        return $units->every(function (array $unit) use ($allowedProductIds): bool {
            if (($unit['type'] ?? null) !== 'product') {
                return false;
            }

            return in_array((int) ($unit['product_id'] ?? 0), $allowedProductIds, true);
        });
    }
}
