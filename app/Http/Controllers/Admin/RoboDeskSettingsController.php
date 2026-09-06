<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RoboDesk\Actions\RoboDeskAction;
use App\Services\RoboDesk\RoboDeskActionRegistry;
use App\Services\RoboDesk\RoboDeskCredentialService;
use App\Services\RoboDesk\RoboDeskPayloadRenderer;
use App\Services\RoboDesk\RoboDeskSettings;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin > التكاملات > RoboDesk.
 *
 * Two concerns: the shared connection (endpoint, token, channel) and one
 * configurable block per action. Action params are validated against the
 * action's own schema, so a new action needs no changes here.
 */
class RoboDeskSettingsController extends Controller
{
    public function __construct(
        private readonly RoboDeskSettings $settings,
        private readonly RoboDeskCredentialService $credentials,
        private readonly RoboDeskActionRegistry $actions,
        private readonly RoboDeskPayloadRenderer $renderer,
    ) {}

    public function index(Request $request)
    {
        $selected = (string) $request->query('action', '');
        $actions = $this->actions->all();

        return view('admin.robodesk.settings', [
            'connection' => [
                'enabled' => $this->settings->enabled(),
                'base_url' => $this->settings->baseUrl(),
                'events_path' => $this->settings->eventsPath(),
                'auth_header' => $this->settings->authHeader(),
                'auth_scheme' => $this->settings->authScheme(),
                'default_channel' => $this->settings->defaultChannel(),
                'default_language' => $this->settings->defaultLanguage(),
                'timeout_seconds' => $this->settings->timeoutSeconds(),
                'signature_tolerance_seconds' => $this->settings->signatureToleranceSeconds(),
                'sign_outbound' => $this->settings->signsOutbound(),
                'whatsapp_number' => $this->settings->whatsAppNumber(),
                'instapay_url' => $this->settings->instaPayUrl(),
                'payment_proof_max_mb' => $this->settings->paymentProofMaxMb(),
            ],
            'credentials' => collect($this->credentials->types())
                ->mapWithKeys(fn (string $type): array => [$type => [
                    'label_ar' => config("robodesk.credentials.{$type}.name_ar", $type),
                    'masked' => $this->credentials->masked($type),
                    'configured' => $this->credentials->has($type),
                ]])
                ->all(),
            'actions' => $actions,
            'selected' => $actions->has($selected) ? $selected : $actions->keys()->first(),
            'warnings' => $this->warnings(),
        ]);
    }

    public function updateConnection(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('robodesk.configure'), 403);

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'base_url' => ['nullable', 'string', 'max:255', 'url'],
            'events_path' => ['nullable', 'string', 'max:255'],
            'auth_header' => ['nullable', 'string', 'max:100'],
            'auth_scheme' => ['nullable', 'string', 'max:40'],
            'default_channel' => ['nullable', 'string', 'max:120'],
            'default_language' => ['nullable', 'string', 'max:20'],
            'timeout_seconds' => ['nullable', 'integer', 'min:5', 'max:120'],
            'signature_tolerance_seconds' => ['nullable', 'integer', 'min:30', 'max:3600'],
            'sign_outbound' => ['nullable', 'boolean'],
            'whatsapp_number' => ['nullable', 'string', 'max:40'],
            'instapay_url' => ['nullable', 'string', 'max:500'],
            'payment_proof_max_mb' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $this->settings->save([
            'robodesk_enabled' => $request->boolean('enabled') ? '1' : '0',
            'robodesk_base_url' => rtrim((string) ($validated['base_url'] ?? ''), '/'),
            'robodesk_events_path' => (string) ($validated['events_path'] ?? ''),
            'robodesk_auth_header' => (string) ($validated['auth_header'] ?? 'Authorization'),
            'robodesk_auth_scheme' => (string) ($validated['auth_scheme'] ?? ''),
            'robodesk_default_channel' => (string) ($validated['default_channel'] ?? ''),
            'robodesk_default_language' => (string) ($validated['default_language'] ?? 'ar'),
            'robodesk_timeout_seconds' => (string) ($validated['timeout_seconds'] ?? 15),
            'robodesk_signature_tolerance_seconds' => (string) ($validated['signature_tolerance_seconds'] ?? 300),
            'robodesk_sign_outbound' => $request->boolean('sign_outbound') ? '1' : '0',
            'robodesk_whatsapp_number' => (string) ($validated['whatsapp_number'] ?? ''),
            'robodesk_instapay_url' => (string) ($validated['instapay_url'] ?? ''),
            'robodesk_payment_proof_max_mb' => (string) ($validated['payment_proof_max_mb'] ?? 10),
        ]);

        AdminActivityLogger::log(
            action: 'robodesk.connection_updated',
            description: 'حدّث المشرف إعدادات اتصال RoboDesk.',
            properties: ['enabled' => $request->boolean('enabled')],
            request: $request,
        );

        return back()->with('success', 'تم حفظ إعدادات الاتصال.');
    }

    public function updateCredential(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('robodesk.manage_credentials'), 403);

        $validated = $request->validate([
            'credential_type' => ['required', Rule::in($this->credentials->types())],
            'value' => ['nullable', 'string', 'min:8', 'max:2000'],
            'forget' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('forget')) {
            $this->credentials->forget($validated['credential_type']);
            $message = 'تم حذف المفتاح.';
        } else {
            abort_unless(filled($validated['value'] ?? null), 422, 'A value is required.');
            $this->credentials->save($validated['credential_type'], (string) $validated['value'], $request->user());
            $message = 'تم حفظ المفتاح مشفرًا.';
        }

        AdminActivityLogger::log(
            action: 'robodesk.credential_updated',
            description: 'حدّث المشرف مفتاح RoboDesk: '.$validated['credential_type'],
            properties: ['credential_type' => $validated['credential_type'], 'forgotten' => $request->boolean('forget')],
            request: $request,
        );

        return back()->with('success', $message);
    }

    public function updateAction(Request $request, string $actionKey): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('robodesk.configure'), 403);

        $action = $this->actions->get($actionKey);

        $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'params' => ['nullable', 'array'],
        ]);

        $params = (array) $request->input('params', []);
        $errors = $this->validateParams($action, $params);

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        $this->actions->save($actionKey, $request->boolean('is_enabled'), $params, $request->user());

        AdminActivityLogger::log(
            action: 'robodesk.action_updated',
            description: 'حدّث المشرف إعدادات إجراء RoboDesk: '.$action->labelEn(),
            properties: ['action' => $actionKey, 'enabled' => $request->boolean('is_enabled')],
            request: $request,
        );

        return redirect()
            ->route('admin.robodesk.settings.index', ['action' => $actionKey])
            ->with('success', 'تم حفظ إعدادات الإجراء.');
    }

    /**
     * The payload template is the one param that can be silently wrong, so it
     * is checked for valid JSON and for placeholders the action cannot supply.
     */
    private function validateParams(RoboDeskAction $action, array $params): array
    {
        $template = trim((string) ($params['payload_template'] ?? ''));

        if ($template === '') {
            return [];
        }

        if (json_decode($template, true) === null && json_last_error() !== JSON_ERROR_NONE) {
            return ['params.payload_template' => 'قالب البيانات ليس JSON صالحًا.'];
        }

        $unknown = $this->renderer->unknownPlaceholders($template, array_keys($action->variables()));

        if ($unknown !== []) {
            return ['params.payload_template' => 'متغيرات غير معروفة: '.implode('، ', $unknown)];
        }

        return [];
    }

    /** Surface configuration that will stop events from being delivered. */
    private function warnings(): array
    {
        $warnings = [];

        if ($this->settings->enabled() && ! $this->credentials->has('outbound_secret')) {
            $warnings[] = 'التكامل مفعّل لكن مفتاح توقيع الأحداث الصادرة غير محفوظ — كل الأحداث ستبقى معلّقة.';
        }

        if ($this->settings->enabled() && ! $this->credentials->has('inbound_secret')) {
            $warnings[] = 'مفتاح التحقق من الأحداث الواردة غير محفوظ — سيتم رفض كل الطلبات القادمة من RoboDesk.';
        }

        if ($this->settings->enabled() && $this->settings->baseUrl() === '') {
            $warnings[] = 'الرابط الأساسي لـ RoboDesk غير مضبوط.';
        }

        return $warnings;
    }
}
