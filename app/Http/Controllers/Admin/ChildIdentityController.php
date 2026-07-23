<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Models\ChildIdentityShareEvent;
use App\Models\Order;
use App\Services\ChildIdentity\ChildIdentityApprovalService;
use App\Services\ChildIdentity\ChildIdentityAttemptService;
use App\Services\ChildIdentity\ChildIdentityDeletionService;
use App\Services\ChildIdentity\ChildIdentityEventLogger;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class ChildIdentityController extends Controller
{
    public function index(Request $request)
    {
        $trash = $request->routeIs('admin.child-identities.trash')
            || $request->string('view')->toString() === 'trash';
        $query = $trash ? ChildIdentityRequest::onlyTrashed() : ChildIdentityRequest::query();
        $query->with([
            'selectedStory:id,title',
            'convertedOrder' => fn ($order) => $order->withTrashed()->select('id', 'order_number', 'status'),
            'attempts' => fn ($attempts) => $attempts->latest('attempt_number')->limit(1),
        ])->withCount(['photos', 'attempts']);

        if ($search = trim($request->string('q')->toString())) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('uuid', 'like', "%{$search}%")
                    ->orWhere('parent_name', 'like', "%{$search}%")
                    ->orWhere('parent_phone', 'like', "%{$search}%")
                    ->orWhere('parent_email', 'like', "%{$search}%")
                    ->orWhere('child_name', 'like', "%{$search}%")
                    ->orWhereHas('convertedOrder', fn ($orders) => $orders->withTrashed()->where('order_number', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($conversion = $request->string('conversion')->toString()) {
            $conversion === 'converted' ? $query->whereNotNull('converted_at') : $query->whereNull('converted_at');
        }

        if ($model = $request->string('model')->toString()) {
            $query->whereHas('attempts', fn ($attempts) => $attempts->where('model', $model));
        }

        if ($outcome = $request->string('outcome')->toString()) {
            $query->whereHas('attempts', fn ($attempts) => $attempts->where(
                'status',
                $outcome === 'success' ? 'succeeded' : 'failed'
            ));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $identities = $query->latest()->paginate(20)->withQueryString();
        $models = ChildIdentityGenerationAttempt::query()->distinct()->orderBy('model')->pluck('model');
        $stats = [
            'active' => ChildIdentityRequest::count(),
            'incomplete' => ChildIdentityRequest::where('status', 'incomplete')->count(),
            'generated' => ChildIdentityRequest::whereIn('status', ['generated', 'approved', 'story_selected', 'in_cart'])->count(),
            'converted' => ChildIdentityRequest::whereNotNull('converted_at')->count(),
            'trash' => ChildIdentityRequest::onlyTrashed()->count(),
        ];

        return view('admin.child-identities.index', compact('identities', 'models', 'stats', 'trash'));
    }

    public function show(int $identity, ChildIdentityAttemptService $attempts)
    {
        $identity = ChildIdentityRequest::withTrashed()
            ->with([
                'user',
                'photos',
                'attempts.photos',
                'attempts.initiator',
                'approvedAttempt',
                'selectedCategory',
                'selectedStory',
                'convertedOrder',
                'share.generationAttempt',
                'events.actor',
                'events.attempt',
                'events.order',
            ])
            ->findOrFail($identity);
        $media = ['photos' => collect(), 'attempts' => collect()];
        $shareMedia = collect();

        if (auth()->user()->hasPermission('child_identities.view_media')) {
            $media['photos'] = $identity->photos->mapWithKeys(fn ($photo) => [
                $photo->id => URL::temporarySignedRoute(
                    'admin.child-identities.media.photo',
                    now()->addMinutes(10),
                    ['identity' => $identity->id, 'photo' => $photo->id],
                ),
            ]);
            $media['attempts'] = $identity->attempts
                ->whereNotNull('output_storage_path')
                ->mapWithKeys(fn ($attempt) => [
                    $attempt->id => URL::temporarySignedRoute(
                        'admin.child-identities.media.attempt',
                        now()->addMinutes(10),
                        ['identity' => $identity->id, 'attempt' => $attempt->id],
                    ),
                ]);
            if ($identity->share) {
                $shareMedia = collect(ChildIdentityShare::VARIANTS)
                    ->filter(fn (string $variant): bool => filled($identity->share->cardPath($variant)))
                    ->mapWithKeys(fn (string $variant): array => [
                        $variant => URL::temporarySignedRoute(
                            'admin.child-identities.media.share-card',
                            now()->addMinutes(10),
                            ['share' => $identity->share->id, 'variant' => $variant],
                        ),
                    ]);
            }
        }
        AdminActivityLogger::log(
            'child_identity.viewed',
            'عرض تفاصيل طلب هوية طفل.',
            $identity,
            ['uuid' => $identity->uuid, 'status' => $identity->status],
        );
        $nextPrompt = $attempts->promptFor($identity);

        $sharePublicUrl = $identity->share
            ? route('child-identity-shares.show', $identity->share->public_token)
            : null;
        $shareChannelBreakdown = $identity->share
            ? ChildIdentityShareEvent::query()
                ->where('child_identity_share_id', $identity->share->id)
                ->whereNotNull('channel')
                ->selectRaw('channel, COUNT(*) as events_count')
                ->groupBy('channel')
                ->orderByDesc('events_count')
                ->pluck('events_count', 'channel')
            : collect();
        $referredIdentities = $identity->share
            ? ChildIdentityRequest::withTrashed()
                ->where('referred_by_child_identity_share_id', $identity->share->id)
                ->latest()
                ->limit(100)
                ->get(['id', 'status', 'created_at'])
            : collect();
        $referredOrders = $identity->share
            ? Order::withTrashed()
                ->where('referred_by_child_identity_share_id', $identity->share->id)
                ->latest()
                ->limit(100)
                ->get(['id', 'order_number', 'status', 'created_at'])
            : collect();

        return view('admin.child-identities.show', compact(
            'identity',
            'media',
            'nextPrompt',
            'shareMedia',
            'sharePublicUrl',
            'shareChannelBreakdown',
            'referredIdentities',
            'referredOrders',
        ));
    }

    public function updatePrompt(
        Request $request,
        int $identity,
        ChildIdentityEventLogger $events,
    ) {
        $identity = ChildIdentityRequest::withTrashed()->findOrFail($identity);
        abort_if($identity->trashed(), 422);
        $validated = $request->validate([
            'prompt_override' => ['nullable', 'string', 'min:50', 'max:20000'],
            'use_global_prompt' => ['nullable', 'boolean'],
        ]);
        $beforeHash = filled($identity->prompt_override)
            ? hash('sha256', (string) $identity->prompt_override)
            : null;
        $override = $request->boolean('use_global_prompt')
            ? null
            : trim((string) ($validated['prompt_override'] ?? ''));
        $override = $override !== '' ? $override : null;
        $identity->forceFill(['prompt_override' => $override])->save();
        $afterHash = $override ? hash('sha256', $override) : null;
        $events->record(
            $identity,
            'prompt.updated_by_admin',
            $override ? 'حدّث المشرف برومبت المحاولة القادمة.' : 'أعاد المشرف الطلب إلى البرومبت العام.',
            ['before_hash' => $beforeHash, 'after_hash' => $afterHash],
            actor: $request->user(),
            actorType: 'admin',
            source: 'admin',
        );
        AdminActivityLogger::log(
            'child_identity.prompt_updated',
            'تحديث برومبت إنشاء هوية طفل.',
            $identity,
            [
                'uuid' => $identity->uuid,
                'before_hash' => $beforeHash,
                'after_hash' => $afterHash,
                'uses_request_override' => $override !== null,
            ],
        );

        return back()->with('success', 'تم حفظ البرومبت. سيُستخدم في المحاولة القادمة فقط، بينما تبقى المحاولات السابقة ثابتة.');
    }

    public function generate(
        Request $request,
        int $identity,
        ChildIdentityAttemptService $attempts,
    ) {
        $identity = ChildIdentityRequest::withTrashed()->findOrFail($identity);
        abort_if($identity->trashed(), 422);
        $validated = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $attempt = $attempts->create($identity, $validated['idempotency_key'], 'admin', $request->user());
        AdminActivityLogger::log(
            'child_identity.generation_queued',
            'تشغيل توليد هوية طفل من الإدارة.',
            $identity,
            ['attempt_id' => $attempt->id, 'attempt_number' => $attempt->attempt_number],
        );

        return back()->with('success', 'تمت إضافة محاولة إدارية جديدة إلى قائمة الانتظار.');
    }

    public function approve(
        Request $request,
        int $identity,
        ChildIdentityGenerationAttempt $attempt,
        ChildIdentityApprovalService $approvals,
    ) {
        $identity = ChildIdentityRequest::findOrFail($identity);
        $previous = $identity->approved_attempt_id;
        $identity = $approvals->approve($identity, $attempt, $request->user(), 'admin', true);
        AdminActivityLogger::log(
            'child_identity.attempt_approved',
            'اعتماد محاولة هوية طفل من الإدارة.',
            $identity,
            ['attempt_id' => $attempt->id, 'previous_attempt_id' => $previous],
        );

        return back()->with('success', 'تم اعتماد المحاولة المختارة.');
    }

    public function reject(
        Request $request,
        int $identity,
        ChildIdentityGenerationAttempt $attempt,
        ChildIdentityApprovalService $approvals,
    ) {
        $identity = ChildIdentityRequest::findOrFail($identity);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $identity = $approvals->reject($identity, $attempt, $validated['reason'], $request->user());
        AdminActivityLogger::log(
            'child_identity.attempt_rejected',
            'رفض محاولة هوية طفل من الإدارة.',
            $identity,
            ['attempt_id' => $attempt->id, 'reason' => $validated['reason']],
        );

        return back()->with('success', 'تم رفض المخرج مع الاحتفاظ بالمحاولة والملف.');
    }

    public function destroy(
        Request $request,
        int $identity,
        ChildIdentityDeletionService $deletions,
        ChildIdentityEventLogger $events,
    ) {
        $identity = ChildIdentityRequest::findOrFail($identity);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'confirmation' => ['required', 'string'],
        ]);
        $this->confirmUuid($identity, $validated['confirmation']);
        $deletions->softDelete($identity, $validated['reason'], $request->user(), $request, $events);

        return redirect()->route('admin.child-identities.index')->with('success', 'تم نقل طلب الهوية إلى سلة المحذوفات دون حذف الملفات.');
    }

    public function restore(
        Request $request,
        int $identity,
        ChildIdentityDeletionService $deletions,
        ChildIdentityEventLogger $events,
    ) {
        $identity = ChildIdentityRequest::onlyTrashed()->findOrFail($identity);
        $deletions->restore($identity, $request->user(), $request, $events);

        return redirect()->route('admin.child-identities.show', $identity->id)->with('success', 'تمت استعادة الطلب.');
    }

    public function forceDelete(
        Request $request,
        int $identity,
        ChildIdentityDeletionService $deletions,
    ) {
        $identity = ChildIdentityRequest::onlyTrashed()->findOrFail($identity);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation' => ['required', 'string'],
        ]);
        $this->confirmUuid($identity, $validated['confirmation']);
        $deletions->forceDelete($identity, $validated['reason'], $request->user(), $request);

        return redirect()->route('admin.child-identities.trash')
            ->with('success', 'تم حذف الطلب ووسائطه نهائيًا وتسجيل بيان تدقيق.');
    }

    private function confirmUuid(ChildIdentityRequest $identity, string $confirmation): void
    {
        if (! hash_equals($identity->uuid, trim($confirmation))) {
            throw ValidationException::withMessages(['confirmation' => 'اكتب UUID الطلب كاملًا كما هو للتأكيد.']);
        }
    }
}
