<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Orders\OrderWhatsAppMessageService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderWhatsAppTemplateController extends Controller
{
    public function edit(OrderWhatsAppMessageService $messages)
    {
        return view('admin.settings.order-whatsapp-messages', [
            'templates' => $messages->templates(),
            'variables' => OrderWhatsAppMessageService::VARIABLES,
        ]);
    }

    public function update(Request $request, OrderWhatsAppMessageService $messages)
    {
        $validated = $request->validate([
            'templates' => ['required', 'array', 'min:1', 'max:20'],
            'templates.*.id' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_\-]+$/i'],
            'templates.*.title' => ['required', 'string', 'max:100'],
            'templates.*.message' => ['required', 'string', 'max:4000'],
            'templates.*.is_active' => ['nullable', 'boolean'],
            'templates.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ], [
            'templates.required' => 'أضف قالب رسالة واحدًا على الأقل.',
            'templates.min' => 'أضف قالب رسالة واحدًا على الأقل.',
            'templates.*.title.required' => 'اكتب عنوان كل زر واتساب.',
            'templates.*.message.required' => 'اكتب محتوى كل رسالة واتساب.',
        ]);

        $normalized = collect($validated['templates'])->map(function (array $template, int $index) use ($messages): array {
            $unknown = $messages->unknownVariables($template['message']);
            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    "templates.$index.message" => 'متغيرات غير معروفة: '.implode('، ', $unknown),
                ]);
            }

            return [
                'id' => $template['id'] ?? 'message_'.Str::lower(Str::random(10)),
                'title' => trim($template['title']),
                'message' => trim($template['message']),
                'is_active' => (bool) ($template['is_active'] ?? false),
                'sort_order' => (int) $template['sort_order'],
            ];
        })->values()->all();

        $ids = collect($normalized)->pluck('id');
        if ($ids->unique()->count() !== $ids->count()) {
            throw ValidationException::withMessages(['templates' => 'تعذر حفظ قالبين بنفس المعرّف. حدّث الصفحة وحاول مرة أخرى.']);
        }

        $before = $messages->templates();
        Setting::updateOrCreate(
            ['key' => OrderWhatsAppMessageService::SETTING_KEY],
            ['value' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_by' => $request->user()->id],
        );

        AdminActivityLogger::log(
            action: 'settings.order_whatsapp_messages.updated',
            description: 'تحديث قوالب رسائل واتساب الخاصة بالطلبات.',
            properties: [
                'before_count' => count($before),
                'after_count' => count($normalized),
                'active_count' => collect($normalized)->where('is_active', true)->count(),
                'template_ids' => collect($normalized)->pluck('id')->all(),
            ],
            request: $request,
        );

        return back()->with('success', 'تم حفظ قوالب رسائل واتساب وتطبيقها فورًا على كل الطلبات.');
    }
}
