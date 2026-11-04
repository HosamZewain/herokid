<?php

namespace App\Services\Orders;

use App\Exceptions\LegacyBookReviewRequired;
use App\Models\CodexSceneRun;
use App\Models\LegacyBookCoverApproval;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProductionComponent;
use App\Models\OrderPreview;
use App\Models\OrderProcessRun;
use App\Models\PuzzleGeneration;
use App\Models\User;
use App\Services\CodexScenes\BookReferences;
use App\Services\OrderAutomation\Process\ProcessProductReview;
use App\Support\AdminActivityLogger;
use App\Support\ProductPersonalizationSchema;
use App\Support\ProductProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** An explicit, per-child bridge for pre-automation books with manually uploaded covers. */
class LegacyBookAdmission
{
    public static function message(RuntimeException $error): string
    {
        if ($error instanceof LegacyBookReviewRequired) {
            return $error->getMessage();
        }

        return match ($error->getMessage()) {
            'book_story_link_ambiguous' => 'ربط الكتاب بالقصة لا يطابق الطفل؛ راجع المنتج والقصة المرتبطة به.',
            'book_waiting_linked_story' => 'أكمل القصة المرتبطة بهذا الطفل واعتماد هويتها قبل تجهيز صفحات الكتاب.',
            'book_story_pages_incomplete' => 'صفحات القصة غير مكتملة أو عدد صفحات الكتاب أقل منها؛ راجع القصة المرتبطة.',
            'book_story_reference_changed', 'book_reference_checksum' => 'إحدى الصور المرتبطة تغيرت أو تعذر قراءتها؛ راجع المرفقات قبل الاعتماد.',
            default => 'تعذر فحص الكتاب الآن. أعد فتح الصفحة، وإذا استمر الخطأ راجع صور الطفل والكفر وإعدادات المنتج.',
        };
    }

    public function authorize(OrderItem $item, User $actor): void
    {
        abort_unless(app(OrderAccess::class)->controls($item->order, $actor)
            && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage'), 403);
    }

    public static function candidates()
    {
        return OrderItem::whereIn('item_type', ['product', 'product_add_on'])
            ->whereHas('product.productionComponents', fn ($q) => $q->where('is_active', true)->where('production_stage', 'book_cover'))
            ->whereNotIn('id', LegacyBookCoverApproval::select('order_item_id'))
            ->whereNotIn('id', PuzzleGeneration::select('order_item_id'))
            ->whereNotIn('id', CodexSceneRun::whereNotNull('order_item_id')->select('order_item_id'))
            ->whereHas('order', fn ($q) => $q->whereNotIn('checkout_group_key', OrderProcessRun::select('checkout_key')))
            ->where(fn ($q) => $q->whereDoesntHave('productionComponents', fn ($q) => $q->where('production_stage', 'book_cover'))
                ->orWhereHas('order.previews', fn ($q) => $q->whereHas('productGallery', fn ($q) => $q->where('status', 'active'))));
    }

    public function candidate(OrderItem $item): bool
    {
        $item->loadMissing('productionComponents', 'product.productionComponents');

        return ($item->item_snapshot['legacy_book_adopted'] ?? false) || self::candidates()->whereKey($item->id)->exists();
    }

    private function ensureIdle(OrderItem $item): void
    {
        $order = $item->order;
        $key = $order->checkoutGroupKey();
        if (! config('product_review.enabled') || ! $this->candidate($item)
            || ! in_array($order->status, ['new_confirmed', 'waiting_customer', 'preview_uploaded', 'ready_preview', 'costomer_confirmed', 'pending_team_review', 'delivered'], true)
            || $order->printing_status !== 'not_started' || $order->shipping_status !== 'not_ready') {
            throw new LegacyBookReviewRequired('هذا الكتاب غير مؤهل للتجهيز القديم، أو بدأت طباعته أو شحنه.');
        }
        if (AutomationControl::blocked($key) || AutomationHandoff::held($key)) {
            throw new LegacyBookReviewRequired('الطلب متوقف يدويًا؛ استأنف الأوتوميشن أولًا ثم جهّز الكتاب.');
        }
        if (OrderProcessRun::where('checkout_key', $key)->exists()
            || PuzzleGeneration::where('order_item_id', $item->id)->exists()
            || CodexSceneRun::where('order_item_id', $item->id)->exists()
            || app(AutomationAssignment::class)->owns($order, false)) {
            throw new LegacyBookReviewRequired('للطلب تنفيذ مسجل بالفعل؛ استخدم إجراء المراجعة أو الاستئناف الخاص به بدل تجهيز كتاب قديم.');
        }
    }

    /** Prepare a copy only: GET never changes a component, order, file or approval. */
    private function prepared(OrderItem $item): OrderItem
    {
        $copy = clone $item;
        if ($item->item_snapshot['legacy_book_adopted'] ?? false) {
            return $copy;
        }
        $modern = $item->productionComponents->contains('production_stage', 'book_cover');
        $components = $modern ? $item->productionComponents : $item->product->productionComponents->where('is_active', true)->values();
        $variant = $item->product_variant_id ? $item->product->variants()->find($item->product_variant_id) : null;
        $pages = $variant?->book_page_count ?? $components->firstWhere('production_stage', 'book_interior')?->quantity_per_item;
        if (($item->product_variant_id && (! $variant?->book_page_count || (int) data_get($item->variant_snapshot, 'id') !== $variant->id
                || data_get($item->variant_snapshot, 'name_ar') !== $variant->name_ar)) || ! $pages
            || ($components->firstWhere('production_stage', 'book_cover')?->quantity_per_item !== 1)
            || preg_match('/\bA3\b/i', json_encode($item->variant_snapshot ?? []))
            || $components->count() !== 2 || $components->where('production_stage', 'book_cover')->count() !== 1
            || $components->where('production_stage', 'book_interior')->count() !== 1) {
            throw new LegacyBookReviewRequired('عدد صفحات الاختيار أو مكونات الكتاب غير مكتملة. راجع اختيار المنتج وإعداداته قبل التجهيز.');
        }
        $copy->variant_snapshot = array_merge($item->variant_snapshot ?? [], ['book_page_count' => (int) $pages]);
        // Retiring a component does not free its unique item/key pair. Reserve
        // archived keys too, keeping every historical ID, brief and unit intact.
        $reserved = $modern ? [] : DB::table('order_item_production_components')->where('order_item_id', $item->id)->pluck('stable_key')->flip()->all();
        $copy->setRelation('productionComponents', $components->map(function ($c) use ($modern, $pages, &$reserved) {
            $key = $c->stable_key;
            if (! $modern && isset($reserved[$key])) {
                $base = 'legacy-'.$c->production_stage.'-'.$c->id;
                $key = $base;
                for ($suffix = 2; isset($reserved[$key]); $suffix++) {
                    $key = $base.'-'.$suffix;
                }
            }
            $reserved[$key] = true;

            return new OrderItemProductionComponent([
                'product_production_component_id' => $modern ? $c->product_production_component_id : $c->id, 'sort_order' => $c->sort_order, 'stable_key' => $key, 'name' => $c->name, 'production_stage' => $c->production_stage,
                'prompt_template' => $c->prompt_template, 'quantity_per_item' => $c->production_stage === 'book_interior' ? $pages : 1,
            ]);
        }));

        return $copy;
    }

    private function image(string $disk, string $path): array
    {
        if (! in_array($disk, array_unique(['s3_private', 'local', 'public', config('media.private_disk')]), true)
            || $path === '' || str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, '://')) {
            throw new LegacyBookReviewRequired('مرجع الصورة غير صالح؛ أعد رفع الصورة من صفحة الطلب.');
        }
        try {
            $storage = Storage::disk($disk);
            if (! $storage->exists($path) || $storage->size($path) > 15 * 1024 * 1024) {
                throw new RuntimeException;
            }
            $bytes = $storage->get($path);
            $size = @getimagesizefromstring($bytes);
            if (! $size || $size[0] * $size[1] > 25000000 || ! in_array($size['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new RuntimeException;
            }

            return ['disk' => $disk, 'path' => $path, 'checksum' => hash('sha256', $bytes), 'mime' => $size['mime']];
        } catch (\Throwable) {
            throw new LegacyBookReviewRequired('تعذر قراءة صورة صالحة. راجع الصورة أو أعد رفعها بصيغة JPG أو PNG أو WebP أقل من 15 ميجابايت.');
        }
    }

    private function capture(OrderItem $item, OrderPreview $preview): array
    {
        $order = $item->order;
        $unit = ProductProductionPrompt::forItem($item, true)->firstWhere('production_stage', 'book_interior');
        $cover = ProductProductionPrompt::forItem($item, true)->firstWhere('production_stage', 'book_cover');
        $fields = ProductPersonalizationSchema::formValues($item->personalization_snapshot ?? []);
        if (! $unit || ! $cover || (int) $unit['quantity_per_item'] < 1 || (int) $unit['quantity_per_item'] > config('product_review.max_pages')
            || trim($unit['prompt']) === '' || preg_match('/\{\{|\[MISSING/', $unit['prompt'])
            || (! empty($fields['child_name']) && trim($fields['child_name']) !== trim($order->child_name ?? ''))) {
            throw new LegacyBookReviewRequired('راجع اسم الطفل وبرومبت الصفحات وعددها؛ بيانات الكتاب غير مكتملة أو تخص طفلًا آخر.');
        }
        if ($preview->order_id !== $order->id || $preview->productGallery?->status !== 'active'
            || $preview->productGallery->checkout_group_key !== $order->checkoutGroupKey()
            || ($preview->production_unit_key && $preview->production_unit_key !== $cover['unit_key'])) {
            throw new LegacyBookReviewRequired('اختر معاينة نشطة تخص هذا الطفل وهذا الكتاب.');
        }
        $image = $this->image($preview->disk ?: 'public', $preview->file_path);
        if ($preview->checksum && ! hash_equals($preview->checksum, $image['checksum'])) {
            throw new LegacyBookReviewRequired('ملف الكفر تغير بعد رفعه؛ أعد رفع النسخة الصحيحة قبل اعتمادها.');
        }
        $photos = [];
        foreach ($order->uploaded_photos ?? [] as $path) {
            foreach (array_unique([config('media.private_disk', 'local'), 'public', 'local']) as $disk) {
                if (is_string($path) && Storage::disk($disk)->exists($path)) {
                    $photos[] = $this->image($disk, $path);

                    continue 2;
                }
            }
            throw new LegacyBookReviewRequired('إحدى صور الطفل غير موجودة؛ أعد رفعها قبل تجهيز الصفحات.');
        }
        if (! $photos || count($photos) > 10) {
            throw new LegacyBookReviewRequired('ارفع من صورة إلى 10 صور واضحة لهذا الطفل قبل تجهيز الكتاب.');
        }
        $references = app(BookReferences::class)->capture($item, $photos, (int) $unit['quantity_per_item']);

        return ['item_id' => $item->id, 'preview_id' => $preview->id, 'unit_key' => $unit['unit_key'], 'page_count' => (int) $unit['quantity_per_item'],
            'artwork_contract' => 'a4-interior-v1', 'references' => $references['references'], 'texts' => $references['texts'],
            'child' => $order->only(['id', 'child_name', 'child_age', 'child_gender', 'parent_notes', 'interests', 'story_id']),
            'variant' => $item->variant_snapshot, 'personalization' => $item->personalization_snapshot, 'quantity' => $item->quantity,
            'interior_fingerprint' => app(ProcessProductReview::class)->interiorFingerprint($item),
            'prompt' => $unit['prompt'], 'identity_disk' => $image['disk'], 'identity_path' => $image['path'], 'identity_checksum' => $image['checksum']];
    }

    public function offers(OrderItem $item): array
    {
        $this->ensureIdle($item);
        $prepared = $this->prepared($item);
        $offers = [];
        foreach (OrderPreview::where('order_id', $item->order_id)->whereHas('productGallery', fn ($q) => $q->where('status', 'active'))->with('productGallery')->latest('id')->limit(100)->get() as $preview) {
            try {
                $inputs = $this->capture($prepared, $preview);
                $offers[] = ['preview' => $preview, 'pages' => $inputs['page_count'], 'token' => Crypt::encryptString(json_encode([
                    'item' => $item->id, 'preview' => $preview->id, 'fingerprint' => $this->hash($inputs),
                    'approval' => LegacyBookCoverApproval::where('order_item_id', $item->id)->max('id'),
                ], JSON_THROW_ON_ERROR))];
            } catch (RuntimeException $e) {
                $offers[] = ['preview' => $preview, 'error' => self::message($e)];
            }
        }

        return $offers;
    }

    public function approve(OrderItem $item, User $actor, Request $request, string $token): LegacyBookCoverApproval
    {
        $this->authorize($item, $actor);
        try {
            $offer = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(409, 'انتهت صلاحية الاختيار؛ حدّث الصفحة.');
        }

        return DB::transaction(function () use ($item, $actor, $request, $offer) {
            $key = $item->order->checkoutGroupKey();
            abort_unless(AutomationControl::admits($key), 409, 'استأنف الطلب أولًا.');
            Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            $item = OrderItem::lockForUpdate()->findOrFail($item->id);
            $this->authorize($item, $actor);
            $this->ensureIdle($item);
            abort_unless($offer['item'] === $item->id && $offer['approval'] === LegacyBookCoverApproval::where('order_item_id', $item->id)->max('id'), 409, 'تغير اعتماد الكتاب؛ حدّث الصفحة.');
            $preview = OrderPreview::lockForUpdate()->findOrFail($offer['preview']);
            $prepared = $this->prepared($item);
            $inputs = $this->capture($prepared, $preview);
            abort_unless(hash_equals($offer['fingerprint'], $this->hash($inputs)), 409, 'تغيرت الصور أو بيانات الكتاب؛ حدّث الصفحة وراجع الكفر مجددًا.');
            $before = ['variant_snapshot' => $item->variant_snapshot, 'components' => $item->productionComponents->map->getAttributes()->all()];
            if (! ($item->item_snapshot['legacy_book_adopted'] ?? false)) {
                $modern = $item->productionComponents->contains('production_stage', 'book_cover');
                if (! $modern) {
                    $item->productionComponents()->update(['retired_at' => now()]);
                }
                $item->update(['variant_snapshot' => $prepared->variant_snapshot,
                    'item_snapshot' => array_merge($item->item_snapshot ?? [], ['legacy_book_adopted' => true])]);
                foreach ($prepared->productionComponents as $component) {
                    if ($modern) {
                        // Keep existing IDs/briefs and retain the previous quantity in the audit snapshot.
                        $item->productionComponents()->where('stable_key', $component->stable_key)->update(['quantity_per_item' => $component->quantity_per_item]);
                    } else {
                        $item->productionComponents()->create($component->getAttributes());
                    }
                }
            }
            $approval = LegacyBookCoverApproval::create(['order_item_id' => $item->id, 'order_preview_id' => $preview->id,
                'user_id' => $actor->id, 'configuration_before' => $before, 'inputs' => $inputs, 'fingerprint' => $this->hash($inputs)]);
            AdminActivityLogger::log('order.legacy_book_cover_approved', 'تجهيز كتاب قديم وتسجيل موافقة العميل على كفره الموجود بواسطة الفريق؛ دون توليد كفر أو إرسال رسالة.', $item->order,
                ['item_id' => $item->id, 'preview_id' => $preview->id, 'approval_id' => $approval->id, 'page_count' => $inputs['page_count']], $actor, $request);

            return $approval;
        }, 3);
    }

    public function approvedInputs(OrderItem $item): array
    {
        $approval = LegacyBookCoverApproval::where('order_item_id', $item->id)->latest('id')->first();
        if (! $approval || PuzzleGeneration::where('order_item_id', $item->id)->exists()) {
            throw new RuntimeException('Book approval unavailable');
        }
        $preview = OrderPreview::find($approval->order_preview_id);
        if (! $preview) {
            throw new RuntimeException('Book preview revoked');
        }
        $current = $this->capture($item, $preview);
        $expected = $approval->inputs;
        // Preserve consent across audited catalog-only template revisions, exactly
        // as modern books do. Child, notes, variants and reference changes still fail.
        if (app(BookPromptSync::class)->matchesInterior($item, $expected['interior_fingerprint'] ?? '')) {
            $expected['prompt'] = $current['prompt'];
            $expected['interior_fingerprint'] = $current['interior_fingerprint'];
        }
        if (! hash_equals($this->hash($expected), $this->hash($current))) {
            throw new RuntimeException('Book inputs changed after approval');
        }

        return ['manifest_id' => null, 'legacy_approval_id' => $approval->id] + $current;
    }

    private function hash(array $inputs): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$entry) {
                if (is_array($entry)) {
                    $entry = $canonical($entry);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($inputs), JSON_THROW_ON_ERROR));
    }
}
