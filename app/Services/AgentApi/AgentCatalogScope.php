<?php

namespace App\Services\AgentApi;

use App\Models\User;
use Illuminate\Support\Collection;

class AgentCatalogScope
{
    public const ALL = 'all';

    public const STORIES = 'stories';

    public const PRODUCTS = 'products';

    public static function abilities(string $scope): array
    {
        return match ($scope) {
            self::STORIES => ['agent:catalog.stories'],
            self::PRODUCTS => ['agent:catalog.products'],
            default => ['agent:catalog.stories', 'agent:catalog.products'],
        };
    }

    public static function label(string $scope): string
    {
        return match ($scope) {
            self::STORIES => 'القصص فقط',
            self::PRODUCTS => 'المنتجات فقط',
            default => 'القصص والمنتجات',
        };
    }

    public static function fromAbilities(array $abilities): string
    {
        $stories = in_array('agent:catalog.stories', $abilities, true);
        $products = in_array('agent:catalog.products', $abilities, true);

        // Backward compatibility for tokens issued before catalog scoping existed.
        if ((! $stories && ! $products) || ($stories && $products)) {
            return self::ALL;
        }

        return $stories ? self::STORIES : self::PRODUCTS;
    }

    public static function forUser(User $user): string
    {
        $token = $user->currentAccessToken();

        return self::fromAbilities(is_array($token?->abilities) ? $token->abilities : []);
    }

    public static function allows(User $user, string $type): bool
    {
        $scope = self::forUser($user);

        return $scope === self::ALL
            || ($scope === self::STORIES && $type === 'story')
            || ($scope === self::PRODUCTS && $type === 'product');
    }

    /** @param Collection<int, array<string, mixed>> $units */
    public static function allowsEveryUnit(User $user, Collection $units): bool
    {
        return $units->every(fn (array $unit): bool => self::allows($user, (string) $unit['type']));
    }
}
