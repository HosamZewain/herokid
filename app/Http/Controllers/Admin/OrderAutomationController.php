<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AdvanceOrderAutomation;
use App\Models\AiModel;
use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRevision;
use App\Models\OrderAutomationRun;
use App\Models\Story;
use App\Services\OrderAutomation\WorkflowDefinition;
use App\Services\OrderAutomation\WorkflowService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderAutomationController extends Controller
{
    public function index(): mixed
    {
        return view('admin.order-automation.index', ['workflows' => OrderAutomation::with('revision')->get(), 'runs' => OrderAutomationRun::with('order')->when(! auth()->user()->hasPermission('orders.view'), fn ($q) => $q->whereRaw('1 = 0'))->latest('id')->paginate(25)]);
    }

    public function create(Request $request): mixed
    {
        $data = $request->validate(['name' => 'required|string|max:150']);
        $workflow = OrderAutomation::create(['name' => $data['name'], 'draft' => WorkflowDefinition::defaults()]);
        AdminActivityLogger::log('order_automation.created', 'إنشاء مسار أتمتة', $workflow);

        return redirect()->route('admin.order-automation.edit', $workflow);
    }

    public function edit(OrderAutomation $automation): mixed
    {
        return view('admin.order-automation.edit', ['workflow' => $automation, 'definition' => $automation->draft,
            'models' => AiModel::with('provider')->where('is_active', true)->get()->filter(fn ($model) => $model->supportsCapability('character_sheet')),
            'stories' => Story::orderBy('title')->get(['id', 'title']),
            'history' => OrderAutomationRevision::where('automation_id', $automation->id)->latest('id')->limit(20)->get()]);
    }

    public function save(Request $request, OrderAutomation $automation): mixed
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'draft_version' => 'required|integer', 'definition' => 'required|array']);
        $definition = app(WorkflowDefinition::class)->validate(array_replace(WorkflowDefinition::defaults(), $data['definition']));
        DB::transaction(function () use ($automation, $data, $definition): void {
            $locked = OrderAutomation::lockForUpdate()->findOrFail($automation->id);
            abort_unless((int) $locked->draft_version === (int) $data['draft_version'], 409, 'تم تعديل المسودة؛ أعد تحميل الصفحة.');
            $locked->update(['name' => $data['name'], 'draft' => $definition, 'draft_version' => $locked->draft_version + 1]);
            AdminActivityLogger::log('order_automation.draft_saved', 'حفظ مسودة الأتمتة', $locked);
        });

        return back()->with('success', 'حُفظت المسودة؛ التشغيل يستخدم النسخة المنشورة حتى تنشر هذه التعديلات.');
    }

    public function publish(Request $request, OrderAutomation $automation, WorkflowService $service): mixed
    {
        $data = $request->validate(['confirmed' => 'required|accepted', 'draft_version' => 'required|integer']);
        $service->publish($automation, $data['draft_version'], $request->user());

        return back()->with('success', 'نُشرت نسخة المسار وأُوقف التشغيل. يلزم تفعيل منفصل بعد مراجعة النسخة.');
    }

    public function activate(Request $request, OrderAutomation $automation, WorkflowService $service): mixed
    {
        $request->validate(['enabled' => 'required|boolean', 'confirmed' => 'required|accepted']);
        $service->activate($automation, $request->boolean('enabled'), $request->user());

        return back()->with('success', $request->boolean('enabled') ? 'تم التفعيل للطلبات الجديدة فقط.' : 'تم الإيقاف. قد يكمل المزوّد طلبًا سبق إرساله.');
    }

    public function trial(Request $request, OrderAutomation $automation, WorkflowService $service): mixed
    {
        $data = $request->validate(['order_id' => 'required|integer|exists:orders,id', 'request_id' => 'required|uuid', 'mode' => 'required|in:simulate,trial', 'confirmed' => 'required|accepted']);
        if ($data['mode'] === 'trial') {
            $request->validate(['confirmed_billable' => 'required|accepted', 'confirmed_processing' => 'required|accepted']);
        }
        $run = $service->start($automation, Order::findOrFail($data['order_id']), $data['mode'] === 'simulate', true, $request->user(), 'manual:'.$request->user()->id.':'.$data['request_id']);

        return redirect()->route('admin.order-automation.runs.show', $run);
    }

    public function show(OrderAutomationRun $run): mixed
    {
        return view('admin.order-automation.run', ['run' => $run->load(['revision', 'order', 'automation'])]);
    }

    public function action(Request $request, OrderAutomationRun $run): mixed
    {
        $data = $request->validate(['action' => 'required|in:review,resume,cancel', 'reason' => 'required|string|min:3|max:500', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($run, $request, $data): void {
            $locked = OrderAutomationRun::lockForUpdate()->findOrFail($run->id);
            if ($data['action'] === 'cancel') {
                $locked->update(['status' => 'cancelled', 'lease_token' => null, 'lease_until' => null]);
            } else {
                abort_if($locked->lease_until?->isFuture(), 409);
                if ($data['action'] === 'review') {
                    abort_unless($locked->status === 'review', 422);
                    $locked->context = array_merge($locked->context, ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()->toIso8601String()]);
                } else {
                    abort_unless(in_array($locked->status, ['blocked', 'waiting_inputs'], true), 422);
                    abort_unless(in_array($locked->error_code, ['missing_inputs', 'provider_unavailable', 'budget_limit', 'concurrency_limit', 'delivery_not_configured', 'output_missing'], true), 422, 'التشغيل يحتاج مراجعة؛ لا يمكن تكرار التوليد تلقائيًا.');
                }
                $locked->status = 'queued';
                $locked->next_at = now();
                $locked->save();
                AdvanceOrderAutomation::dispatch($locked->id)->afterCommit();
            }
            AdminActivityLogger::log('order_automation.'.$data['action'], 'إجراء على تشغيل الأتمتة', $locked, ['reason' => $data['reason']], $request->user());
        });

        return back()->with('success', 'تم تسجيل الإجراء.');
    }

    public function image(OrderAutomationRun $run): mixed
    {
        abort_if($run->dry_run || ! isset($run->context['output']), 404);
        $output = $run->context['output'];
        abort_unless($run->order && ! $run->order->trashed() && Storage::disk($output['disk'])->exists($output['path']), 404);

        return Storage::disk($output['disk'])->response($output['path'], 'identity-preview.'.$output['extension'], ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $output['mime']]);
    }
}
