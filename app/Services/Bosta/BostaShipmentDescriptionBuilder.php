<?php

namespace App\Services\Bosta;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BostaShipmentDescriptionBuilder
{
    /** @param array<string, mixed> $group */
    public function build(array $group, string $receiverName, string $phone): string
    {
        $products = collect($group['direct_products'] ?? [])
            ->concat($group['add_ons'] ?? [])
            ->groupBy(fn ($item): string => trim((string) ($item->title ?: 'منتج')))
            ->map(fn (Collection $items, string $title): string => $title.' × '.max(1, (int) $items->sum('quantity')))
            ->values();

        $stories = collect($group['story_titles'] ?? [])
            ->countBy()
            ->map(fn (int $quantity, string $title): string => $title.' × '.$quantity)
            ->values();

        $children = collect($group['child_names'] ?? [])
            ->concat($this->productChildNames(collect($group['direct_products'] ?? [])->concat($group['add_ons'] ?? [])))
            ->map(fn ($name): string => trim((string) $name))
            ->filter()
            ->unique()
            ->values();

        $reference = (string) ($group['short_reference'] ?: ($group['order_numbers'][0] ?? $group['key']));
        $parts = [
            'ولي الأمر: '.$receiverName,
            'الهاتف: '.$phone,
            'الطلب: '.$reference,
            'المنتجات: '.$stories->concat($products)->implode('، '),
        ];

        if ($children->isNotEmpty()) {
            $parts[] = 'الأطفال: '.$children->implode('، ');
        }

        return Str::limit(implode(' | ', array_filter($parts)), 1000, '');
    }

    private function productChildNames(Collection $items): Collection
    {
        return $items->flatMap(function ($item): array {
            $names = [];
            $snapshot = $item->personalization_snapshot ?? [];
            array_walk_recursive($snapshot, function ($value, $key) use (&$names): void {
                if ($key === 'child_name' && filled($value)) {
                    $names[] = (string) $value;
                }
            });

            return $names;
        });
    }
}
