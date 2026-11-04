<?php

namespace App\Services\Orders;

use App\Exceptions\LegacyBookReviewRequired;
use App\Models\CodexSceneRun;
use App\Models\LegacyBookCoverApproval;
use App\Models\Order;
use App\Models\OrderAutomationRun;
use App\Models\OrderIdentityRegeneration;
use App\Models\OrderItem;
use App\Models\OrderPreview;
use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Support\AdminActivityLogger;
use App\Support\ProductProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit consent on replacement artwork, never a fabricated AI generation. */
class ManualBookCoverApproval
{
    public function candidate(OrderItem $item): bool
    {
        return $item->productionComponents->contains('production_stage', 'book_cover')
            && $item->productionComponents->contains('production_stage', 'book_interior')
            && ! CodexSceneRun::where('order_item_id', $item->id)->exists();
    }

    private function state(OrderItem $item): array
    {
        $key = $item->order->checkoutGroupKey();

        return ['item_updated' => $item->updated_at->toISOString(),
            'control' => (array) DB::table('order_automation_controls')->where('checkout_key', $key)->first(['state', 'version']),
            'runs' => OrderProcessRun::where('checkout_key', $key)->orderBy('id')->get(['id', 'status', 'version'])->toArray(),
            'generation' => PuzzleGeneration::where('order_item_id', $item->id)->max('id'),
            'manifest' => OrderReviewManifest::where('checkout_key', $key)->max('id'),
            'approval' => LegacyBookCoverApproval::where('order_item_id', $item->id)->max('id')];
    }

    private function ensureIdle(OrderItem $item): void
    {
        $key = $item->order->checkoutGroupKey();
        $runs = OrderProcessRun::where('checkout_key', $key)->get();
        $control = DB::table('order_automation_controls')->where('checkout_key', $key)->first();
        $interior = $item->productionComponents->firstWhere('production_stage', 'book_interior');
        if ($item->product_variant_id && ((int) data_get($item->variant_snapshot, 'id') !== $item->product_variant_id
            || (int) data_get($item->variant_snapshot, 'book_page_count') !== (int) $interior?->quantity_per_item)
            || preg_match('/\bA3\b/i', json_encode($item->variant_snapshot ?? []))) {
            throw new LegacyBookReviewRequired('راجع الاختيار المشترى وعدد صفحات الكتاب؛ يجب أن يطابق عدد الصفحات اختيار المنتج قبل اعتماد الكفر.');
        }
        if (! config('product_review.enabled') || ! $this->candidate($item)
            || ! in_array($item->order->status, ['new_confirmed', 'waiting_customer', 'preview_uploaded', 'ready_preview', 'costomer_confirmed', 'pending_team_review', 'delivered'], true)
            || Order::withTrashed()->where('checkout_group_key', $key)->get()->contains(fn ($order) => $order->trashed() || $order->printing_status !== 'not_started' || $order->shipping_status !== 'not_ready')) {
            throw new LegacyBookReviewRequired('لا يمكن تغيير اعتماد الكفر بعد بدء الصفحات أو الطباعة أو الشحن، أو قبل تأكيد الطلب.');
        }
        if (! in_array($control->state ?? 'running', ['running', 'paused'], true)
            || $runs->contains(fn ($run) => ! in_array($run->status, ['waiting_response', 'waiting_staff', 'blocked', 'paused', 'completed', 'cancelled'], true))
            || CodexSceneRun::where('checkout_key', $key)->where('active_slot', 1)->exists()
            || OrderAutomationRun::whereIn('order_id', Order::where('checkout_group_key', $key)->select('id'))->where('dry_run', false)->whereNotIn('status', ['completed', 'cancelled', 'failed'])->exists()
            || OrderIdentityRegeneration::where('checkout_key', $key)->whereIn('status', ['queued', 'processing', 'uncertain'])->exists()
            || PuzzleGeneration::where('checkout_key', $key)->where(fn ($q) => $q->whereIn('status', ['queued', 'processing', 'uncertain'])->orWhereNotNull('lease_until'))->exists()
            || RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->whereIn('status', ['processing', 'unknown'])->exists()
            || $runs->contains(fn ($run) => $run->identities()->where('status', '!=', 'succeeded')->exists()
                || $run->steps()->whereIn('status', ['processing', 'uncertain', 'retry_pending'])->exists()
                || $run->steps()->whereHas('messageAttempts', fn ($q) => $q->whereIn('status', ['processing', 'uncertain', 'pending']))->exists())) {
            throw new LegacyBookReviewRequired('يوجد توليد أو إرسال جارٍ أو غير محسوم. انتظر انتهاءه أو الإيقاف الآمن قبل اعتماد الكفر البديل.');
        }
    }

    public function offers(OrderItem $item): array
    {
        $this->ensureIdle($item);
        $service = app(LegacyBookAdmission::class);
        $state = $this->state($item);

        return OrderPreview::where('order_id', $item->order_id)->whereHas('productGallery', fn ($q) => $q->where('status', 'active'))
            ->with('productGallery')->latest('id')->limit(100)->get()->map(function ($preview) use ($item, $service, $state) {
                try {
                    $inputs = $service->capture($item, $preview);

                    return ['preview' => $preview, 'pages' => $inputs['page_count'], 'token' => Crypt::encryptString(json_encode([
                        'purpose' => 'manual_replacement', 'item' => $item->id, 'preview' => $preview->id,
                        'fingerprint' => $service->hash($inputs), 'state' => $state], JSON_THROW_ON_ERROR))];
                } catch (RuntimeException $error) {
                    return ['preview' => $preview, 'error' => LegacyBookAdmission::message($error)];
                }
            })->all();
    }

    public function approve(OrderItem $item, User $actor, Request $request, string $token): LegacyBookCoverApproval
    {
        $service = app(LegacyBookAdmission::class);
        $service->authorize($item, $actor);
        try {
            $offer = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(409, 'انتهت صلاحية الاختيار؛ حدّث الصفحة.');
        }

        return DB::transaction(function () use ($item, $actor, $request, $offer, $service) {
            $key = $item->order->checkoutGroupKey();
            DB::table('order_automation_controls')->insertOrIgnore(['checkout_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
            $control = DB::table('order_automation_controls')->where('checkout_key', $key)->lockForUpdate()->first();
            $runs = OrderProcessRun::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
            Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            PuzzleGeneration::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
            $item = OrderItem::lockForUpdate()->findOrFail($item->id);
            $service->authorize($item, $actor);
            $this->ensureIdle($item);
            $state = $this->state($item);
            // A missing control row is semantically running/version1; GET never inserts it.
            $expected = $offer['state'] ?? [];
            if (($expected['control'] ?? null) === []) {
                $expected['control'] = ['state' => 'running', 'version' => 1];
            }
            abort_unless(($offer['purpose'] ?? null) === 'manual_replacement' && ($offer['item'] ?? null) === $item->id
                && $expected === $state, 409, 'تغيرت بيانات الكتاب أو حالة التنفيذ؛ حدّث الصفحة وراجع الكفر.');
            $preview = OrderPreview::lockForUpdate()->findOrFail($offer['preview']);
            $inputs = $service->capture($item, $preview);
            abort_unless(hash_equals($offer['fingerprint'], $service->hash($inputs)), 409, 'تغيرت الصور أو بيانات الطفل؛ حدّث الصفحة وراجع الكفر المحدد.');
            $cover = ProductProductionPrompt::forItem($item, true)->firstWhere('production_stage', 'book_cover');
            $preview->update(['production_unit_key' => $cover['unit_key'], 'checksum' => $inputs['identity_checksum']]);
            $approval = LegacyBookCoverApproval::create(['order_item_id' => $item->id, 'order_preview_id' => $preview->id,
                'user_id' => $actor->id, 'inputs' => $inputs, 'fingerprint' => $service->hash($inputs),
                'configuration_before' => ['source' => 'manual_replacement', 'generation_id' => $state['generation'], 'manifest_id' => $state['manifest'],
                    'previous_control' => $control->state, 'runs' => $state['runs']]]);
            // Retire the obsolete automatic wait, not its immutable sent manifest or
            // provider receipts. Other units need their own approval; nothing is resent.
            foreach ($runs->whereNotIn('status', ['completed', 'cancelled']) as $run) {
                $run->update(['status' => 'waiting_staff', 'error_code' => 'manual_artwork_review', 'next_at' => null, 'wait_until' => null,
                    'active_key' => null, 'version' => $run->version + 1]);
            }
            DB::table('order_automation_holds')->where('checkout_key', $key)->delete();
            RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->where('status', 'held')->update(['status' => 'cancelled']);
            DB::table('order_automation_controls')->where('checkout_key', $key)->update(['state' => 'running', 'version' => $control->version + 1, 'error_code' => null, 'updated_at' => now()]);
            AdminActivityLogger::log('order.manual_book_cover_approved', 'ربط الكفر البديل بالطفل والكتاب وتسجيل موافقة العميل بواسطة الفريق؛ دون إعادة الكفر أو إرسال رسالة.', $item->order,
                ['item_id' => $item->id, 'preview_id' => $preview->id, 'approval_id' => $approval->id, 'page_count' => $inputs['page_count'], 'previous_runs' => $state['runs']], $actor, $request);

            return $approval;
        }, 3);
    }

    public function validateReceipt(OrderItem $item, LegacyBookCoverApproval $approval): void
    {
        $before = $approval->configuration_before;
        $latest = OrderReviewManifest::where('checkout_key', $item->order->checkoutGroupKey())->latest('id')->first();
        if (($before['generation_id'] ?? null) !== PuzzleGeneration::where('order_item_id', $item->id)->max('id')
            || (($before['manifest_id'] ?? null) !== $latest?->id && collect($latest?->images ?? [])->contains(fn ($image) => ($image['item_id'] ?? null) === $item->id))) {
            throw new RuntimeException('Book inputs changed after approval');
        }
    }
}
