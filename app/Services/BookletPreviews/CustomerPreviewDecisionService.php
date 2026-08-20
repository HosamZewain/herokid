<?php

namespace App\Services\BookletPreviews;

use App\Models\BookletPreview;
use App\Models\BookletPreviewDecision;
use App\Models\BookletPreviewVersion;
use App\Models\Order;
use App\Models\User;
use App\Services\Mobile\MobileNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerPreviewDecisionService
{
    public function __construct(private readonly MobileNotificationService $mobileNotifications) {}

    public function approve(Order $order, User $user, Request $request): BookletPreviewDecision
    {
        return $this->decide($order, $user, $request, 'approved');
    }

    public function requestRevision(Order $order, User $user, Request $request, int $pageNumber, string $comments): BookletPreviewDecision
    {
        return $this->decide($order, $user, $request, 'revision_requested', $pageNumber, $comments);
    }

    private function decide(Order $order, User $user, Request $request, string $decision, ?int $pageNumber = null, ?string $comments = null): BookletPreviewDecision
    {
        return DB::transaction(function () use ($order, $user, $request, $decision, $pageNumber, $comments): BookletPreviewDecision {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($lockedOrder->user_id === $user->id, 404);

            $preview = BookletPreview::query()->where('order_id', $lockedOrder->id)->where('status', 'active')->lockForUpdate()->first();
            $version = $preview?->currentVersion()->lockForUpdate()->first();
            if (! $preview || ! $version || $preview->current_version_id !== $version->id) {
                throw ValidationException::withMessages(['preview' => 'The current immutable preview version is unavailable.']);
            }
            if ($pageNumber !== null && ($pageNumber < 1 || $pageNumber > $version->page_count)) {
                throw ValidationException::withMessages(['page_number' => 'Select a page from the current preview version.']);
            }

            $existing = BookletPreviewDecision::query()->where('booklet_preview_version_id', $version->id)->first();
            if ($existing) {
                if ($existing->decision === $decision && $existing->user_id === $user->id) {
                    return $existing;
                }
                throw ValidationException::withMessages(['preview' => 'A final decision has already been recorded for this preview version.']);
            }
            if ($lockedOrder->status !== 'preview_uploaded') {
                throw ValidationException::withMessages(['preview' => 'There is no design currently awaiting your decision.']);
            }

            $record = BookletPreviewDecision::query()->create([
                'order_id' => $lockedOrder->id,
                'booklet_preview_id' => $preview->id,
                'booklet_preview_version_id' => $version->id,
                'user_id' => $user->id,
                'decision' => $decision,
                'page_number' => $pageNumber,
                'comments' => $comments,
                'device_installation_uuid' => $request->header('X-Device-Installation'),
                'device_fingerprint_hash' => $this->hash($request->userAgent()),
                'ip_hash' => $this->hash($request->ip()),
                'decided_at' => now(),
            ]);

            if ($decision === 'approved') {
                $lockedOrder->forceFill([
                    'approved_booklet_preview_version_id' => $version->id,
                    'preview_approved_at' => now(),
                    'status' => 'approved_for_print',
                ])->save();
                $notes = 'تم اعتماد النسخة '.$version->version_number.' من التصميم نهائياً بواسطة العميل.';
            } else {
                $lockedOrder->forceFill([
                    'approved_booklet_preview_version_id' => null,
                    'preview_approved_at' => null,
                    'status' => 'revision_requested',
                ])->save();
                $notes = 'طلب العميل تعديلاً على النسخة '.$version->version_number.'، الصفحة '.$pageNumber.'.';
            }
            $lockedOrder->statusLogs()->create(['status' => $lockedOrder->status, 'notes' => $notes]);
            $this->mobileNotifications->notifyOrder(
                $lockedOrder,
                $decision === 'approved' ? 'preview.approved' : 'preview.revision_requested',
                $decision === 'approved' ? 'تم اعتماد التصميم' : 'تم إرسال طلب التعديل',
                $decision === 'approved'
                    ? 'سجلنا موافقتك على النسخة الحالية من الطلب '.$lockedOrder->order_number.'.'
                    : 'وصل طلب تعديل النسخة الحالية من الطلب '.$lockedOrder->order_number.' إلى فريق HeroKid.',
            );

            return $record;
        });
    }

    private function hash(?string $value): ?string
    {
        return filled($value) ? hash_hmac('sha256', $value, (string) config('app.key')) : null;
    }
}
