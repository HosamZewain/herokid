<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderPreview;
use App\Models\OrderProductPreviewGallery;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class OrderProductPreviewService
{
    public function __construct(private readonly OrderProductPreviewImageService $imageService) {}

    /** @param array<int, UploadedFile> $files */
    public function upload(Order $order, array $files, ?string $note, ?User $actor): OrderProductPreviewGallery
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($order, $files, $note, $actor, &$storedPaths): OrderProductPreviewGallery {
                $gallery = OrderProductPreviewGallery::query()
                    ->lockForUpdate()
                    ->where('checkout_group_key', $order->checkoutGroupKey())
                    ->first();

                if (! $gallery) {
                    $token = Str::random(64);
                    $gallery = OrderProductPreviewGallery::create([
                        'checkout_group_key' => $order->checkoutGroupKey(),
                        'status' => 'active',
                        'public_token_hash' => hash('sha256', $token),
                        'public_token_encrypted' => Crypt::encryptString($token),
                        'created_by_user_id' => $actor?->id,
                    ]);
                }

                $created = collect();
                foreach ($files as $file) {
                    $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
                    $path = $file->storeAs(
                        'orders/product-previews/'.$gallery->id,
                        Str::uuid().'.'.$extension,
                        'local',
                    );

                    abort_unless($path, 422, 'تعذر حفظ إحدى صور المعاينة.');
                    $storedPaths[] = $path;

                    $preview = OrderPreview::create([
                        'order_id' => $order->id,
                        'product_gallery_id' => $gallery->id,
                        'file_path' => $path,
                        'disk' => 'local',
                        'original_name' => Str::limit($file->getClientOriginalName(), 240, ''),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'checksum' => hash_file('sha256', $file->getRealPath()),
                        'note' => $note,
                        'uploaded_by' => $actor?->id,
                    ]);

                    $created->push($preview);
                }

                AdminActivityLogger::log(
                    action: 'order.product_previews_uploaded',
                    description: 'تم رفع '.count($files).' صورة معاينة للمنتجات في الطلب.',
                    subject: $order,
                    properties: [
                        'gallery_id' => $gallery->id,
                        'preview_ids' => $created->pluck('id')->all(),
                        'file_names' => $created->pluck('original_name')->all(),
                    ],
                );

                return $gallery->fresh('previews');
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }
    }

    public function delete(OrderPreview $preview, ?User $actor): void
    {
        abort_unless($preview->product_gallery_id, 404);
        $order = $preview->order;

        DB::transaction(function () use ($preview, $order, $actor): void {
            $fileName = $preview->original_name ?: basename($preview->file_path);
            $this->imageService->deleteCustomerImage($preview);
            Storage::disk($preview->disk ?: 'local')->delete($preview->file_path);
            $preview->delete();

            AdminActivityLogger::log(
                action: 'order.product_preview_deleted',
                description: 'تم حذف صورة معاينة من طلب المنتجات.',
                subject: $order,
                properties: ['file_name' => $fileName, 'deleted_by' => $actor?->id],
            );
        });
    }
}
