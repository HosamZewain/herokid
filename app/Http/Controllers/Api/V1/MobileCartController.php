<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileCartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileCartController extends Controller
{
    public function show(Request $request, MobileCartService $carts): JsonResponse
    {
        return response()->json(['data' => $carts->payload($carts->activeCart($request->user()))])->header('Cache-Control', 'private, no-store');
    }

    public function storeItem(Request $request, MobileCartService $carts): JsonResponse
    {
        $data = $request->validate($this->itemRules());

        return response()->json(['data' => $carts->payload($carts->add($request->user(), $data))], 201);
    }

    public function storeBatch(Request $request, MobileCartService $carts): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'min:1', 'max:20'], 'items.*' => ['required', 'array']]);
        $items = [];
        foreach ($data['items'] as $index => $item) {
            $validator = Validator::make($item, $this->itemRules());
            if ($validator->fails()) {
                throw ValidationException::withMessages(collect($validator->errors()->messages())
                    ->mapWithKeys(fn ($messages, $key) => ['items.'.$index.'.'.$key => $messages])->all());
            }
            $items[] = $validator->validated();
        }
        $seen = [];
        foreach ($items as $index => $item) {
            if (isset($seen[$item['idempotency_key']])) {
                throw ValidationException::withMessages(['items.'.$index.'.idempotency_key' => 'Each configuration needs its own idempotency key.']);
            }
            $seen[$item['idempotency_key']] = true;
        }
        if (collect($items)->sum(fn ($item) => $item['item_type'] === 'story' ? 1 : ($item['quantity'] ?? 1)) > 20) {
            throw ValidationException::withMessages(['items' => 'Add no more than twenty children or copies in one configuration.']);
        }

        return response()->json(['data' => $carts->payload($carts->addBatch($request->user(), $items))], 201);
    }

    private function itemRules(): array
    {
        return [
            'item_type' => ['required', Rule::in(['story', 'product'])],
            'story_id' => ['required_if:item_type,story', 'nullable', 'integer'],
            'product_id' => ['required_if:item_type,product', 'nullable', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'linked_item_id' => ['nullable', 'uuid'],
            'child_profile_id' => ['required_if:item_type,story', 'nullable', 'uuid'],
            'child_photo_ids' => ['required_if:item_type,story', 'nullable', 'array', 'max:'.max(1, (int) config('photo_uploads.max_files', 3))],
            'child_photo_ids.*' => ['uuid', 'distinct'],
            'child_identity_id' => ['nullable', 'uuid'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'dedication' => ['nullable', 'string', 'max:1000'],
            'additional_instructions' => ['nullable', 'string', 'max:2000'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'theme' => ['nullable', 'string', 'max:80'],
            'personalization' => ['nullable', 'array'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function updateItem(Request $request, string $item, MobileCartService $carts): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:20']]);

        return response()->json(['data' => $carts->payload($carts->updateQuantity($request->user(), $item, $data['quantity']))]);
    }

    public function destroyItem(Request $request, string $item, MobileCartService $carts): JsonResponse
    {
        return response()->json(['data' => $carts->payload($carts->remove($request->user(), $item))]);
    }

    public function duplicateItem(Request $request, string $item, MobileCartService $carts): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);

        return response()->json(['data' => $carts->payload($carts->duplicate($request->user(), $item, $data['idempotency_key']))], 201);
    }

    public function applyPromo(Request $request, MobileCartService $carts): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:80']]);

        return response()->json(['data' => $carts->payload($carts->applyPromo($request->user(), $data['code']))]);
    }

    public function removePromo(Request $request, MobileCartService $carts): JsonResponse
    {
        return response()->json(['data' => $carts->payload($carts->removePromo($request->user()))]);
    }
}
