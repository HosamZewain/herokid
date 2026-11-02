<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAgentRun;
use App\Models\AiCheckoutState;
use App\Models\Order;
use App\Models\Product;
use App\Services\Agentic\CapabilityRegistry;
use App\Services\Agentic\DecisionValidator;
use App\Services\Agentic\OrderContextBuilder;
use App\Services\Orders\AdminOrderGroupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AgenticAutomationController extends Controller
{
    public const STATES = ['unobserved', 'pending', 'partial', 'covered', 'quota_deferred', 'error', 'observation_error', 'inactive'];

    public function index(Request $request)
    {
        return response()->view('admin.agentic.index', $this->data($request))->header('Cache-Control', 'private, no-store');
    }

    public function monitor(Request $request)
    {
        return response()->json(['html' => view('admin.agentic._overview', $this->data($request))->render()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Order $order, OrderContextBuilder $builder, CapabilityRegistry $registry)
    {
        // Fresh read, independent of provider, scheduler, stored snapshot and feature switches.
        $context = $builder->build($order->checkoutGroupKey());
        $state = AiCheckoutState::where('checkout_key', $builder->canonicalKey($order->checkoutGroupKey()))->first();
        $history = AiAgentRun::where('checkout_state_id', $state?->id ?? 0)->with('decision')->latest('started_at')->paginate(30);
        $matches = $state?->fingerprint === $context['fingerprint'];
        if (! $request->user()->hasPermission('orders.statistics.view')) {
            unset($context['operational']['paid_amount_cents']);
        }

        return response()->view('admin.agentic.show', ['order' => $order, 'context' => $context, 'state' => $state,
            'history' => $history, 'matches' => $matches, 'targets' => $registry->targets($context), 'tasks' => $registry->humanTasks($context)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function data(Request $request): array
    {
        $filters = $request->validate(['state' => ['nullable', Rule::in(self::STATES)],
            'decision' => ['nullable', Rule::in(DecisionValidator::TYPES)],
            'product' => 'nullable|integer|min:1', 'scope' => 'nullable|in:active,all', 'page' => 'nullable|integer|min:1']);
        $active = app(AdminOrderGroupService::class)->activeOrdersQuery()
            ->whereNotIn('checkout_group_key', DB::table('order_group_merge_aliases')->select('source_checkout_group_key'));
        $activeKeys = (clone $active)->select('checkout_group_key')->distinct();
        $activeCount = DB::query()->fromSub($activeKeys, 'active')->count();
        $observed = AiCheckoutState::whereIn('checkout_key', $activeKeys)->whereNotNull('observed_at')->count();
        $covered = AiCheckoutState::whereIn('checkout_key', $activeKeys)->where('status', 'covered')->whereNull('dirty_at')->count();
        $base = ($filters['scope'] ?? 'active') === 'all' ? Order::query()
            ->whereNotIn('checkout_group_key', DB::table('order_group_merge_aliases')->select('source_checkout_group_key')) : clone $active;
        if ($filters['product'] ?? null) {
            $base->whereIn('checkout_group_key', Order::whereHas('items', fn ($q) => $q->where('product_id', $filters['product']))->select('checkout_group_key'));
        }
        $groups = DB::query()->fromSub($base->selectRaw('checkout_group_key, MIN(id) as representative_id')->groupBy('checkout_group_key'), 'g')
            ->leftJoin('ai_checkout_states as s', 's.checkout_key', '=', 'g.checkout_group_key');
        if ($state = $filters['state'] ?? null) {
            $state === 'unobserved' ? $groups->where(fn ($q) => $q->whereNull('s.observed_at')) : $groups->where('s.status', $state);
        }
        if ($decision = $filters['decision'] ?? null) {
            $groups->whereExists(function ($q) use ($decision) {
                $q->selectRaw('1')->from('ai_order_decisions as d')->join('ai_agent_runs as r', 'r.id', '=', 'd.agent_run_id')
                    ->whereColumn('r.checkout_state_id', 's.id')->where('d.decision->decision', $decision)
                    ->whereRaw('d.id = (select max(d2.id) from ai_order_decisions d2 join ai_agent_runs r2 on r2.id = d2.agent_run_id where r2.checkout_state_id = s.id)');
            });
        }
        $rows = $groups->select(['g.*', 's.id as state_id', 's.status', 's.context_version', 's.target_count', 's.covered_targets',
            's.error_code', 's.observed_at', 's.evaluated_at', 's.dirty_at'])->orderByRaw('CASE WHEN s.id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('s.last_attempt_at')->orderByDesc('g.representative_id')->paginate(25)
            ->withPath(route('admin.agentic.index'))->appends($filters);
        $orders = Order::whereIn('checkout_group_key', $rows->pluck('checkout_group_key'))->select('id', 'checkout_group_key', 'status')
            ->with('checkoutReference:id,checkout_group_key,short_reference')->get()->groupBy('checkout_group_key');
        $latest = AiAgentRun::whereIn('checkout_state_id', $rows->pluck('state_id')->filter())->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('ai_agent_runs as newer')->whereColumn('newer.checkout_state_id', 'ai_agent_runs.checkout_state_id')
                ->where(fn ($q) => $q->whereColumn('newer.started_at', '>', 'ai_agent_runs.started_at')
                    ->orWhere(fn ($q) => $q->whereColumn('newer.started_at', 'ai_agent_runs.started_at')->whereColumn('newer.id', '>', 'ai_agent_runs.id')));
        })->with('decision')->get()->keyBy('checkout_state_id');
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($orders, $latest) {
            $siblings = $orders->get($row->checkout_group_key, collect());
            $row->reference = $siblings->first()?->checkoutReference?->short_reference ?: 'Order '.$row->representative_id;
            $row->order_states = $siblings->pluck('status')->unique()->all();
            $row->covered_count = count(json_decode($row->covered_targets ?? '[]', true) ?: []);
            $row->latest = $latest->get($row->state_id);

            return $row;
        }));

        return ['rows' => $rows, 'filters' => $filters, 'products' => Product::orderBy('name_ar')->get(['id', 'name_ar']),
            'control' => DB::table('ai_runtime_states')->where('id', 1)->first(),
            'counts' => ['active' => $activeCount, 'unobserved' => max(0, $activeCount - $observed), 'unevaluated' => max(0, $activeCount - $covered), 'covered' => $covered],
            'states' => self::STATES];
    }
}
