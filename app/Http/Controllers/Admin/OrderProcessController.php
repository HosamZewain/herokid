<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRevision;
use App\Models\OrderProcessSimulation;
use App\Models\Product;
use App\Models\Story;
use App\Services\OrderAutomation\Process\ProcessDefinition;
use App\Services\OrderAutomation\Process\ProcessSimulator;
use App\Support\AdminActivityLogger;
use App\Support\OrderStatusRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderProcessController extends Controller
{
    public function create(Request $request): mixed
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'preset' => 'required|in:blank,confirmation']);
        $workflow = OrderAutomation::create(['name' => $data['name'], 'draft' => ProcessDefinition::defaults($data['preset'])]);
        AdminActivityLogger::log('order_process.created', 'إنشاء مسار عمليات', $workflow);

        return redirect()->route('admin.order-automation.process.edit', $workflow);
    }

    public function edit(OrderAutomation $automation): mixed
    {
        $this->guard($automation);

        return view('admin.order-automation.process-editor', ['workflow' => $automation,
            'definition' => $automation->draft, 'statuses' => OrderStatusRegistry::labels('order'),
            'stories' => Story::orderBy('title')->get(['id', 'title']), 'products' => Product::orderBy('name_ar')->get(['id', 'name_ar', 'name_en']),
            'history' => OrderAutomationRevision::where('automation_id', $automation->id)->latest('id')->limit(20)->get(),
            'simulations' => OrderProcessSimulation::where('automation_id', $automation->id)->latest('id')->paginate(15),
            'counts' => OrderProcessSimulation::where('automation_id', $automation->id)->select('status')->selectRaw('count(*) as total')->groupBy('status')->pluck('total', 'status')]);
    }

    public function save(Request $request, OrderAutomation $automation, ProcessDefinition $definition): mixed
    {
        $this->guard($automation);
        $data = $request->validate(['name' => 'required|string|max:150', 'draft_version' => 'required|integer|min:1', 'definition_json' => 'required|string|max:50000|json']);
        $input = json_decode($data['definition_json'], true);
        if (! is_array($input)) {
            throw ValidationException::withMessages(['definition_json' => 'تعريف المسار غير صالح.']);
        }
        $clean = $definition->validate($input);
        DB::transaction(function () use ($automation, $data, $clean) {
            $locked = OrderAutomation::lockForUpdate()->findOrFail($automation->id);
            abort_unless($locked->draft_version === (int) $data['draft_version'], 409, 'المسودة اتغيرت؛ حدّث الصفحة.');
            $locked->update(['name' => $data['name'], 'draft' => $clean, 'draft_version' => $locked->draft_version + 1]);
            AdminActivityLogger::log('order_process.saved', 'حفظ مسار العمليات', $locked);
        });

        return back()->with('success', 'حُفظت المسودة. النسخ السابقة ومحاكاتها لم تتغير.');
    }

    public function publish(Request $request, OrderAutomation $automation, ProcessDefinition $definition): mixed
    {
        $this->guard($automation);
        $data = $request->validate(['draft_version' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($automation, $request, $data, $definition) {
            $locked = OrderAutomation::lockForUpdate()->findOrFail($automation->id);
            abort_unless($locked->draft_version === (int) $data['draft_version'], 409);
            $clean = $definition->validate($locked->draft);
            $revision = OrderAutomationRevision::create(['automation_id' => $locked->id, 'definition' => $clean,
                'checksum' => hash('sha256', json_encode($clean, JSON_THROW_ON_ERROR)), 'published_by' => $request->user()->id, 'created_at' => now()]);
            $locked->update(['revision_id' => $revision->id, 'enabled' => false]);
            AdminActivityLogger::log('order_process.versioned', 'حفظ نسخة مسار للمحاكاة', $locked, ['revision_id' => $revision->id]);
        });

        return back()->with('success', 'حُفظت نسخة ثابتة للمحاكاة. لم يتم تفعيل تنفيذ حقيقي.');
    }

    public function simulate(Request $request, OrderAutomation $automation, ProcessSimulator $simulator): mixed
    {
        $this->guard($automation);
        $data = $request->validate(['request_id' => 'required|uuid', 'fixture' => 'required|array']);
        $run = $simulator->start($automation, $request->user(), $data['request_id'], $data['fixture']);

        return redirect()->route('admin.order-automation.process.simulation', $run);
    }

    public function show(OrderProcessSimulation $simulation): mixed
    {
        return view('admin.order-automation.process-simulation', ['run' => $simulation->load(['automation', 'revision'])]);
    }

    public function respond(Request $request, OrderProcessSimulation $simulation, ProcessSimulator $simulator): mixed
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'decision' => 'required|string|max:40']);
        $simulator->respond($simulation, $data['version'], $data['decision']);

        return back()->with('success', 'تمت متابعة المحاكاة بدون تنفيذ خارجي.');
    }

    private function guard(OrderAutomation $automation): void
    {
        abort_unless(($automation->draft['schema'] ?? 1) === 2, 404);
    }
}
