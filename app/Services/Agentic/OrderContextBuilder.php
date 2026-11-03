<?php

namespace App\Services\Agentic;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderAutomationRun;
use App\Models\OrderGroupMergeAlias;
use App\Models\OrderItem;
use App\Models\OrderPaymentEvent;
use App\Models\OrderPreview;
use App\Models\OrderPreviewStaffApproval;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Models\ProductionProject;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskIntegrationEvent;
use App\Services\CodexScenes\BookPageService;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\AutomationHandoff;
use App\Services\Orders\BookPromptSync;
use App\Services\Orders\OrderSceneTextService;
use App\Services\Orders\StaffPreviewInputs;
use App\Services\RoboDesk\OrderConfirmationService;
use App\Support\OrderStatusRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** SELECT-only projection. No ready(), poller, admission, URLs, prompts or media bytes. */
class OrderContextBuilder
{
    public function __construct(private ProductionSpecificationResolver $specifications) {}

    public function canonicalKey(string $key): string
    {
        $seen = [];
        for ($i = 0; $i < 16; $i++) {
            if (isset($seen[$key])) {
                throw new RuntimeException('checkout_alias_cycle');
            }
            $seen[$key] = true;
            $next = OrderGroupMergeAlias::where('source_checkout_group_key', $key)->value('target_checkout_group_key');
            if (! $next) {
                return $key;
            }
            $key = $next;
        }
        throw new RuntimeException('checkout_alias_depth');
    }

    public function build(string $key): array
    {
        $key = $this->canonicalKey($key);
        $orders = Order::withTrashed()->where('checkout_group_key', $key)->with(['items.product.productionComponents', 'items.productionComponents', 'childIdentityApprovedAttempt'])->orderBy('id')->get();
        if ($orders->isEmpty()) {
            throw new RuntimeException('checkout_not_found');
        }
        $activeIds = AutoPilotOrderScope::activeOrdersQuery()->whereIn('id', $orders->pluck('id'))->pluck('id')->all();
        $live = $orders->filter(fn ($o) => ! $o->trashed() && $o->status !== 'cancelled');
        $balances = $live->pluck('paid_amount_cents')->map(fn ($v) => (int) $v)->unique();
        $payment = [
            'positive_recorded' => $live->contains(fn ($o) => $o->paid_amount_cents > 0 && in_array($o->payment_status, ['partially_paid', 'paid_in_full', 'paid_without_shipping'], true)),
            'consistent' => $balances->count() <= 1,
            'ledger_ref' => OrderPaymentEvent::where('checkout_group_key', $key)->max('id'),
            'review_pending' => DB::table('order_payment_reviews')->where('checkout_key', $key)->where('status', 'pending')->exists(),
        ];
        $runs = OrderProcessRun::where('checkout_key', $key)->with('automation:id,enabled,accepts_new_runs')->orderBy('id')->get();
        $responses = DB::table('order_process_responses')->whereIn('run_id', $runs->pluck('id'))->orderBy('id')->get(['id', 'run_id', 'decision']);
        $latestResponse = $responses->last();
        $manifest = OrderReviewManifest::where('checkout_key', $key)->latest('id')->first();
        $staffApproval = $manifest ? OrderPreviewStaffApproval::where('manifest_id', $manifest->id)->first() : null;
        $staffInputs = $manifest ? app(StaffPreviewInputs::class)->recorded($manifest) : [];
        $control = DB::table('order_automation_controls')->where('checkout_key', $key)->first(['state', 'version']);
        $confirmation = RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->latest('id')->first();
        $held = AutomationHandoff::held($key);
        $ownership = app(AutomationAssignment::class)->owns($orders->first(), false);
        $context = [
            'schema_version' => 1, 'capability_registry_version' => CapabilityRegistry::VERSION, 'checkout_ref' => 'checkout:'.$orders->first()->id,
            'representative_id' => $orders->first()->id,
            'orders' => $orders->map(fn ($o) => ['order_ref' => 'order:'.$o->id, 'status' => $o->status, 'payment_status' => $o->payment_status,
                'order_behavior' => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $o->status),
                'shipping_behavior' => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_SHIPPING, $o->shipping_status),
                'printing_status' => $o->printing_status, 'shipping_status' => $o->shipping_status, 'deleted' => $o->trashed(), 'active' => in_array($o->id, $activeIds, true)])->all(),
            'all_complete' => $activeIds === [], 'hold' => $held, 'automation_owned' => $ownership,
            'automation_control' => ['state' => $control?->state ?? 'running', 'version' => (int) ($control?->version ?? 0)],
            'assignee_ref' => ($id = DB::table('order_group_assignments')->where('checkout_group_key', $key)->value('assigned_to_user_id')) ? 'staff:'.$id : null,
            'payment' => $payment,
            'confirmation' => ['required' => $live->contains('status', 'new'), 'status' => $confirmation?->status,
                'sendable' => $confirmation ? app(OrderConfirmationService::class)->canSend($confirmation) : false],
            'customer_interactions' => $responses->map(fn ($r) => ['response_ref' => 'response:'.$r->id, 'run_ref' => 'process:'.$r->run_id, 'decision' => $r->decision])->all(),
            'change_requested' => $latestResponse && in_array($latestResponse->decision, ['revise_identity', 'revise_preview', 'request_changes', 'reject'], true),
            'process_runs' => $runs->map(fn ($r) => ['run_ref' => 'process:'.$r->id, 'status' => $r->status, 'version' => $r->version,
                'node' => $r->node_id, 'enabled' => (bool) $r->automation?->enabled, 'accepts_new_runs' => (bool) $r->automation?->accepts_new_runs,
                'deadline_elapsed' => $r->wait_until?->isPast() ?? false])->all(),
            'review' => ['manifest_ref' => $manifest ? 'manifest:'.$manifest->id : null, 'approved' => (bool) $manifest?->approved_at,
                'staff_approval_ref' => $manifest?->approved_at && $staffApproval && hash_equals($manifest->fingerprint, $staffApproval->fingerprint) ? 'staff-approval:'.$staffApproval->id : null],
            'global_uncertain' => CodexSceneRun::where('status', 'uncertain')->exists(),
            'runtime' => ['scenes_enabled' => (bool) config('codex_scenes.enabled'), 'scenes_automatic' => (bool) config('codex_scenes.automatic'),
                'identity_enabled' => (bool) config('order_process.identity_enabled'), 'products_enabled' => (bool) config('puzzle_automation.enabled'),
                'reviews_enabled' => (bool) config('product_review.enabled'), 'book_pages_enabled' => (bool) config('product_review.book_pages_enabled')],
            'units' => [], 'operational' => ['outputs' => [], 'paid_amount_cents' => $balances->count() === 1 ? $balances->first() : null],
        ];
        foreach ($orders as $order) {
            $base = $this->orderFacts($order, in_array($order->id, $activeIds, true));
            if ($order->story_id) {
                $texts = app(OrderSceneTextService::class)->present($order);
                $run = CodexSceneRun::where('order_id', $order->id)->where('production_kind', 'story')->latest('created_at')->first();
                $identity = OrderProcessIdentity::where('order_id', $order->id)->latest('id')->first();
                $attempt = $identity ? ChildIdentityGenerationAttempt::find($identity->attempt_id)
                    : ($order->child_identity_request_id ? ChildIdentityGenerationAttempt::where('child_identity_request_id', $order->child_identity_request_id)->latest('id')->first() : null);
                $approved = $order->childIdentityApprovedAttempt;
                $context['units'][] = $base + [
                    'unit_key' => 'story:'.$order->id, 'capability' => 'story_scenes', 'supported' => true, 'product_ref' => 'story:'.$order->story_id,
                    'variant_ref' => null, 'expected_outputs' => 13, 'purchased_copies' => 1, 'spec_revision' => 'story-compat-v1',
                    'spec_fingerprint' => ContextFingerprint::privateInputs([$order->story?->updated_at, $texts['scenes']]),
                    'prompt_complete' => $texts['all_ready'] && count($texts['scenes']) === 13, 'variant_valid' => true,
                    'identity_approved' => $approved && $approved->status === 'succeeded' && filled($approved->output_checksum) && filled($approved->output_storage_path),
                    'identity_status' => $identity?->status ?? $attempt?->status,
                    'identity_ref' => $approved ? 'identity:'.$approved->id : null,
                    'production' => $this->production($run, 13), 'output_count' => $this->outputs($order, 'story:'.$order->id, $context),
                    'legacy_conflict' => OrderAutomationRun::where('order_id', $order->id)->whereNotIn('status', ['completed', 'cancelled', 'failed'])->exists()
                        || ProductionProject::where('order_id', $order->id)->whereHas('automationRuns', fn ($q) => $q->whereNotNull('active_project_id'))->exists(),
                ];
            }
            foreach ($order->items->whereIn('item_type', ['product', 'product_add_on']) as $item) {
                foreach ($this->specifications->forItem($item) as $spec) {
                    $bookPages = $spec['capability'] === 'book_interior';
                    $run = $bookPages ? CodexSceneRun::where('order_item_id', $item->id)->where('production_kind', 'coloring_book')->latest('created_at')->first()
                        : PuzzleGeneration::where('order_item_id', $item->id)->latest('id')->first();
                    $approvedImage = $manifest ? collect($manifest->images)->first(fn ($image) => ($image['item_id'] ?? null) === $item->id && ($image['kind'] ?? null) === 'book_cover') : null;
                    $generation = $bookPages ? PuzzleGeneration::where('order_item_id', $item->id)->latest('id')->first() : $run;
                    $preview = $generation?->preview_id ? OrderPreview::with('productGallery')->find($generation->preview_id) : null;
                    $coverApproved = $manifest?->approved_at && $approvedImage && $generation?->status === 'succeeded'
                        && in_array($manifest->run?->status, ['running', 'waiting_staff', 'completed'], true)
                        && ($approvedImage['generation_id'] ?? null) === $generation->id
                        && ($approvedImage['sha256'] ?? null) === $generation->checksum
                        && $preview && $preview->productGallery?->status === 'active'
                        && ($approvedImage['preview_id'] ?? null) === $preview->id
                        && ($approvedImage['path'] ?? null) === $preview->file_path && $preview->checksum === $generation->checksum
                        // Reuse the SELECT-only approved template lineage. Media,
                        // provider and byte-level capture remain executor preflight.
                        && (app(BookPromptSync::class)->matchesInterior($item, $approvedImage['interior_fingerprint'] ?? '')
                            || app(BookPromptSync::class)->matchesInterior($item, $staffInputs[(string) $item->id]['interior_fingerprint'] ?? ''));
                    $linked = $item->linked_order_item_id ? OrderItem::with('order')->find($item->linked_order_item_id) : null;
                    $linkedOrder = $linked?->order ?? ($order->story_id ? $order : null);
                    $linkValid = ! $item->linked_order_item_id || ($linked && $linked->item_type === 'story' && $linkedOrder
                        && $linkedOrder->checkoutGroupKey() === $key && $linkedOrder->child_name === $order->child_name
                        && (int) $linkedOrder->child_age === (int) $order->child_age && $linkedOrder->child_gender === $order->child_gender);
                    $linkedRun = $linkedOrder ? CodexSceneRun::where('order_id', $linkedOrder->id)->where('production_kind', 'story')->latest('created_at')->first() : null;
                    $context['units'][] = $base + $spec + [
                        'item_ref' => 'item:'.$item->id, 'identity_approved' => false, 'identity_status' => null,
                        'production' => $this->production($run, $spec['expected_outputs']), 'output_count' => $this->outputs($order, $spec['unit_key'], $context),
                        'cover_approved' => (bool) $coverApproved, 'linked_story_ref' => $linkedOrder ? 'story:'.$linkedOrder->id : null,
                        'book_admission_eligible' => $spec['capability'] === 'book_interior' && app(BookPageService::class)->statusEligible($order),
                        'linked_story_valid' => (bool) $linkValid, 'linked_story_complete' => ! $linkedOrder || ($linkedRun?->status === 'completed' && $spec['expected_outputs'] >= $linkedRun->scene_count),
                        'legacy_conflict' => false,
                    ];
                }
            }
        }
        $context['memory'] = app(MemoryService::class)->forContext($key, $context);
        $context['fingerprint'] = ContextFingerprint::hash($context);
        $context['observed_at'] = now()->toISOString();

        return $context;
    }

    private function orderFacts(Order $order, bool $active): array
    {
        return ['order_ref' => 'order:'.$order->id, 'child_ref' => 'child-for-order:'.$order->id, 'active' => $active,
            'order_status' => $order->status, 'has_photos' => count(array_filter($order->uploaded_photos ?? [], 'is_string')) > 0,
            'input_fingerprint' => ContextFingerprint::privateInputs([$order->only(['story_id', 'child_name', 'child_age', 'child_gender', 'uploaded_photos', 'interests', 'lesson', 'parent_notes', 'child_identity_approved_attempt_id']),
                $order->items->map(fn ($i) => $i->only(['id', 'quantity', 'product_variant_id', 'linked_order_item_id', 'variant_snapshot', 'personalization_snapshot']))])];
    }

    private function production(CodexSceneRun|PuzzleGeneration|null $run, int $expected): array
    {
        $numbers = $run instanceof CodexSceneRun ? array_map('intval', array_keys($run->scenes ?? [])) : ($run?->status === 'succeeded' ? [1] : []);
        sort($numbers);

        return ['run_ref' => $run ? ($run instanceof CodexSceneRun ? 'scenes:' : 'image:').$run->id : null, 'status' => $run?->status,
            'active' => in_array($run?->status, ['queued', 'processing', 'running', 'preparing', 'waiting_slot', 'stopping'], true),
            'uncertain' => $run?->status === 'uncertain', 'attempted' => $run !== null,
            'completed_numbers' => $numbers, 'missing_numbers' => array_values(array_diff(range(1, max(1, min(1000, $expected))), $numbers))];
    }

    private function outputs(Order $order, string $key, array &$context): int
    {
        $rows = OrderAttachment::where('order_id', $order->id)->where(fn ($q) => $q->where('production_unit_key', $key)->orWhereNull('production_unit_key'))->orderBy('id')->get(['id', 'production_unit_key', 'mime_type']);
        foreach ($rows as $row) {
            $context['operational']['outputs'][$row->id] = ['attachment_id' => $row->id, 'order_id' => $order->id, 'unit_key' => $row->production_unit_key, 'mime_type' => $row->mime_type];
        }

        return $rows->count();
    }
}
