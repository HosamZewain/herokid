<?php

namespace App\Http\Controllers\Admin;

use App\DTOs\Notifications\NotificationTestRequest;
use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Models\Setting;
use App\Services\Notifications\NotificationCredentialService;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\OrderCreatedNotificationMessage;
use App\Services\Notifications\TelegramNotificationChannel;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class NotificationCenterController extends Controller
{
    public function __construct(
        private readonly NotificationCredentialService $credentials,
        private readonly NotificationSettings $settings,
        private readonly OrderCreatedNotificationMessage $orderCreatedMessage,
    ) {}

    public function index(Request $request)
    {
        $this->ensureDefaults();

        $telegram = $this->credentials->channel('telegram')->load('credentials');
        $eventDefinitions = config('admin_notifications.events', []);
        $rules = NotificationRule::query()
            ->whereIn('event_key', array_keys($eventDefinitions))
            ->orderBy('event_key')
            ->get()
            ->keyBy(fn (NotificationRule $rule): string => $rule->event_key.'|'.$rule->channel_type);

        $deliveries = auth()->user()->hasPermission('settings.notifications.view_logs')
            ? NotificationDelivery::query()
                ->latest()
                ->paginate(15)
                ->withQueryString()
            : null;

        $stats = [
            'active_channels' => NotificationChannel::query()->where('is_active', true)->count(),
            'enabled_events' => NotificationRule::query()->where('is_enabled', true)->count(),
            'failed_deliveries' => NotificationDelivery::query()->where('status', 'failed')->count(),
            'recent_deliveries' => NotificationDelivery::query()->where('created_at', '>=', now()->subDay())->count(),
            'last_stuck_check' => setting('notification_last_stuck_check_run_at'),
        ];

        return view('admin.settings.notifications.index', [
            'telegram' => $telegram,
            'telegramMask' => $this->credentials->masked($telegram),
            'eventDefinitions' => $eventDefinitions,
            'rules' => $rules,
            'deliveries' => $deliveries,
            'stats' => $stats,
            'notificationSettings' => $this->settingsForView(),
            'orderCreatedTemplateVariables' => OrderCreatedNotificationMessage::VARIABLE_LABELS,
        ]);
    }

    public function updateTelegram(Request $request)
    {
        $telegram = $this->credentials->channel('telegram')->load('credentials');
        $canManageSettings = auth()->user()->hasPermission('settings.notifications.manage');
        $canManageCredentials = auth()->user()->hasPermission('settings.notifications.manage_credentials');

        abort_unless($canManageSettings || ($request->filled('bot_token') && $canManageCredentials), 403);

        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'default_chat_id' => ['nullable', 'string', 'max:120'],
            'additional_chat_ids' => ['nullable', 'string', 'max:2000'],
            'bot_token' => ['nullable', 'string', 'min:10', 'max:500'],
            'confirm_replace_credential' => ['nullable'],
        ]);

        if ($request->filled('bot_token')) {
            abort_unless($canManageCredentials, 403);
            $this->rateLimit($request, 'telegram-token-save', 5);

            if ($telegram->credentials->isNotEmpty() && ! $request->boolean('confirm_replace_credential')) {
                return back()->withErrors(['bot_token' => 'استبدال توكن Telegram الحالي يتطلب تأكيدًا صريحًا.'])->withInput();
            }
        }

        $settings = $telegram->settings_json ?? [];
        if ($canManageSettings) {
            $settings['default_chat_id'] = trim((string) ($validated['default_chat_id'] ?? ''));
            $settings['additional_chat_ids'] = $this->parseChatIds((string) ($validated['additional_chat_ids'] ?? ''));
        }

        $wantsActive = $canManageSettings ? $request->boolean('is_active') : $telegram->is_active;
        $hasToken = $request->filled('bot_token') || $this->credentials->hasToken($telegram);

        if ($wantsActive && (! $hasToken || blank($settings['default_chat_id']))) {
            return back()->withErrors(['is_active' => 'لا يمكن تفعيل Telegram قبل حفظ توكن وتحديد Chat ID افتراضي.'])->withInput();
        }

        $before = $telegram->only(['is_active', 'settings_json']);
        if ($canManageSettings) {
            $telegram->forceFill([
                'is_active' => $wantsActive,
                'settings_json' => $settings,
            ])->save();
        }

        if ($request->filled('bot_token')) {
            $this->credentials->saveToken($telegram->refresh(), $validated['bot_token'], auth()->user());
            AdminActivityLogger::log('notifications.telegram_token_saved', 'تم حفظ توكن Telegram مشفرًا.', $telegram, [
                'channel_type' => 'telegram',
            ]);
        }

        if ($canManageSettings) {
            AdminActivityLogger::log('notifications.telegram_settings_updated', 'تم تحديث إعدادات Telegram في مركز التنبيهات.', $telegram, [
                'changes' => AdminActivityLogger::changedValues($before, $telegram->fresh()->only(array_keys($before))),
            ]);
        }

        return back()->with('success', 'تم حفظ إعدادات Telegram.');
    }

    public function removeTelegramToken(Request $request)
    {
        $this->rateLimit($request, 'telegram-token-remove', 5);
        $request->validate(['confirm_remove_credential' => ['accepted']]);

        $telegram = $this->credentials->channel('telegram');
        $this->credentials->removeToken($telegram);

        AdminActivityLogger::log('notifications.telegram_token_removed', 'تم حذف توكن Telegram وإيقاف القناة.', $telegram, [
            'channel_type' => 'telegram',
        ]);

        return back()->with('success', 'تم حذف توكن Telegram وإيقاف القناة.');
    }

    public function testTelegram(Request $request, TelegramNotificationChannel $telegramChannel)
    {
        $this->rateLimit($request, 'telegram-test', 5);

        $validated = $request->validate([
            'chat_id' => ['nullable', 'string', 'max:120'],
        ]);

        $telegram = $this->credentials->channel('telegram');
        $settings = $telegram->settings_json ?? [];
        $recipient = trim((string) ($validated['chat_id'] ?? '')) ?: (string) ($settings['default_chat_id'] ?? '');

        if (blank($recipient)) {
            return back()->withErrors(['chat_id' => 'حدد Chat ID أو احفظ Chat ID افتراضي قبل إرسال الاختبار.']);
        }

        $result = $telegramChannel->test(new NotificationTestRequest(
            recipient: $recipient,
            message: implode("\n", [
                '✅ اختبار تنبيه HeroKid',
                '',
                'تم إرسال هذه الرسالة من مركز التنبيهات.',
                'التاريخ: '.now()->format('Y-m-d H:i'),
            ])
        ));

        $settings['last_test_status'] = $result->successful ? 'sent' : 'failed';
        $settings['last_test_message'] = $result->successful ? 'تم إرسال رسالة الاختبار.' : $result->errorMessage;
        $settings['last_test_at'] = now()->toIso8601String();
        $telegram->forceFill(['settings_json' => $settings])->save();

        AdminActivityLogger::log('notifications.telegram_tested', 'تم اختبار قناة Telegram.', $telegram, [
            'status' => $settings['last_test_status'],
            'message' => $settings['last_test_message'],
        ]);

        return back()->with($result->successful ? 'success' : 'error', $settings['last_test_message']);
    }

    public function updateRules(Request $request)
    {
        $eventKeys = array_keys(config('admin_notifications.events', []));

        $validated = $request->validate([
            'rules' => ['nullable', 'array'],
            'rules.*.event_key' => ['required', 'string', Rule::in($eventKeys)],
            'rules.*.channel_type' => ['required', 'string', Rule::in(['telegram'])],
            'rules.*.is_enabled' => ['nullable', 'boolean'],
            'rules.*.severity' => ['required', Rule::in(['info', 'success', 'warning', 'error', 'critical'])],
        ]);

        foreach ($validated['rules'] ?? [] as $ruleInput) {
            NotificationRule::query()->updateOrCreate(
                [
                    'event_key' => $ruleInput['event_key'],
                    'channel_type' => $ruleInput['channel_type'],
                ],
                [
                    'is_enabled' => (bool) ($ruleInput['is_enabled'] ?? false),
                    'severity' => $ruleInput['severity'],
                ]
            );
        }

        AdminActivityLogger::log('notifications.rules_updated', 'تم تحديث قواعد مركز التنبيهات.', properties: [
            'events_count' => count($validated['rules'] ?? []),
        ], request: $request);

        return back()->with('success', 'تم تحديث قواعد التنبيهات.');
    }

    public function updateThresholds(Request $request)
    {
        $validated = $request->validate([
            'notification_production_stuck_after_minutes' => ['required', 'integer', 'min:5', 'max:10080'],
            'notification_ai_job_stuck_after_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'notification_repeat_stuck_alert_after_minutes' => ['required', 'integer', 'min:15', 'max:10080'],
            'notification_production_default_ai_budget_usd' => ['required', 'numeric', 'min:0', 'max:999'],
            'notification_ai_job_warning_cost_usd' => ['required', 'numeric', 'min:0', 'max:999'],
            'notification_ai_project_warning_cost_usd' => ['required', 'numeric', 'min:0', 'max:999'],
            'notification_notify_on_budget_80_percent' => ['nullable', 'boolean'],
        ]);

        $validated['notification_notify_on_budget_80_percent'] = $request->boolean('notification_notify_on_budget_80_percent') ? '1' : '0';
        $before = Setting::query()->whereIn('key', array_keys($validated))->pluck('value', 'key')->toArray();
        $this->settings->save($validated);
        $after = Setting::query()->whereIn('key', array_keys($validated))->pluck('value', 'key')->toArray();

        AdminActivityLogger::log('notifications.thresholds_updated', 'تم تحديث حدود مركز التنبيهات.', properties: [
            'changes' => AdminActivityLogger::changedValues($before, $after),
        ], request: $request);

        return back()->with('success', 'تم تحديث حدود التنبيهات.');
    }

    public function updateOrderCreatedTemplate(Request $request)
    {
        $validated = $request->validate([
            'notification_order_created_template' => ['required', 'string', 'max:5000'],
        ], [
            'notification_order_created_template.required' => 'اكتب محتوى رسالة الطلب الجديد.',
            'notification_order_created_template.max' => 'يجب ألا يزيد قالب الرسالة عن 5000 حرف.',
        ]);

        $template = trim($validated['notification_order_created_template']);
        $unknownVariables = $this->orderCreatedMessage->unknownVariables($template);

        if ($unknownVariables !== []) {
            return back()
                ->withErrors([
                    'notification_order_created_template' => 'يحتوي القالب على متغيرات غير مدعومة: '.implode('، ', $unknownVariables),
                ])
                ->withInput();
        }

        $key = OrderCreatedNotificationMessage::TEMPLATE_SETTING;
        $before = (string) $this->settings->get($key, '');
        $this->settings->save([$key => $template]);

        AdminActivityLogger::log('notifications.order_created_template_updated', 'تم تحديث قالب رسالة Telegram للطلب الجديد.', properties: [
            'changed' => $before !== $template,
            'template_length' => mb_strlen($template),
        ], request: $request);

        return back()->with('success', 'تم حفظ قالب رسالة الطلب الجديد.');
    }

    private function ensureDefaults(): void
    {
        $this->settings->ensureDefaults();
        $this->credentials->channel('telegram');

        foreach (config('admin_notifications.events', []) as $eventKey => $event) {
            NotificationRule::query()->firstOrCreate(
                ['event_key' => $eventKey, 'channel_type' => 'telegram'],
                [
                    'is_enabled' => (bool) ($event['default_enabled'] ?? false),
                    'severity' => $event['severity'] ?? 'info',
                    'thresholds_json' => [],
                ]
            );
        }
    }

    private function settingsForView(): array
    {
        return collect(config('admin_notifications.settings', []))
            ->mapWithKeys(fn ($default, string $key): array => [$key => $this->settings->get($key, $default)])
            ->all();
    }

    private function parseChatIds(string $input): array
    {
        return collect(preg_split('/[\r\n,]+/', $input) ?: [])
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function rateLimit(Request $request, string $key, int $maxAttempts): void
    {
        $limiterKey = $key.':'.($request->user()?->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($limiterKey, $maxAttempts)) {
            abort(429);
        }

        RateLimiter::hit($limiterKey, 60);
    }
}
