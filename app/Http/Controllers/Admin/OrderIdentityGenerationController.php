<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\Order;
use App\Models\OrderIdentityRegeneration;
use App\Models\OrderProcessIdentity;
use App\Services\ChildIdentity\ChildIdentityApprovalService;
use App\Services\OrderAutomation\WorkflowInputs;
use App\Services\Orders\OrderIdentityRegenerationService;
use App\Services\Orders\PreviewRegenerationGuard;
use App\Services\Orders\PrivateOrderThumbnail;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderIdentityGenerationController extends Controller
{
    public function regenerate(Request $request, Order $order, OrderIdentityRegenerationService $service)
    {
        $data = $request->validate(['previous_attempt_id' => ['required', 'integer', 'min:1'],
            'prompt' => ['required', 'string', 'min:50', 'max:65000'], 'confirm' => ['required', 'accepted']]);
        $service->request($order, $request->user(), $data['previous_attempt_id'], $data['prompt']);

        return back()->with('success', 'بدأت محاولة هوية جديدة بالبرومبت المحدد وصور الطلب الحالية. ستُرفق دون اعتماد أو إرسال تلقائي، مع حفظ النسخ السابقة.');
    }

    public function image(Order $order, ChildIdentityGenerationAttempt $attempt)
    {
        abort_unless($order->child_identity_request_id === $attempt->child_identity_request_id && $attempt->output_disk && $attempt->output_storage_path, 404);

        if (request()->boolean('thumbnail')) {
            return app(PrivateOrderThumbnail::class)->response($attempt->output_disk, $attempt->output_storage_path);
        }

        return Storage::disk($attempt->output_disk)->response($attempt->output_storage_path, 'identity-attempt-'.$attempt->attempt_number, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, Order $order, ChildIdentityGenerationAttempt $attempt)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        app(PreviewRegenerationGuard::class)->authorize($request->user());
        DB::transaction(function () use ($request, $order, $attempt) {
            Order::where(fn ($q) => $q->whereKey($order->id)->orWhere('checkout_group_key', $order->checkoutGroupKey()))->orderBy('id')->lockForUpdate()->get();
            $order = $order->fresh();
            app(PreviewRegenerationGuard::class)->check($order);
            abort_unless($order->child_identity_request_id === $attempt->child_identity_request_id
                && $order->childIdentityRequest->attempts()->max('id') === $attempt->id
                && ! $order->childIdentityRequest->orders()->whereKeyNot($order->id)->exists(), 422, 'هذه ليست آخر محاولة لهذا الطلب.');
            $manual = OrderIdentityRegeneration::where('attempt_id', $attempt->id)->first();
            $automatic = OrderProcessIdentity::where('order_id', $order->id)->where('attempt_id', $attempt->id)->latest('id')->first();
            abort_unless(($manual && $manual->status === 'succeeded') || ($automatic && $automatic->status === 'succeeded'), 422, 'المحاولة غير جاهزة للاعتماد.');
            $current = app(WorkflowInputs::class)->capture($order, ['story_ids' => [], 'photo_limit' => 3, 'prompt' => '{{identity_prompt}}']);
            abort_unless(hash_equals(($manual ?? $automatic)->context['fingerprint'], $current['fingerprint']), 422, 'تغيّرت صور الطفل أو بياناته بعد التوليد؛ راجعها قبل الاعتماد.');
            abort_unless($attempt->status === 'succeeded' && hash_equals($attempt->output_checksum, hash('sha256', Storage::disk($attempt->output_disk)->get($attempt->output_storage_path))), 422);
            app(ChildIdentityApprovalService::class)->approve($order->childIdentityRequest, $attempt, $request->user(), 'admin');
            $order->refresh()->update(['status' => 'costomer_confirmed']);
            $order->statusLogs()->create(['status_type' => 'order', 'status' => 'costomer_confirmed', 'notes' => 'اعتمد الفريق محاولة الهوية من صفحة الطلب.']);
            AdminActivityLogger::log('order.identity_approved_by_staff', 'اعتماد محاولة الهوية بواسطة الفريق؛ لم يتم تسجيل دفع أو إرسال رسالة.', $order, ['attempt_id' => $attempt->id], $request->user());
        });

        return back()->with('success', 'تم اعتماد هذه الهوية فقط؛ الدفع ومتابعة العمل يظلان منفصلين.');
    }
}
