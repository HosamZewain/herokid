<?php

namespace App\Services\Orders;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderIdentityRegeneration;
use App\Models\OrderItem;
use App\Models\OrderPreviewStaffApproval;
use App\Models\OrderProcessEvent;
use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\OrderAutomation\Process\ProcessOrderContext;
use App\Services\OrderAutomation\Process\ProcessProductReview;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Staff attest to consent on an exact preview; this never fabricates a WhatsApp reply. */
class StaffPreviewApproval
{
    public function approve(Order $order, User $actor, Request $request, array $data): OrderPreviewStaffApproval
    {
        abort_unless(app(OrderAccess::class)->controls($order, $actor)
            && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage'), 403);

        return DB::transaction(function () use ($order, $actor, $request, $data) {
            $key = $order->checkoutGroupKey();
            $runs = OrderProcessRun::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
            $control = DB::table('order_automation_controls')->where('checkout_key', $key)->lockForUpdate()->first();
            $rows = Order::withTrashed()->where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->with('items')->get();
            $manifest = OrderReviewManifest::where('checkout_key', $key)->latest('id')->lockForUpdate()->first();
            $run = $runs->firstWhere('id', $manifest?->run_id);
            $deny = fn (string $message) => throw ValidationException::withMessages(['preview_approval' => $message]);
            abort_unless($manifest && $manifest->id === (int) $data['manifest_id'] && hash_equals($manifest->fingerprint, $data['fingerprint'])
                && $run && $run->version === (int) $data['run_version']
                && (int) ($control->version ?? 1) === (int) $data['control_version'], 409, 'تغيرت المعاينة أو حالة التنفيذ؛ حدّث الصفحة وراجع النسخة الحالية.');
            $rows = app(ProcessOrderContext::class)->forRun($run, true);
            if (! in_array($control->state ?? 'running', ['running', 'paused'], true)
                || ! in_array($run->status, ['waiting_response', 'waiting_staff', 'paused', 'blocked'], true)
                || $runs->whereNotIn('status', ['completed', 'cancelled'])->where('id', '!=', $run->id)->isNotEmpty()
                || CodexSceneRun::where('checkout_key', $key)->exists()
                || OrderIdentityRegeneration::where('checkout_key', $key)->whereIn('status', ['queued', 'processing', 'uncertain'])->exists()
                || PuzzleGeneration::where('checkout_key', $key)->where(fn ($q) => $q->whereIn('status', ['queued', 'processing', 'uncertain'])->orWhereNotNull('lease_until'))->exists()
                || $run->identities()->where('status', '!=', 'succeeded')->exists()
                || $run->steps()->whereIn('status', ['processing', 'uncertain', 'retry_pending'])->exists()
                || $run->steps()->whereHas('messageAttempts', fn ($q) => $q->whereIn('status', ['processing', 'uncertain', 'pending']))->exists()) {
                $deny('يوجد تنفيذ أو إرسال جارٍ أو نتيجة تحتاج مراجعة؛ لا يمكن اعتماد نسخة أخرى أو تكرار التوليد.');
            }
            if ($rows->contains(fn ($row) => $row->trashed() || ! in_array($row->status, ['new_confirmed', 'preview_uploaded', 'costomer_confirmed', 'pending_team_review', 'delivered'], true)
                || $row->printing_status !== 'not_started' || $row->shipping_status !== 'not_ready')) {
                $deny('لا يمكن اعتماد طلب غير مؤكد أو ملغى أو بدأ طباعته أو شحنه.');
            }
            $nodes = collect($run->revision->definition['nodes']);
            $approvalNode = $nodes->firstWhere('type', 'approve_previews');
            $reviewNode = $nodes->firstWhere('id', $approvalNode['next'] ?? null);
            $response = $run->steps()->where('node_id', $approvalNode['config']['response_node'] ?? '')->first();
            if (! $approvalNode || ($reviewNode['type'] ?? null) !== 'manual_review' || ! $response) {
                $deny('هذا المسار لا يحتوي معاينة مجمّعة صالحة للاعتماد؛ راجع المعاينة قبل التشغيل.');
            }
            // Status and ledger changes are legitimate while staff review payment.
            // All contact/child/item/price facts must still match the sent version.
            $contexts = app(ProcessOrderContext::class);
            $comparison = $rows->map(function ($row) use ($run) {
                $copy = clone $row;
                $copy->status = $run->context['order_statuses'][$row->id] ?? $run->context['status'];

                return $copy;
            });
            $snapshot = $contexts->snapshot($comparison, (int) ($run->context['fingerprint_version'] ?? 1));
            if (! hash_equals($run->context['fingerprint'], $snapshot['fingerprint'])) {
                if (app(ApprovedIdentityRecovery::class)->candidate($order)) {
                    $deny('الهوية المعتمدة أحدث من هذه المعاينة. استخدم «مراجعة الهوية المعتمدة الجديدة واستكمال الطلب» لمراجعة النسخة الحالية وتسجيل موافقتها.');
                }
                $deny('تغيرت بيانات الطلب بعد المعاينة. جهّز نسخة معاينة محدثة للعميل قبل تسجيل موافقته.');
            }
            try {
                $inputs = app(StaffPreviewInputs::class)->capture($manifest);
                if (! app(StaffPreviewInputs::class)->needsApproval($manifest, $inputs)) {
                    $deny('هذه النسخة معتمدة بالفعل؛ استخدم تشغيل الصفحات أو راجع التقدم.');
                }
                abort_unless(hash_equals(app(StaffPreviewInputs::class)->fingerprint($inputs), $data['inputs_fingerprint']), 409, 'تغير البرومبت أو عدد الصفحات؛ حدّث الصفحة وراجع البيانات.');
                if (! app(ProcessProductReview::class)->ready($run, $inputs)) {
                    $deny('المعاينات لم تكتمل بعد.');
                }
            } catch (HttpExceptionInterface $e) {
                throw $e;
            } catch (\RuntimeException) {
                $deny('صور المعاينة أو بيانات إنتاجها تغيرت أو غير مكتملة؛ لا تعتمد الصور القديمة.');
            }
            $events = RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->lockForUpdate()->get();
            if ($events->contains(fn ($event) => in_array($event->status, ['processing', 'unknown'], true))) {
                $deny('إرسال تأكيد الطلب لم يُحسم بعد؛ انتظر تسوية الإرسال.');
            }
            $wasApproved = (bool) $manifest->approved_at;
            $approval = OrderPreviewStaffApproval::create(['manifest_id' => $manifest->id, 'fingerprint' => $manifest->fingerprint,
                'user_id' => $actor->id, 'channel' => $data['channel'], 'note' => trim($data['note']), 'production_inputs' => $inputs]);
            app(ProcessProductReview::class)->approve($run, $approvalNode['config'], $approval);
            // Explicit staff consent settles only responses already present under
            // the checkout/run locks. Any later customer rejection still blocks AI.
            $context = $run->context;
            $context['staff_preview_approval'] = ['approval_id' => $approval->id, 'manifest_id' => $manifest->id,
                'response_checkpoint_id' => (int) DB::table('order_process_responses')->whereIn('run_id', $runs->modelKeys())->max('id')];
            $run->context = $context;
            // Settle the existing preview wait directly. Never replay the send, invent
            // a customer message, or execute arbitrary nodes from a historical path.
            $response->update(['status' => 'completed', 'completed_at' => now()]);
            $run->steps()->updateOrCreate(['node_id' => $approvalNode['id']], ['type' => 'approve_previews', 'status' => 'completed', 'completed_at' => now()]);
            $run->steps()->updateOrCreate(['node_id' => $reviewNode['id']], ['type' => 'manual_review', 'status' => 'waiting', 'started_at' => now()]);
            $run->fill(['status' => 'waiting_staff', 'node_id' => $reviewNode['id'], 'error_code' => null,
                'version' => $run->version + 1, 'next_at' => null, 'wait_until' => null]);
            $run->save();
            DB::table('order_automation_holds')->where('checkout_key', $key)->delete();
            foreach ($events->where('status', 'held') as $event) {
                $event->update(['status' => 'cancelled']);
            }
            DB::table('order_automation_controls')->updateOrInsert(['checkout_key' => $key], ['state' => 'running',
                'version' => (int) ($control->version ?? 1) + 1, 'error_code' => null, 'updated_at' => now()]);
            foreach ($inputs as $itemId => $input) {
                if ($wasApproved && $input['page_count'] !== null) {
                    OrderProcessEvent::create(['event_key' => 'staff-book-resume:'.$approval->id.':'.$itemId,
                        'type' => 'book_pages_resume', 'order_id' => OrderItem::findOrFail($itemId)->order_id,
                        'checkout_key' => $key, 'data' => ['staff_approval_id' => $approval->id, 'item_id' => $itemId], 'status' => 'processed']);
                }
            }
            AdminActivityLogger::log('order.preview_approved_by_staff', 'تسجيل موافقة العميل على المعاينة الحالية بواسطة الفريق واستكمال المتبقي المؤهل.', $order,
                ['approval_id' => $approval->id, 'manifest_id' => $manifest->id, 'fingerprint' => $manifest->fingerprint,
                    'channel' => $approval->channel, 'note' => $data['note'], 'previous_control_state' => $control->state ?? 'running',
                    'run_id' => $run->id, 'image_count' => count($manifest->images)], $actor, $request);

            return $approval;
        }, 3);
    }
}
