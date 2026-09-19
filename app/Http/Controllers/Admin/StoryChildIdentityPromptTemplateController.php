<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Orders\OrderChildIdentityPromptService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoryChildIdentityPromptTemplateController extends Controller
{
    public function edit(OrderChildIdentityPromptService $prompts): View
    {
        return view('admin.settings.story-child-identity-prompt', [
            'template' => $prompts->activeTemplate(),
            'setting' => $prompts->templateSetting(),
        ]);
    }

    public function update(Request $request, OrderChildIdentityPromptService $prompts): RedirectResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string', 'max:'.OrderChildIdentityPromptService::MAX_LENGTH],
        ], [
            'template.required' => 'قالب برومبت هوية القصة مطلوب.',
            'template.max' => 'قالب برومبت هوية القصة طويل جدًا.',
        ]);
        $before = $prompts->activeTemplate();
        $template = trim($validated['template']);
        if ($template === '') {
            return back()->withErrors(['template' => 'قالب برومبت هوية القصة مطلوب.'])->withInput();
        }

        Setting::query()->updateOrCreate(
            ['key' => OrderChildIdentityPromptService::SETTING_KEY],
            ['value' => $template, 'updated_by' => $request->user()->id],
        );

        AdminActivityLogger::log(
            action: 'story_child_identity_prompt_template.updated',
            description: 'تحديث قالب برومبت إنتاج هوية القصص.',
            properties: [
                'changed' => $before !== $template,
                'previous_hash' => hash('sha256', $before),
                'new_hash' => hash('sha256', $template),
                'length' => mb_strlen($template),
            ],
            request: $request,
        );

        return redirect()->route('admin.settings.story-child-identity-prompt.edit')
            ->with('success', 'تم حفظ قالب برومبت هوية القصص. ظهر التحديث تلقائيًا في كل طلبات القصص القديمة والجديدة.');
    }

    public function reset(Request $request, OrderChildIdentityPromptService $prompts): RedirectResponse
    {
        $before = $prompts->activeTemplate();
        $template = $prompts->defaultInstructions();
        Setting::query()
            ->where('key', OrderChildIdentityPromptService::SETTING_KEY)
            ->first()
            ?->delete();

        AdminActivityLogger::log(
            action: 'story_child_identity_prompt_template.reset',
            description: 'استعادة قالب برومبت إنتاج هوية القصص الافتراضي.',
            properties: [
                'changed' => $before !== $template,
                'previous_hash' => hash('sha256', $before),
                'new_hash' => hash('sha256', $template),
            ],
            request: $request,
        );

        return redirect()->route('admin.settings.story-child-identity-prompt.edit')
            ->with('success', 'تمت استعادة قالب برومبت هوية القصص الافتراضي.');
    }
}
