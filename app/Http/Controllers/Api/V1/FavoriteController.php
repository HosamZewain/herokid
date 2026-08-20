<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\Story;
use App\Services\Catalog\UnifiedStorefrontService;
use App\Services\Mobile\MobileCatalogPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

class FavoriteController extends Controller
{
    public function index(Request $request, UnifiedStorefrontService $catalog, MobileCatalogPresenter $presenter): JsonResponse
    {
        App::setLocale(in_array($request->query('locale'), ['ar', 'en'], true) ? $request->query('locale') : 'ar');
        $favorites = Favorite::query()->where('user_id', $request->user()->id)->latest()->get();
        $stories = Story::query()->with('categories:id,name,slug')->where('active', true)->whereIn('id', $favorites->where('item_type', 'story')->pluck('item_id'))->get()->keyBy('id');
        $products = Product::query()->with('category:id,name_ar,name_en,slug,is_active,show_in_store')->publiclyVisible()->whereIn('id', $favorites->where('item_type', 'product')->pluck('item_id'))->get()->keyBy('id');

        return response()->json(['data' => $favorites->map(function (Favorite $favorite) use ($catalog, $presenter, $stories, $products): ?array {
            $model = $favorite->item_type === 'story' ? $stories->get($favorite->item_id) : $products->get($favorite->item_id);
            if (! $model) {
                return null;
            }
            $item = $favorite->item_type === 'story' ? $catalog->storyItem($model) : $catalog->productItem($model);

            return ['type' => $favorite->item_type, 'id' => $favorite->item_id, 'created_at' => $favorite->created_at?->toISOString(), 'item' => $presenter->item($item)];
        })->filter()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['story', 'product'])],
            'id' => ['required', 'integer', 'min:1'],
        ]);
        $exists = $validated['type'] === 'story'
            ? Story::query()->whereKey($validated['id'])->where('active', true)->exists()
            : Product::query()->whereKey($validated['id'])->publiclyVisible()->exists();
        abort_unless($exists, 404);
        $favorite = Favorite::firstOrCreate([
            'user_id' => $request->user()->id,
            'item_type' => $validated['type'],
            'item_id' => $validated['id'],
        ]);

        return response()->json(['data' => ['type' => $favorite->item_type, 'id' => $favorite->item_id]], $favorite->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        abort_unless(in_array($type, ['story', 'product'], true), 404);
        Favorite::query()->where('user_id', $request->user()->id)->where('item_type', $type)->where('item_id', $id)->delete();

        return response()->json(status: 204);
    }
}
