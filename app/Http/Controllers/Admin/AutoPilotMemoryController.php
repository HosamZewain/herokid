<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAgentRun;
use App\Models\AiCheckoutState;
use App\Models\AiMemory;
use App\Services\Agentic\MemoryService;
use Illuminate\Http\Request;

class AutoPilotMemoryController extends Controller
{
    public function index()
    {
        return response()->view('admin.agentic.memory.index', ['memories' => AiMemory::orderByDesc('updated_at')->paginate(30)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request)
    {
        $data = $request->validate(['run' => 'nullable|uuid|exists:ai_agent_runs,id']);
        $run = isset($data['run']) ? AiAgentRun::with('decision')->findOrFail($data['run']) : null;

        return response()->view('admin.agentic.memory.edit', ['memory' => null, 'versions' => collect(), 'run' => $run])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(AiMemory $memory)
    {
        return response()->view('admin.agentic.memory.edit', ['memory' => $memory,
            'versions' => $memory->versions()->orderByDesc('revision')->get(), 'run' => null])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, MemoryService $service)
    {
        [$input, $run] = $this->input($request);
        $memory = $service->save(null, $input, $request->user(), runId: $run?->id);

        return redirect()->route('admin.agentic.memory.show', $memory)->with('status', 'تم حفظ القاعدة ونسختها.');
    }

    public function update(Request $request, AiMemory $memory, MemoryService $service)
    {
        $request->validate(['revision' => 'required|integer|min:1']);
        [$input] = $this->input($request, $memory);
        $service->save($memory, $input, $request->user(), (int) $request->input('revision'));

        return redirect()->route('admin.agentic.memory.show', $memory)->with('status', 'تم حفظ نسخة جديدة. القرارات القادمة تستخدم القواعد النشطة المناسبة.');
    }

    public function restore(Request $request, AiMemory $memory, MemoryService $service)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'restore_revision' => 'required|integer|min:1']);
        $service->restore($memory, $data['restore_revision'], $data['revision'], $request->user());

        return redirect()->route('admin.agentic.memory.show', $memory)->with('status', 'تم استرجاع المحتوى كنسخة جديدة مع الاحتفاظ بالسجل.');
    }

    public function import(Request $request, MemoryService $service)
    {
        $count = $service->importOwnerReview($request->user());

        return redirect()->route('admin.agentic.memory.index')->with('status', 'تمت إضافة '.$count.' قواعد من مراجعتك، مع الحفاظ على أي قواعد سبق تعديلها.');
    }

    private function input(Request $request, ?AiMemory $memory = null): array
    {
        $data = $request->validate(['scope' => 'required|string|max:80', 'run_id' => 'nullable|uuid|exists:ai_agent_runs,id']);
        $run = ! $memory && isset($data['run_id']) ? AiAgentRun::findOrFail($data['run_id']) : null;
        $scope = $data['scope'];
        $input = $request->only('title', 'guidance', 'status', 'change_reason');
        $input['scope_type'] = str_starts_with($scope, 'capability:') ? 'capability' : $scope;
        $input['scope_key'] = str_starts_with($scope, 'capability:') ? substr($scope, 11) : '';
        if ($scope === 'checkout') {
            abort_unless($run || $memory?->scope_type === 'checkout', 422);
            $input['scope_key'] = $memory?->scope_key ?: AiCheckoutState::findOrFail($run->checkout_state_id)->checkout_key;
        }

        return [$input, $run];
    }
}
