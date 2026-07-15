<x-admin-layout>
    <x-slot name="header">
        <div class="text-right">
            <h2 class="text-xl font-bold text-gray-800">مركز التنبيهات</h2>
            <p class="text-sm text-gray-500" dir="ltr">Notification Center</p>
        </div>
    </x-slot>

    @php
        $telegramSettings = $telegram->settings_json ?? [];
        $extraChatIds = implode("\n", $telegramSettings['additional_chat_ids'] ?? []);
        $severityLabels = [
            'info' => 'Info',
            'success' => 'Success',
            'warning' => 'Warning',
            'error' => 'Error',
            'critical' => 'Critical',
        ];
        $deliveryLink = function ($delivery) {
            if ($delivery->notifiable_type === \App\Models\Order::class && $delivery->notifiable_id) {
                return route('admin.orders.show', $delivery->notifiable_id);
            }

            if ($delivery->notifiable_type === \App\Models\ProductionProject::class && $delivery->notifiable_id) {
                return route('admin.production-studio.show', $delivery->notifiable_id);
            }

            if ($delivery->notifiable_type === \App\Models\SceneGenerationJob::class && $delivery->notifiable_id) {
                $job = \App\Models\SceneGenerationJob::query()->find($delivery->notifiable_id);

                return $job?->production_project_id ? route('admin.production-studio.show', $job->production_project_id) : null;
            }

            return null;
        };
    @endphp

    <div class="space-y-6" dir="rtl">
        @if($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-right text-sm font-bold text-red-700">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <section class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                <p class="text-sm font-bold text-gray-500">القنوات النشطة</p>
                <p class="mt-2 text-2xl font-black text-gray-950">{{ $stats['active_channels'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                <p class="text-sm font-bold text-gray-500">الأحداث المفعلة</p>
                <p class="mt-2 text-2xl font-black text-gray-950">{{ $stats['enabled_events'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                <p class="text-sm font-bold text-gray-500">فشل التسليم</p>
                <p class="mt-2 text-2xl font-black text-red-600">{{ $stats['failed_deliveries'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                <p class="text-sm font-bold text-gray-500">آخر فحص توقف</p>
                <p class="mt-2 text-sm font-black text-gray-950" dir="ltr">{{ $stats['last_stuck_check'] ?: 'Not run yet' }}</p>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
            <section class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
                <div class="mb-5 border-b border-gray-100 pb-4 text-right">
                    <h3 class="text-lg font-black text-gray-950">Telegram</h3>
                    <p class="mt-1 text-sm text-gray-500">اترك حقل التوكن فارغًا للاحتفاظ بالتوكن الحالي. لن يظهر التوكن بعد الحفظ.</p>
                </div>

                <form method="POST" action="{{ route('admin.settings.notifications.telegram.update') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="block text-right">
                            <span class="text-sm font-black text-gray-700">تفعيل Telegram</span>
                            <select name="is_active" @cannot('settings.notifications.manage') disabled @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-right">
                                <option value="0" @selected(! old('is_active', $telegram->is_active))>Disabled</option>
                                <option value="1" @selected(old('is_active', $telegram->is_active))>Enabled</option>
                            </select>
                        </label>
                        <label class="block text-right">
                            <span class="text-sm font-black text-gray-700">Default Chat ID</span>
                            <input name="default_chat_id" value="{{ old('default_chat_id', $telegramSettings['default_chat_id'] ?? '') }}" dir="ltr" @cannot('settings.notifications.manage') readonly @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-left">
                        </label>
                        <label class="block text-right md:col-span-2">
                            <span class="text-sm font-black text-gray-700">Additional Chat IDs</span>
                            <textarea name="additional_chat_ids" rows="4" dir="ltr" @cannot('settings.notifications.manage') readonly @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-left">{{ old('additional_chat_ids', $extraChatIds) }}</textarea>
                        </label>
                    </div>

                    @can('settings.notifications.manage_credentials')
                        <div class="rounded-xl border border-amber-100 bg-amber-50 p-4 text-right">
                            <label class="block">
                                <span class="text-sm font-black text-gray-800">Bot token</span>
                                <input type="password" name="bot_token" value="" autocomplete="new-password" dir="ltr" class="mt-2 w-full rounded-xl border-gray-300 text-left">
                            </label>
                            <p class="mt-2 text-xs text-amber-800">Configured: {{ $telegramMask ?: 'Not configured' }}</p>
                            @if($telegramMask)
                                <label class="mt-3 flex items-center gap-2 text-sm font-bold text-amber-900">
                                    <input type="checkbox" name="confirm_replace_credential" value="1" class="rounded border-gray-300">
                                    أؤكد استبدال التوكن الحالي
                                </label>
                            @endif
                        </div>
                    @else
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 text-right text-sm font-bold text-gray-700">
                            Configured: {{ $telegramMask ? '••••••••'.substr($telegramMask, -4) : 'Not configured' }}
                        </div>
                    @endcan

                    @can('settings.notifications.manage')
                        <button class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white hover:bg-indigo-700">حفظ Telegram</button>
                    @endcan
                </form>
            </section>

            <aside class="space-y-4">
                <section class="rounded-xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                    <h3 class="font-black text-gray-950">اختبار Telegram</h3>
                    <p class="mt-2 text-sm text-gray-500">{{ $telegramSettings['last_test_message'] ?? 'لم يتم الاختبار بعد.' }}</p>
                    <p class="mt-1 text-xs text-gray-400" dir="ltr">{{ $telegramSettings['last_test_at'] ?? '' }}</p>
                    @can('settings.notifications.test')
                        <form method="POST" action="{{ route('admin.settings.notifications.telegram.test') }}" class="mt-3 space-y-3">
                            @csrf
                            <input name="chat_id" placeholder="Optional test Chat ID" dir="ltr" class="w-full rounded-xl border-gray-300 text-left">
                            <button class="w-full rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-black text-indigo-700">Test Message</button>
                        </form>
                    @endcan
                </section>

                @can('settings.notifications.manage_credentials')
                    @if($telegramMask)
                        <section class="rounded-xl border border-red-100 bg-red-50 p-5 text-right shadow-sm">
                            <h3 class="font-black text-red-800">حذف التوكن</h3>
                            <form method="POST" action="{{ route('admin.settings.notifications.telegram.token.destroy') }}" class="mt-3" onsubmit="return confirm('سيتم حذف توكن Telegram وإيقاف القناة. هل تريد المتابعة؟')">
                                @csrf
                                @method('DELETE')
                                <label class="mb-3 flex items-center gap-2 text-xs font-bold text-red-800">
                                    <input type="checkbox" name="confirm_remove_credential" value="1" required class="rounded border-red-300">
                                    أؤكد حذف توكن Telegram
                                </label>
                                <button class="w-full rounded-xl bg-red-600 px-4 py-2 text-sm font-black text-white">Remove Token</button>
                            </form>
                        </section>
                    @endif
                @endcan
            </aside>
        </div>

        <section class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="mb-5 border-b border-gray-100 pb-4 text-right">
                <h3 class="text-lg font-black text-gray-950">حدود التوقف والميزانية</h3>
            </div>
            <form method="POST" action="{{ route('admin.settings.notifications.thresholds.update') }}" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                @csrf
                @method('PUT')
                @foreach([
                    'notification_production_stuck_after_minutes' => 'توقف مشروع الإنتاج بعد / دقيقة',
                    'notification_ai_job_stuck_after_minutes' => 'توقف مهمة AI بعد / دقيقة',
                    'notification_repeat_stuck_alert_after_minutes' => 'تكرار تنبيه التوقف بعد / دقيقة',
                    'notification_production_default_ai_budget_usd' => 'ميزانية الإنتاج الافتراضية بالدولار',
                    'notification_ai_job_warning_cost_usd' => 'حد تكلفة مهمة AI بالدولار',
                    'notification_ai_project_warning_cost_usd' => 'حد تكلفة مشروع AI بالدولار',
                ] as $key => $label)
                    <label class="block text-right">
                        <span class="text-sm font-black text-gray-700">{{ $label }}</span>
                        <input type="number" step="0.01" min="0" name="{{ $key }}" value="{{ old($key, $notificationSettings[$key] ?? '') }}" @cannot('settings.notifications.manage') readonly @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-left" dir="ltr">
                    </label>
                @endforeach
                <label class="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm font-bold text-gray-700 md:col-span-2">
                    <input type="checkbox" name="notification_notify_on_budget_80_percent" value="1" @checked(old('notification_notify_on_budget_80_percent', $notificationSettings['notification_notify_on_budget_80_percent'] ?? '1') === '1') @cannot('settings.notifications.manage') disabled @endcannot class="rounded border-gray-300 text-indigo-600">
                    <span>إرسال تحذير عند الوصول إلى 80% من الميزانية</span>
                </label>
                @can('settings.notifications.manage')
                    <div class="flex items-end">
                        <button class="w-full rounded-xl bg-gray-900 px-6 py-3 text-sm font-black text-white">حفظ الحدود</button>
                    </div>
                @endcan
            </form>
        </section>

        <section class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="mb-5 border-b border-gray-100 pb-4 text-right">
                <h3 class="text-lg font-black text-gray-950">قواعد الأحداث</h3>
            </div>
            <form method="POST" action="{{ route('admin.settings.notifications.rules.update') }}">
                @csrf
                @method('PUT')
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-right text-sm">
                        <thead class="bg-gray-50 text-xs font-black uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-3">الحدث</th>
                                <th class="px-4 py-3">Event key</th>
                                <th class="px-4 py-3">القناة</th>
                                <th class="px-4 py-3">الشدة</th>
                                <th class="px-4 py-3">مفعل</th>
                                <th class="px-4 py-3">Preview</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($eventDefinitions as $eventKey => $event)
                                @php
                                    $rule = $rules->get($eventKey.'|telegram');
                                    $index = $loop->index;
                                @endphp
                                <tr>
                                    <td class="px-4 py-3 font-bold text-gray-900">{{ $event['name_ar'] }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-600" dir="ltr">{{ $eventKey }}</td>
                                    <td class="px-4 py-3 text-gray-700">Telegram</td>
                                    <td class="px-4 py-3">
                                        <input type="hidden" name="rules[{{ $index }}][event_key]" value="{{ $eventKey }}">
                                        <input type="hidden" name="rules[{{ $index }}][channel_type]" value="telegram">
                                        <select name="rules[{{ $index }}][severity]" @cannot('settings.notifications.manage_rules') disabled @endcannot class="rounded-lg border-gray-300 text-sm">
                                            @foreach($severityLabels as $value => $label)
                                                <option value="{{ $value }}" @selected(($rule?->severity ?? $event['severity']) === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-4 py-3">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="rules[{{ $index }}][is_enabled]" value="1" @checked($rule?->is_enabled ?? false) @cannot('settings.notifications.manage_rules') disabled @endcannot class="rounded border-gray-300 text-indigo-600">
                                            <span>{{ ($rule?->is_enabled ?? false) ? 'Enabled' : 'Disabled' }}</span>
                                        </label>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500">{{ $event['name_en'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @can('settings.notifications.manage_rules')
                    <button class="mt-5 rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white hover:bg-indigo-700">حفظ القواعد</button>
                @endcan
            </form>
        </section>

        @can('settings.notifications.view_logs')
            <section class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
                <div class="mb-5 border-b border-gray-100 pb-4 text-right">
                    <h3 class="text-lg font-black text-gray-950">سجل التسليم</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-right text-sm">
                        <thead class="bg-gray-50 text-xs font-black uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-3">الحدث</th>
                                <th class="px-4 py-3">القناة</th>
                                <th class="px-4 py-3">المستلم</th>
                                <th class="px-4 py-3">الحالة</th>
                                <th class="px-4 py-3">Attempts</th>
                                <th class="px-4 py-3">Sent at</th>
                                <th class="px-4 py-3">Error</th>
                                <th class="px-4 py-3">Related</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($deliveries as $delivery)
                                @php $link = $deliveryLink($delivery); @endphp
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs" dir="ltr">{{ $delivery->event_key }}</td>
                                    <td class="px-4 py-3">{{ $delivery->channel_type }}</td>
                                    <td class="px-4 py-3 font-mono text-xs" dir="ltr">{{ $delivery->recipient }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-1 text-xs font-black {{ $delivery->status === 'sent' ? 'bg-green-50 text-green-700' : ($delivery->status === 'failed' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-700') }}">{{ $delivery->status }}</span>
                                    </td>
                                    <td class="px-4 py-3">{{ $delivery->attempts }}</td>
                                    <td class="px-4 py-3 font-mono text-xs" dir="ltr">{{ $delivery->sent_at?->format('Y-m-d H:i') }}</td>
                                    <td class="max-w-xs truncate px-4 py-3 text-red-700">{{ $delivery->error_message }}</td>
                                    <td class="px-4 py-3">
                                        @if($link)
                                            <a href="{{ $link }}" class="font-bold text-indigo-600 hover:text-indigo-800">فتح</a>
                                        @else
                                            <span class="text-gray-400">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">لا توجد محاولات تسليم بعد.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    {{ $deliveries->links() }}
                </div>
            </section>
        @endcan
    </div>
</x-admin-layout>
