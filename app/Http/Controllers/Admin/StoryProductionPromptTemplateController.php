<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Setting;
use App\Support\AdminActivityLogger;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class StoryProductionPromptTemplateController extends Controller
{
    public function edit(Request $request)
    {
        $template = old('template', StoryProductionPrompt::activeTemplate());
        $setting = StoryProductionPrompt::templateSetting();
        $orders = $this->previewOrders($request);
        $previewOrder = null;
        $previewPrompt = null;

        if ($request->filled('preview_order_id')) {
            $previewOrder = Order::with('story')->find($request->integer('preview_order_id'));
            if ($previewOrder) {
                $previewPrompt = StoryProductionPrompt::renderForOrder($previewOrder, $template);
            }
        }

        return view('admin.settings.story-production-prompt', [
            'template' => $template,
            'setting' => $setting,
            'variables' => StoryProductionPrompt::supportedVariables(),
            'orders' => $orders,
            'previewOrder' => $previewOrder,
            'previewPrompt' => $previewPrompt,
        ]);
    }

    public function update(Request $request)
    {
        $template = $this->validatedTemplate($request);
        $before = StoryProductionPrompt::templateSetting()?->value;

        Setting::updateOrCreate(
            ['key' => StoryProductionPrompt::SETTING_KEY],
            ['value' => $template, 'updated_by' => auth()->id()]
        );

        Cache::forget('site_settings');

        AdminActivityLogger::log(
            action: 'story_production_prompt_template.updated',
            description: 'تحديث قالب برومبت إنتاج القصة.',
            properties: [
                'changed' => $before !== $template,
                'length' => mb_strlen($template),
            ],
            request: $request,
        );

        return redirect()->route('admin.settings.story-production-prompt.edit')->with('success', 'تم حفظ قالب برومبت الإنتاج بنجاح.');
    }

    public function preview(Request $request)
    {
        $template = $this->validatedTemplate($request);

        $validated = $request->validate([
            'preview_order_id' => 'required|integer|exists:orders,id',
        ]);

        return redirect()
            ->route('admin.settings.story-production-prompt.edit', ['preview_order_id' => $validated['preview_order_id']])
            ->withInput(['template' => $template]);
    }

    public function reset(Request $request)
    {
        $template = StoryProductionPrompt::defaultTemplate();

        Setting::updateOrCreate(
            ['key' => StoryProductionPrompt::SETTING_KEY],
            ['value' => $template, 'updated_by' => auth()->id()]
        );

        AdminActivityLogger::log(
            action: 'story_production_prompt_template.reset',
            description: 'استعادة قالب برومبت إنتاج القصة الافتراضي.',
            properties: ['length' => mb_strlen($template)],
            request: $request,
        );

        return redirect()->route('admin.settings.story-production-prompt.edit')->with('success', 'تمت استعادة القالب الافتراضي.');
    }

    private function validatedTemplate(Request $request): string
    {
        $validated = $request->validate([
            'template' => 'required|string|max:'.StoryProductionPrompt::MAX_TEMPLATE_LENGTH,
        ], [
            'template.required' => 'قالب برومبت الإنتاج مطلوب.',
            'template.max' => 'قالب برومبت الإنتاج طويل جداً.',
        ]);

        $unsupported = StoryProductionPrompt::unsupportedVariables($validated['template']);

        if ($unsupported !== []) {
            throw ValidationException::withMessages([
                'template' => collect($unsupported)
                    ->map(fn (string $variable): string => 'Unsupported variable: '.$variable)
                    ->implode("\n"),
            ]);
        }

        return $validated['template'];
    }

    private function previewOrders(Request $request)
    {
        return Order::with('story')
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = $request->input('q');
                $query->where(function ($builder) use ($search) {
                    $builder->where('order_number', 'like', '%'.$search.'%')
                        ->orWhere('child_name', 'like', '%'.$search.'%')
                        ->orWhere('parent_name', 'like', '%'.$search.'%');
                });
            })
            ->latest()
            ->limit(30)
            ->get();
    }
}
