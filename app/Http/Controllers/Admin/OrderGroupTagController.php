<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTag;
use App\Services\Orders\OrderGroupTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OrderGroupTagController extends Controller
{
    public function store(
        Request $request,
        int $representative,
        OrderGroupTagService $tags,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'tag' => ['required', 'string', 'max:40'],
        ]);
        $order = Order::query()->findOrFail($representative);

        $updatedTags = $tags->add(
            $order,
            $validated['tag'],
            $request->user(),
            $request,
        );

        return $this->response($request, $updatedTags, 'تمت إضافة العلامة.');
    }

    public function update(
        Request $request,
        int $representative,
        OrderGroupTagService $tags,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'tags' => ['nullable', 'string', 'max:600'],
        ]);
        $order = Order::query()->findOrFail($representative);

        $updatedTags = $tags->update(
            $order,
            $validated['tags'] ?? null,
            $request->user(),
            $request,
        );

        return $this->response($request, $updatedTags, 'تم تحديث علامات عملية الشراء.');
    }

    public function destroy(
        Request $request,
        int $representative,
        OrderTag $tag,
        OrderGroupTagService $tags,
    ): JsonResponse|RedirectResponse {
        $order = Order::query()->findOrFail($representative);

        $updatedTags = $tags->remove(
            $order,
            $tag,
            $request->user(),
            $request,
        );

        return $this->response($request, $updatedTags, 'تم حذف العلامة.');
    }

    /** @param Collection<int, OrderTag> $tags */
    private function response(Request $request, Collection $tags, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            $canDelete = $request->user()->hasPermission('orders.tags.delete');

            return response()->json([
                'message' => $message,
                'tags' => $tags->map(fn (OrderTag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'filter_url' => route('admin.orders.index', [
                        'catalog_type' => 'all',
                        'lifecycle' => 'all',
                        'tag_id' => $tag->id,
                    ]),
                    'delete_url' => $canDelete
                        ? route('admin.orders.groups.tags.destroy', [$request->route('representative'), $tag])
                        : null,
                ])->values(),
            ]);
        }

        return back()->with('success', $message);
    }
}
