<?php

namespace App\Services\Orders;

use App\Models\AdminActivityLog;
use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Uploads\OrderPhotoUploadService;
use App\Support\AdminActivityLogger;
use App\Support\ProductPersonalizationSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdminOrderQuickEditService
{
    public function __construct(
        private readonly AdminOrderUpdateService $updater,
        private readonly OrderPhotoUploadService $photos,
        private readonly OrderDetailsUpdateService $details,
    ) {}

    public function options(Order $representative, User $actor): array
    {
        $orders = Order::query()->with(['items.product', 'story'])
            ->where('checkout_group_key', $representative->checkoutGroupKey())->orderBy('id')->get();
        $canSeePhotos = $actor->hasPermission('orders.photos.view');

        return [
            'products' => Product::query()->with('activeVariants')->where('is_active', true)
                ->orderBy('name_ar')->get()->map(fn (Product $product): array => [
                    'id' => $product->id, 'name' => $product->name_ar,
                    'price' => $product->effectivePriceCents() / 100,
                    'linked' => $product->isPersonalizedAddon(),
                    'schema' => ProductPersonalizationSchema::forProduct($product),
                    'variants' => $product->activeVariants->map(fn ($variant): array => [
                        'id' => $variant->id, 'name' => $variant->name_ar,
                        'price' => $product->effectivePriceCents($variant) / 100,
                    ])->all(),
                ])->all(),
            'children' => $orders->filter(fn (Order $order): bool => filled($order->child_name))
                ->map(fn (Order $order): array => [
                    'order_id' => $order->id, 'name' => $order->child_name,
                    'label' => $order->child_name.' — '.($order->items->first()?->title ?? $order->order_number),
                    'story' => (bool) $order->story_id,
                    'values' => $this->childValues($order),
                    'photos' => $canSeePhotos ? collect($order->uploaded_photos ?? [])->keys()
                        ->map(fn (int $index): string => route('admin.orders.photo', [$order, $index, 'thumbnail' => 1]))->all() : [],
                ])->values()->all(),
            'items' => $orders->flatMap->items->filter(fn (OrderItem $item): bool => in_array($item->item_type, ['product', 'product_add_on'], true))
                ->map(fn (OrderItem $item): array => [
                    'id' => $item->id, 'order_id' => $item->order_id, 'title' => $item->title,
                    'linked' => $item->item_type === 'product_add_on',
                    'schema' => $this->itemSchema($item),
                    'values' => $this->itemValues($item, $orders->firstWhere('id', (int) $item->order_id)),
                ])->values()->all(),
            'can_upload_photos' => $canSeePhotos,
            'stories' => $orders->filter(fn (Order $order): bool => (bool) $order->story_id)->map(fn (Order $order): array => [
                'order_id' => $order->id,
                'values' => array_replace(Arr::only($order->getAttributes(), ['child_name', 'child_age', 'child_gender', 'language', 'lesson', 'interests', 'gift_note', 'parent_notes']),
                    ['language' => $order->language ?? $order->story?->language ?? 'ar']),
            ])->values()->all(),
        ];
    }

    public function updateStory(Order $order, array $data, User $actor, Request $request): void
    {
        $this->withPhotoCleanup(function (callable $onStored) use ($order, $data, $actor, $request): void {
            DB::transaction(function () use ($order, $data, $actor, $request, $onStored): void {
                $orders = Order::query()->where('checkout_group_key', $order->checkoutGroupKey())->orderBy('id')->lockForUpdate()->get();
                $order = $orders->firstWhere('id', $order->id);
                abort_unless($order && $order->story_id, 404);
                $this->authorizePhotos($actor, $data['photos'] ?? []);
                if (($data['photos'] ?? []) !== []) {
                    $upload = $this->photos->append($order, $data['photos'], $onStored);
                    foreach ($order->items()->where('item_type', 'story')->get() as $item) {
                        $snapshot = $item->personalization_snapshot ?? [];
                        $snapshot['uploaded_photos_count'] = $upload['total_count'];
                        $item->forceFill(['personalization_snapshot' => $snapshot])->save();
                    }
                    AdminActivityLogger::log(action: 'order.photos_uploaded', description: 'إضافة صور طفل القصة', subject: $order,
                        properties: ['reason' => $data['change_reason'], 'file_names' => collect($data['photos'])->map(fn ($file) => $file->getClientOriginalName())->all(),
                            'changes' => ['uploaded_photos_count' => ['old' => $upload['total_count'] - $upload['added_count'], 'new' => $upload['total_count']]]],
                        admin: $actor, request: $request);
                }
                $this->details->update($order, [
                    ...Arr::except($data, ['photos']), 'parent_name' => $order->parent_name,
                    'phone' => data_get($order->delivery_details, 'phone'),
                ], $actor, $request);
            });
        });
    }

    public function contact(Order $representative, array $data, User $actor, Request $request): void
    {
        DB::transaction(function () use ($representative, $data, $actor, $request): void {
            $orders = Order::query()->where('checkout_group_key', $representative->checkoutGroupKey())
                ->orderBy('id')->lockForUpdate()->get();
            abort_if($orders->isEmpty(), 404);
            $before = $orders->mapWithKeys(fn (Order $order): array => [$order->id => [
                'parent_name' => $order->parent_name,
                'phone' => data_get($order->delivery_details, 'phone'),
                'alternate_phone' => data_get($order->delivery_details, 'alternate_phone'),
            ]])->all();
            foreach ($orders as $order) {
                $delivery = array_replace($order->delivery_details ?? [], Arr::only($data, ['phone', 'alternate_phone']));
                $order->forceFill([...Arr::only($data, ['parent_name']), 'delivery_details' => $delivery])->save();
            }
            // Keep a linked identity request's contact current without touching its child or production.
            $identityContact = Arr::only($data, ['parent_name']);
            if (array_key_exists('phone', $data)) {
                $identityContact['parent_phone'] = $data['phone'];
            }
            if ($identityContact !== []) {
                ChildIdentityRequest::query()->whereIn('id', $orders->pluck('child_identity_request_id')->filter())->update($identityContact);
            }
            $first = $orders->first();
            AdminActivityLogger::log(
                action: 'checkout.contact_updated', description: 'تعديل بيانات التواصل فقط', subject: $first,
                properties: ['reason' => $data['change_reason'], 'order_ids' => $orders->pluck('id')->all(), 'before' => $before,
                    'changes' => AdminActivityLogger::changedValues($before[$first->id], Arr::only($data, ['parent_name', 'phone', 'alternate_phone']))],
                admin: $actor, request: $request,
            );
        });
    }

    public function addProduct(Order $representative, array $data, User $actor, Request $request): void
    {
        $this->withPhotoCleanup(function (callable $onStored) use ($representative, $data, $actor, $request): void {
            DB::transaction(function () use ($representative, $data, $actor, $request, $onStored): void {
                $orders = Order::query()->with('items')->where('checkout_group_key', $representative->checkoutGroupKey())
                    ->orderBy('id')->lockForUpdate()->get();
                $fingerprint = Arr::except($data, ['photos', 'request_key']);
                $fingerprint['photos'] = collect($data['photos'] ?? [])->map(fn ($file): array => [
                    'name' => $file->getClientOriginalName(), 'sha256' => hash_file('sha256', $file->getRealPath()),
                ])->all();
                $data['request_hash'] = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
                $previous = AdminActivityLog::query()->where('action', 'checkout.product_added')
                    ->where('user_id', $actor->id)->where('properties->checkout_group_key', $representative->checkoutGroupKey())
                    ->where('properties->request_key', $data['request_key'])->first();
                if ($previous) {
                    abort_unless(hash_equals((string) ($previous->properties['request_hash'] ?? ''), $data['request_hash']), 409, 'طلب الإضافة مكرر ببيانات مختلفة. افتح إضافة جديدة.');

                    return;
                }
                $product = Product::query()->where('is_active', true)->lockForUpdate()->findOrFail($data['product_id']);
                $schema = ProductPersonalizationSchema::forProduct($product);
                $source = null;
                if (! empty($data['reuse_child_order_id'])) {
                    $source = $orders->firstWhere('id', (int) $data['reuse_child_order_id']);
                    if (! $source || blank($source->child_name)) {
                        throw ValidationException::withMessages(['reuse_child_order_id' => 'اختر طفلًا من داخل الطلب الحالي.']);
                    }
                }
                $reusedPhotos = $source?->uploaded_photos ?? [];
                $this->authorizePhotos($actor, $data['photos'] ?? [], $reusedPhotos);
                $personalization = $this->validateFields($schema,
                    array_replace($source ? $this->childValues($source) : [], $data['personalization'] ?? []),
                    $data['photos'] ?? [], count($reusedPhotos), false);
                $this->updater->appendProduct($representative, [
                    ...$data, 'schema' => $schema, 'personalization' => $personalization,
                    'reused_photos' => $reusedPhotos, 'on_stored' => $onStored,
                ], $actor, $request);
            });
        });
    }

    public function updateItem(Order $representative, OrderItem $item, array $data, User $actor, Request $request): void
    {
        $this->withPhotoCleanup(function (callable $onStored) use ($representative, $item, $data, $actor, $request): void {
            DB::transaction(function () use ($representative, $item, $data, $actor, $request, $onStored): void {
                // Always lock in checkout order to serialize against the full editor and adding items.
                $orders = Order::query()->where('checkout_group_key', $representative->checkoutGroupKey())
                    ->orderBy('id')->lockForUpdate()->get();
                $item = OrderItem::query()->with('product')->lockForUpdate()->findOrFail($item->id);
                $order = $orders->firstWhere('id', (int) $item->order_id);
                abort_unless($order && $item->item_type === 'product', 404);
                if ($order->story_id || $order->items()->whereKeyNot($item->id)->where('personalization_mode', 'collect_child_details')->exists()) {
                    throw ValidationException::withMessages(['personalization' => 'هذا السجل القديم يجمع بيانات طفل لعدة منتجات؛ استخدم تعديل الطلب الكامل لتجنب تغيير منتج آخر.']);
                }
                $schema = $this->itemSchema($item);
                $before = $item->personalization_snapshot ?? [];
                $oldValues = $this->itemValues($item, $order);
                $this->authorizePhotos($actor, $data['photos'] ?? []);
                $this->validateFields($schema, $data['personalization'] ?? [], $data['photos'] ?? [], count($order->uploaded_photos ?? []), true);
                $values = array_replace($oldValues, $data['personalization'] ?? []);
                if (($data['photos'] ?? []) !== []) {
                    $this->photos->append($order, $data['photos'], $onStored);
                }
                $snapshot = array_replace($before, ProductPersonalizationSchema::snapshot($schema, $values, count($order->uploaded_photos ?? [])));
                // Preserve unrelated legacy/supplemental fields as well as component identities.
                $snapshot['fields'] = array_replace($before['fields'] ?? [], $snapshot['fields']);
                $item->forceFill(['personalization_snapshot' => $snapshot])->save();
                $order->forceFill(Arr::only($values, ['child_name', 'child_age', 'child_gender', 'interests', 'parent_notes']))->save();
                AdminActivityLogger::log(
                    action: 'order.product_details_updated', description: 'تعديل تخصيص منتج واحد: '.$item->title, subject: $order,
                    properties: ['reason' => $data['change_reason'], 'order_item_id' => $item->id, 'product_title' => $item->title,
                        'before' => $before, 'after' => $snapshot,
                        'changes' => AdminActivityLogger::changedValues($oldValues + ['uploaded_photos_count' => $before['uploaded_photos_count'] ?? 0],
                            $values + ['uploaded_photos_count' => count($order->uploaded_photos ?? [])]),
                        'file_names' => collect($data['photos'] ?? [])->map(fn ($file) => $file->getClientOriginalName())->all()],
                    admin: $actor, request: $request,
                );
            });
        });
    }

    private function childValues(Order $order): array
    {
        return array_replace(Arr::only($order->getAttributes(), ['child_name', 'child_age', 'child_gender', 'interests', 'parent_notes']),
            ProductPersonalizationSchema::formValues($order->items->firstWhere('item_type', 'product')?->personalization_snapshot ?? []));
    }

    private function itemSchema(OrderItem $item): array
    {
        return ProductPersonalizationSchema::normalize($item->personalization_snapshot['schema']
            ?? ($item->product ? ProductPersonalizationSchema::forProduct($item->product) : ProductPersonalizationSchema::legacyDefault()));
    }

    private function itemValues(OrderItem $item, Order $order): array
    {
        return array_replace(Arr::only($order->getAttributes(), ['child_name', 'child_age', 'child_gender', 'interests', 'parent_notes']),
            ProductPersonalizationSchema::formValues($item->personalization_snapshot ?? []));
    }

    private function validateFields(array $schema, array $values, array $photos, int $existingCount, bool $partial): array
    {
        $rules = ProductPersonalizationSchema::adminOrderValidationRules($schema);
        $allowed = array_keys(ProductPersonalizationSchema::enabledFields($schema));
        if ($partial && array_diff(array_keys($values), $allowed) !== []) {
            throw ValidationException::withMessages(['personalization' => 'إحدى بيانات المنتج غير متاحة للتعديل.']);
        }
        if ($partial) {
            $rules = Arr::only($rules, [...array_keys($values), 'photos', 'photos.*']);
        }
        if (isset($rules['photos'])) {
            $photoField = ProductPersonalizationSchema::enabledFields($schema)['photos'];
            $min = ! $partial && $photoField['required'] ? max(0, $photoField['min_files'] - $existingCount) : 0;
            $max = $partial ? (int) config('photo_uploads.admin_max_files', 10) : (int) $photoField['max_files'];
            if (! $partial && $existingCount > $max) {
                throw ValidationException::withMessages(['reuse_child_order_id' => 'صور الطفل المختار تتجاوز الحد المسموح لهذا المنتج. اختر إدخال البيانات والصور يدويًا.']);
            }
            $rules['photos'] = [$min ? 'required' : 'nullable', 'array', 'min:'.$min, 'max:'.max(0, $max - $existingCount)];
        } elseif ($photos !== []) {
            throw ValidationException::withMessages(['photos' => 'هذا المنتج لا يطلب صورًا.']);
        }
        $validated = Validator::make([...$values, 'photos' => $photos], $rules, ProductPersonalizationSchema::adminOrderValidationMessages($schema))->validate();
        unset($validated['photos']);

        return $validated;
    }

    private function authorizePhotos(User $actor, array $newPhotos, array $reusedPhotos = []): void
    {
        abort_if(($newPhotos !== [] || $reusedPhotos !== []) && ! $actor->hasPermission('orders.photos.view'), 403);
    }

    private function withPhotoCleanup(callable $operation): void
    {
        $paths = [];
        try {
            $operation(function (string $path) use (&$paths): void {
                $paths[] = $path;
            });
        } catch (\Throwable $exception) {
            if ($paths !== []) {
                Storage::disk((string) config('photo_uploads.disk', 'local'))->delete($paths);
            }
            throw $exception;
        }
    }
}
