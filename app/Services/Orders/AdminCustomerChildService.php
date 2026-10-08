<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Storage\MediaStorage;
use App\Support\Phone;
use App\Support\ProductPersonalizationSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminCustomerChildService
{
    public function children(string $phone, User $actor): array
    {
        return Order::query()->with('items')
            ->whereIn('delivery_details->phone', Phone::equivalentValues($phone))
            ->whereNotNull('child_name')->where('child_name', '!=', '')
            ->latest('created_at')->latest('id')->limit(100)->get()
            ->filter(fn (Order $order): bool => Phone::forWhatsApp(data_get($order->delivery_details, 'phone')) === Phone::forWhatsApp($phone))
            ->map(fn (Order $order): array => [
                'order_id' => $order->id,
                'label' => $order->child_name.' — '.($order->items->first()?->title ?? $order->order_number),
                'values' => $this->values($order),
                'photo_count' => $actor->hasPermission('orders.photos.view') ? count($order->uploaded_photos ?? []) : 0,
                'photos' => $actor->hasPermission('orders.photos.view')
                    ? collect($order->uploaded_photos ?? [])->keys()->map(fn (int $index): string => route('admin.orders.photo', [$order, $index, 'thumbnail' => 1]))->all() : [],
            ])->unique(fn (array $child): string => json_encode([$child['values'], $child['photos']]))->values()->all();
    }

    public function source(int $orderId, string $phone, User $actor, string $field, bool $lock = false): Order
    {
        $query = Order::query()->with('items')->whereKey($orderId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $order = $query->first();
        if (! $order || blank($order->child_name) || ! Phone::forWhatsApp($phone)
            || Phone::forWhatsApp(data_get($order->delivery_details, 'phone')) !== Phone::forWhatsApp($phone)) {
            throw ValidationException::withMessages([$field => 'اختر طفلًا من الطلبات السابقة لنفس رقم العميل الحالي.']);
        }

        return $order;
    }

    public function values(Order $order): array
    {
        return Arr::only(array_replace(
            Arr::only($order->getAttributes(), ['child_name', 'child_age', 'child_gender', 'interests', 'parent_notes']),
            ProductPersonalizationSchema::formValues($order->items->firstWhere('item_type', 'product')?->personalization_snapshot ?? []),
        ), ['child_name', 'child_age', 'child_gender', 'school_name', 'class_name', 'interests', 'parent_notes']);
    }

    public function photos(Order $source, User $actor): array
    {
        $photos = array_values($source->uploaded_photos ?? []);
        abort_if($photos !== [] && ! $actor->hasPermission('orders.photos.view'), 403);

        return $photos;
    }

    /** Copy to independent private files so later source-order edits cannot affect this purchase. */
    public function copyPhotos(Order $target, array $paths): void
    {
        $disk = (string) config('photo_uploads.disk', 'local');
        $created = [];
        try {
            foreach ($paths as $path) {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'avif'], true) ? $extension : 'jpg';
                $destination = 'orders/photos/'.$target->id.'/reused/'.Str::uuid().'.'.$extension;
                $created[] = $destination;
                app(MediaStorage::class)->copy($disk, $path, $disk, $destination);
            }
            $target->forceFill(['uploaded_photos' => [...($target->uploaded_photos ?? []), ...$created]])->save();
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($created);
            throw $exception;
        }
    }
}
