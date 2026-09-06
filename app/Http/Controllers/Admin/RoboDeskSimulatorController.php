<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPaymentProof;
use App\Models\RoboDeskIntegrationEvent;
use App\Services\RoboDesk\PaymentProofService;
use App\Services\RoboDesk\RoboDeskInboundEventHandler;
use App\Services\RoboDesk\RoboDeskSettings;
use App\Support\AdminActivityLogger;
use App\Support\OrderStatusRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin > التكاملات > محاكاة RoboDesk.
 *
 * Walks a real checkout through the whole WhatsApp journey without RoboDesk
 * existing. Outbound messages are whatever simulation mode captured — the exact
 * payload a live send would have used — and the reply buttons call the same
 * inbound handler the webhook does, so the flow under test is the real one.
 */
class RoboDeskSimulatorController extends Controller
{
    /** Replies a customer can send back, and what each needs. */
    private const REPLIES = [
        'order.confirmed' => ['label' => 'العميل يؤكد الطلب', 'tone' => 'positive'],
        'order.rejected' => ['label' => 'العميل يرفض الطلب', 'tone' => 'negative', 'comment' => true],
        'identity.approved' => ['label' => 'اعتماد هوية الطفل', 'tone' => 'positive'],
        'identity.changes_requested' => ['label' => 'طلب تعديل الهوية', 'tone' => 'warning', 'comment' => true, 'requires_comment' => true],
        'preview.approved' => ['label' => 'اعتماد المنتج', 'tone' => 'positive'],
        'preview.changes_requested' => ['label' => 'طلب تعديل المنتج', 'tone' => 'warning', 'comment' => true],
        'payment.proof' => ['label' => 'إرسال إثبات دفع', 'tone' => 'positive'],
        'csat.submitted' => ['label' => 'إرسال تقييم', 'tone' => 'positive', 'comment' => true, 'score' => true],
    ];

    public function __construct(private readonly RoboDeskSettings $settings) {}

    public function index(Request $request)
    {
        $checkouts = RoboDeskIntegrationEvent::query()
            ->whereNotNull('checkout_group_key')
            ->select('checkout_group_key')
            ->selectRaw('MAX(created_at) as last_activity_at')
            ->selectRaw('COUNT(*) as message_count')
            ->groupBy('checkout_group_key')
            ->orderByDesc('last_activity_at')
            ->limit(50)
            ->get();

        return view('admin.robodesk.simulator.index', [
            'checkouts' => $checkouts,
            'recentOrders' => Order::query()->latest('id')->limit(15)->get(),
            'simulating' => $this->settings->simulating(),
        ]);
    }

    public function show(string $checkoutReference)
    {
        $orders = Order::query()
            ->where('checkout_group_key', $checkoutReference)
            ->orderBy('id')
            ->get();

        abort_if($orders->isEmpty(), 404, 'Checkout not found.');

        return view('admin.robodesk.simulator.show', [
            'checkoutReference' => $checkoutReference,
            'orders' => $orders,
            'events' => RoboDeskIntegrationEvent::query()
                ->where('checkout_group_key', $checkoutReference)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(),
            'replies' => self::REPLIES,
            'simulating' => $this->settings->simulating(),
            'statusLabels' => OrderStatusRegistry::labels(OrderStatusRegistry::TYPE_ORDER, false),
            'proofs' => OrderPaymentProof::query()
                ->where('checkout_group_key', $checkoutReference)
                ->latest('id')
                ->get(),
        ]);
    }

    public function reply(
        Request $request,
        string $checkoutReference,
        RoboDeskInboundEventHandler $handler,
        PaymentProofService $proofs,
    ): RedirectResponse {
        abort_unless($request->user()->hasPermission('robodesk.configure'), 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::REPLIES))],
            'comment' => ['nullable', 'string', 'max:2000'],
            'score' => ['nullable', 'integer', 'min:0', 'max:10'],
            'order_id' => ['nullable', 'integer'],
        ]);

        $order = $this->targetOrder($checkoutReference, $validated['order_id'] ?? null);

        try {
            if ($validated['type'] === 'payment.proof') {
                $this->simulatePaymentProof($checkoutReference, $proofs);
            } else {
                $this->simulateInbound($handler, $validated['type'], $this->replyData($checkoutReference, $order, $validated));
            }
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        AdminActivityLogger::log(
            action: 'robodesk.simulated_reply',
            description: 'محاكاة رد عميل عبر RoboDesk: '.$validated['type'],
            subject: $order,
            properties: ['checkout_group_key' => $checkoutReference, 'type' => $validated['type']],
            request: $request,
        );

        return back()->with('success', 'تمت محاكاة رد العميل: '.self::REPLIES[$validated['type']]['label']);
    }

    private function replyData(string $checkoutReference, ?Order $order, array $validated): array
    {
        $data = [
            'checkout_reference' => $checkoutReference,
            'comment' => $validated['comment'] ?? null,
            'contact_id' => 'simulator',
            'conversation_id' => 'simulator:'.$checkoutReference,
            'message_id' => 'sim-'.Str::lower(Str::random(12)),
        ];

        if ($order) {
            $data['order_id'] = $order->id;
            $data['order_number'] = $order->order_number;

            if ($order->childIdentityRequest) {
                $data['identity_uuid'] = $order->childIdentityRequest->uuid;
            }
        }

        if (isset($validated['score'])) {
            $data['score'] = $validated['score'];
        }

        return $data;
    }

    /**
     * Runs the inbound handler and records the reply as an inbound event, so the
     * thread shows both sides of the conversation exactly as a live webhook
     * delivery would have.
     */
    private function simulateInbound(RoboDeskInboundEventHandler $handler, string $type, array $data): void
    {
        DB::transaction(function () use ($handler, $type, $data): void {
            $handler->handle($type, $data);

            RoboDeskIntegrationEvent::query()->create([
                'event_id' => (string) Str::uuid(),
                'deduplication_key' => 'simulated:'.$type.':'.$data['message_id'],
                'direction' => 'inbound',
                'event_type' => $type,
                'aggregate_type' => 'checkout',
                'aggregate_id' => $data['checkout_reference'],
                'checkout_group_key' => $data['checkout_reference'],
                'order_id' => $data['order_id'] ?? null,
                'status' => 'succeeded',
                'attempts' => 1,
                'payload' => array_merge($data, ['simulated' => true]),
                'processed_at' => now(),
            ]);
        });
    }

    /**
     * A 1×1 PNG stands in for the customer's InstaPay screenshot so the proof
     * flow can be exercised without leaving the admin panel.
     */
    private function simulatePaymentProof(string $checkoutReference, PaymentProofService $proofs): void
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        $path = tempnam(sys_get_temp_dir(), 'robodesk-sim').'.png';
        file_put_contents($path, $bytes);

        try {
            $proofs->store(
                $checkoutReference,
                new UploadedFile($path, 'simulated-instapay-proof.png', 'image/png', null, true),
                [
                    'message_id' => 'sim-proof-'.Str::lower(Str::random(10)),
                    'conversation_id' => 'simulator:'.$checkoutReference,
                    'sender_phone' => 'simulator',
                ],
            );
        } finally {
            @unlink($path);
        }
    }

    private function targetOrder(string $checkoutReference, ?int $orderId): ?Order
    {
        $query = Order::query()->where('checkout_group_key', $checkoutReference);

        return $orderId
            ? $query->find($orderId)
            : $query->orderBy('id')->first();
    }
}
