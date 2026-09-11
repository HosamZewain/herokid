<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderCheckoutReference;
use App\Models\OrderTag;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderGroupTagService
{
    private const MAX_TAGS = 12;

    private const MAX_TAG_LENGTH = 40;

    public function __construct(private readonly OrderShortReferenceService $references) {}

    /** @return Collection<int, OrderTag> */
    public function update(Order $representative, ?string $tagList, User $admin, Request $request): Collection
    {
        $names = $this->parse($tagList);

        return $this->persist($representative, $names, $admin, $request);
    }

    /** @return Collection<int, OrderTag> */
    public function add(Order $representative, string $tagName, User $admin, Request $request): Collection
    {
        $reference = $this->references->ensureForOrder($representative);

        return DB::transaction(function () use ($representative, $reference, $tagName, $admin, $request): Collection {
            $reference = OrderCheckoutReference::query()
                ->with('tags:id,name,normalized_name')
                ->lockForUpdate()
                ->findOrFail($reference->id);
            $names = $this->parse($reference->tags->pluck('name')->push($tagName)->implode(','));

            return $this->sync($representative, $reference, $names, $admin, $request);
        });
    }

    /** @return Collection<int, OrderTag> */
    public function remove(Order $representative, OrderTag $tag, User $admin, Request $request): Collection
    {
        $reference = $this->references->ensureForOrder($representative);

        return DB::transaction(function () use ($representative, $reference, $tag, $admin, $request): Collection {
            $reference = OrderCheckoutReference::query()
                ->with('tags:id,name,normalized_name')
                ->lockForUpdate()
                ->findOrFail($reference->id);
            abort_unless($reference->tags->contains('id', $tag->id), 404);
            $names = $this->parse(
                $reference->tags
                    ->reject(fn (OrderTag $current): bool => $current->is($tag))
                    ->pluck('name')
                    ->implode(','),
            );

            return $this->sync($representative, $reference, $names, $admin, $request);
        });
    }

    /**
     * @param  Collection<int, array{name: string, normalized: string}>  $names
     * @return Collection<int, OrderTag>
     */
    private function persist(Order $representative, Collection $names, User $admin, Request $request): Collection
    {
        $reference = $this->references->ensureForOrder($representative);

        return DB::transaction(function () use ($representative, $reference, $names, $admin, $request): Collection {
            $reference = OrderCheckoutReference::query()
                ->with('tags:id,name,normalized_name')
                ->lockForUpdate()
                ->findOrFail($reference->id);

            return $this->sync($representative, $reference, $names, $admin, $request);
        });
    }

    /**
     * @param  Collection<int, array{name: string, normalized: string}>  $names
     * @return Collection<int, OrderTag>
     */
    private function sync(
        Order $representative,
        OrderCheckoutReference $reference,
        Collection $names,
        User $admin,
        Request $request,
    ): Collection {
        $before = $reference->tags->sortBy('normalized_name')->pluck('name')->values()->all();

        $tagIds = $names->map(function (array $tag): int {
            return (int) OrderTag::query()->firstOrCreate(
                ['normalized_name' => $tag['normalized']],
                ['name' => $tag['name']],
            )->id;
        });

        $reference->tags()->sync($tagIds->all());
        $reference->touch();
        $after = $reference->tags()->orderBy('normalized_name')->pluck('name')->all();

        if ($before !== $after) {
            AdminActivityLogger::log(
                action: 'checkout.tags_updated',
                description: 'تم تحديث علامات عملية الشراء.',
                subject: $representative,
                properties: [
                    'checkout_group_key' => $representative->checkoutGroupKey(),
                    'before' => $before,
                    'after' => $after,
                ],
                admin: $admin,
                request: $request,
            );
        }

        return $reference->tags()->orderBy('normalized_name')->get();
    }

    /** @return Collection<int, array{name: string, normalized: string}> */
    private function parse(?string $tagList): Collection
    {
        $tags = collect(preg_split('/[,،\r\n]+/u', (string) $tagList) ?: [])
            ->map(fn (string $tag): string => Str::squish((string) preg_replace('/^[#＃\s]+/u', '', $tag)))
            ->filter()
            ->map(fn (string $name): array => [
                'name' => $name,
                'normalized' => Str::lower($name),
            ])
            ->unique('normalized')
            ->values();

        if ($tags->count() > self::MAX_TAGS) {
            throw ValidationException::withMessages([
                'tags' => 'يمكن إضافة '.self::MAX_TAGS.' علامة كحد أقصى لعملية الشراء.',
            ]);
        }

        $tooLong = $tags->first(fn (array $tag): bool => mb_strlen($tag['name']) > self::MAX_TAG_LENGTH);
        if ($tooLong) {
            throw ValidationException::withMessages([
                'tags' => 'يجب ألا يزيد طول العلامة الواحدة عن '.self::MAX_TAG_LENGTH.' حرفًا.',
            ]);
        }

        return $tags;
    }
}
