<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RoboDesk\RoboDeskIntegrationRegistry;
use App\Services\RoboDesk\RoboDeskPayloadRenderer;
use App\Services\RoboDesk\RoboDeskSettings;
use App\Services\RoboDesk\RoboDeskTestRunner;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin > التكاملات > RoboDesk.
 *
 * A list of integrations; opening one gives three fields — API URL, token and
 * JSON payload — plus the variables that payload may reference. General
 * switches that are not integration config live on the same index page.
 */
class RoboDeskSettingsController extends Controller
{
    public function __construct(
        private readonly RoboDeskSettings $settings,
        private readonly RoboDeskIntegrationRegistry $integrations,
        private readonly RoboDeskPayloadRenderer $renderer,
        private readonly RoboDeskTestRunner $tester,
    ) {}

    public function index()
    {
        return view('admin.robodesk.settings.index', [
            'integrations' => $this->integrations->all(),
            'general' => [
                'enabled' => $this->settings->enabled(),
                'simulation_mode' => $this->settings->simulating(),
                'gate_order_confirmation' => $this->settings->bool('robodesk_gate_order_confirmation'),
                'gate_identity_confirmation' => $this->settings->bool('robodesk_gate_identity_confirmation'),
            ],
        ]);
    }

    public function edit(string $integrationKey)
    {
        $integration = $this->integrations->get($integrationKey);

        return view('admin.robodesk.settings.edit', [
            'integration' => $integration,
            'setting' => $integration->setting(),
        ]);
    }

    public function update(Request $request, string $integrationKey): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('robodesk.configure'), 403);

        $integration = $this->integrations->get($integrationKey);

        $validated = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'api_url' => ['nullable', 'string', 'max:500', 'url'],
            'token' => ['nullable', 'string', 'max:2000'],
            'payload_template' => ['nullable', 'string', 'max:20000'],
        ]);

        if ($error = $this->payloadError($integration->variables(), (string) ($validated['payload_template'] ?? ''))) {
            return back()->withErrors(['payload_template' => $error])->withInput();
        }

        // Enabling without somewhere to send is a configuration error, not a
        // runtime one — catch it here rather than parking events as `held`.
        if ($request->boolean('is_enabled') && blank($validated['api_url'] ?? null)) {
            return back()->withErrors(['api_url' => 'أدخل رابط الـ API قبل تفعيل التكامل.'])->withInput();
        }

        // Editing the URL or payload only needs robodesk.configure; changing
        // the secret is held to the stricter credentials permission.
        if (filled($validated['token'] ?? null)) {
            abort_unless($request->user()->hasPermission('robodesk.manage_credentials'), 403);
        }

        $this->integrations->save(
            $integrationKey,
            $request->boolean('is_enabled'),
            (string) ($validated['api_url'] ?? ''),
            $validated['token'] ?? null,
            (string) ($validated['payload_template'] ?? ''),
            $request->user(),
        );

        AdminActivityLogger::log(
            action: 'robodesk.integration_updated',
            description: 'حدّث المشرف تكامل RoboDesk: '.$integration->nameEn(),
            properties: ['integration' => $integrationKey, 'enabled' => $request->boolean('is_enabled')],
            request: $request,
        );

        return redirect()
            ->route('admin.robodesk.settings.edit', $integrationKey)
            ->with('success', 'تم حفظ إعدادات التكامل.');
    }

    /**
     * Fires the integration for real with sample values. Ignores simulation
     * mode on purpose — a test that never leaves the server proves nothing.
     */
    public function test(Request $request, string $integrationKey)
    {
        abort_unless($request->user()->hasPermission('robodesk.configure'), 403);

        $integration = $this->integrations->get($integrationKey);

        abort_unless($integration->configured(), 422, 'أدخل رابط الـ API أولًا.');

        $result = $this->tester->run($integration);

        AdminActivityLogger::log(
            action: 'robodesk.integration_tested',
            description: 'شغّل المشرف اختبار تكامل RoboDesk: '.$integration->nameEn(),
            properties: ['integration' => $integrationKey, 'reference' => $result['reference']],
            request: $request,
        );

        return response()->json($this->tester->status($result['reference']));
    }

    /** Polled by the test panel while it waits for RoboDesk to call back. */
    public function testStatus(Request $request, string $integrationKey, string $reference)
    {
        abort_unless($request->user()->hasPermission('robodesk.configure'), 403);
        abort_unless(RoboDeskTestRunner::isTestReference($reference), 404);

        $this->integrations->get($integrationKey);

        return response()->json($this->tester->status($reference))
            ->header('Cache-Control', 'no-store, private');
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('robodesk.configure'), 403);

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'simulation_mode' => ['nullable', 'boolean'],
            'gate_order_confirmation' => ['nullable', 'boolean'],
            'gate_identity_confirmation' => ['nullable', 'boolean'],
        ]);

        $this->settings->save([
            'robodesk_enabled' => $request->boolean('enabled') ? '1' : '0',
            'robodesk_simulation_mode' => $request->boolean('simulation_mode') ? '1' : '0',
            'robodesk_gate_order_confirmation' => $request->boolean('gate_order_confirmation') ? '1' : '0',
            'robodesk_gate_identity_confirmation' => $request->boolean('gate_identity_confirmation') ? '1' : '0',
        ]);

        AdminActivityLogger::log(
            action: 'robodesk.general_updated',
            description: 'حدّث المشرف الإعدادات العامة لـ RoboDesk.',
            properties: ['enabled' => $request->boolean('enabled')],
            request: $request,
        );

        return back()->with('success', 'تم حفظ الإعدادات العامة.');
    }

    /**
     * The payload is the one field that can be silently wrong, so it is checked
     * for valid JSON and for variables the integration cannot supply.
     */
    private function payloadError(array $variables, string $template): ?string
    {
        $template = trim($template);

        if ($template === '') {
            return null;
        }

        if (json_decode($template, true) === null && json_last_error() !== JSON_ERROR_NONE) {
            return 'قالب البيانات ليس JSON صالحًا.';
        }

        $unknown = $this->renderer->unknownPlaceholders($template, array_keys($variables));

        return $unknown === [] ? null : 'متغيرات غير معروفة: '.implode('، ', $unknown);
    }
}
