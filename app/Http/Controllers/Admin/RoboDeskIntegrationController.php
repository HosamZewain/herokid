<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPaymentProof;
use App\Models\RoboDeskIntegrationEvent;
use App\Services\Orders\OrderPaymentService;
use App\Services\RoboDesk\RoboDeskOutbox;
use App\Support\AdminActivityLogger;
use App\Support\OrderPaymentStatus;
use App\Support\OrderWorkflowStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RoboDeskIntegrationController extends Controller
{
    public function index(Request $request)
    {
        $events = RoboDeskIntegrationEvent::query()->latest()->paginate(20, ['*'], 'events_page')->withQueryString();
        $proofs = OrderPaymentProof::query()->with('reviewedBy:id,name')->latest()->paginate(12, ['*'], 'proofs_page')->withQueryString();

        return view('admin.robodesk.index', compact('events', 'proofs'));
    }

    public function retry(RoboDeskIntegrationEvent $event, RoboDeskOutbox $outbox): RedirectResponse
    {
        abort_unless(config('robodesk.enabled') && filled(config('robodesk.outbound_secret')), 422, 'أضف بيانات الاعتماد وفعّل التكامل أولاً.');
        $outbox->release($event);

        return back()->with('success', 'تمت إعادة الحدث إلى قائمة الإرسال.');
    }

    public function proof(OrderPaymentProof $proof): BinaryFileResponse
    {
        abort_unless(Storage::disk($proof->disk)->exists($proof->file_path), 404);

        return response()->file(Storage::disk($proof->disk)->path($proof->file_path), [
            'Content-Type' => $proof->mime_type,
            'Content-Disposition' => 'inline; filename="payment-proof"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approveProof(
        Request $request,
        OrderPaymentProof $proof,
        OrderPaymentService $payments,
        RoboDeskOutbox $outbox,
    ): RedirectResponse {
        abort_if($proof->status !== 'pending', 422, 'تمت مراجعة هذا الإثبات بالفعل.');
        $representative = Order::query()->where('checkout_group_key', $proof->checkout_group_key)->orderBy('id')->firstOrFail();

        DB::transaction(function () use ($request, $proof, $payments, $outbox, $representative): void {
            $payments->updateGroup(
                $representative,
                OrderPaymentStatus::PAID_IN_FULL,
                null,
                'انستاباي',
                $request->user(),
                $request,
            );

            $orders = Order::query()->where('checkout_group_key', $proof->checkout_group_key)->lockForUpdate()->get();
            foreach ($orders as $order) {
                $order->forceFill([
                    'status' => 'approved_for_print',
                    'printing_status' => OrderWorkflowStatus::PRINTING_READY,
                    'workflow_status_updated_by_user_id' => $request->user()->id,
                    'workflow_status_updated_at' => now(),
                ])->save();
                $order->statusLogs()->create([
                    'status_type' => 'printing',
                    'status' => OrderWorkflowStatus::PRINTING_READY,
                    'notes' => 'تم اعتماد إثبات دفع InstaPay يدويًا.',
                ]);
            }

            $proof->update([
                'status' => 'approved',
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'review_reason' => $request->string('reason')->trim()->toString() ?: null,
            ]);

            AdminActivityLogger::log(
                action: 'robodesk.payment_proof_approved',
                description: 'اعتماد إثبات دفع InstaPay لعملية الشراء '.$proof->checkout_group_key,
                subject: $proof,
                properties: ['checkout_group_key' => $proof->checkout_group_key],
                admin: $request->user(),
                request: $request,
            );

            $outbox->queue(
                'payment.verified',
                'payment.verified:'.$proof->id,
                $proof->checkout_group_key,
                $representative->id,
                ['payment_proof_id' => $proof->uuid],
            );
        });

        return back()->with('success', 'تم اعتماد الدفع وأصبح الطلب جاهزًا للطباعة.');
    }

    public function rejectProof(Request $request, OrderPaymentProof $proof, RoboDeskOutbox $outbox): RedirectResponse
    {
        abort_if($proof->status !== 'pending', 422, 'تمت مراجعة هذا الإثبات بالفعل.');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $proof->update([
            'status' => 'rejected',
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
            'review_reason' => $validated['reason'],
        ]);
        AdminActivityLogger::log(
            action: 'robodesk.payment_proof_rejected',
            description: 'رفض إثبات دفع InstaPay لعملية الشراء '.$proof->checkout_group_key,
            subject: $proof,
            properties: ['reason' => $validated['reason']],
            admin: $request->user(),
            request: $request,
        );
        $outbox->queue('payment.proof_rejected', 'payment.proof_rejected:'.$proof->id, $proof->checkout_group_key, null, [
            'payment_proof_id' => $proof->uuid,
            'reason' => $validated['reason'],
        ]);

        return back()->with('success', 'تم رفض الإثبات وتسجيل السبب.');
    }
}
