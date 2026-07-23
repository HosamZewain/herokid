<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ChildIdentity\ChildIdentitySettings;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChildIdentitySettingsController extends Controller
{
    public function edit(ChildIdentitySettings $settings)
    {
        return view('admin.child-identities.settings', [
            'values' => [
                'enabled' => $settings->enabled(),
                'size' => $settings->size(),
                'quality' => $settings->quality(),
                'prompt' => $settings->promptTemplate(),
                'version' => $settings->promptVersion(),
                'limit' => $settings->customerSuccessfulLimit(),
                'processing_copy' => $settings->processingCopy(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $rules = [
            'enabled' => ['nullable', 'boolean'],
            'image_size' => ['required', Rule::in(['1536x1024', '1024x1536', '1024x1024'])],
            'image_quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'prompt_template' => ['required', 'string', 'min:50', 'max:20000'],
            'prompt_version' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'processing_copy' => ['nullable', 'array'],
        ];

        foreach (ChildIdentitySettings::PROCESSING_COPY_DEFAULTS as $key => $default) {
            $rules["processing_copy.{$key}"] = ['sometimes', 'required', 'string', 'max:500'];
        }

        $validated = $request->validate($rules);
        $updates = [
            'child_identity_enabled' => $request->boolean('enabled') ? '1' : '0',
            'child_identity_image_size' => $validated['image_size'],
            'child_identity_image_quality' => $validated['image_quality'],
            'child_identity_prompt_template' => $validated['prompt_template'],
            'child_identity_prompt_version' => $validated['prompt_version'],
        ];

        foreach (($validated['processing_copy'] ?? []) as $key => $value) {
            if (array_key_exists($key, ChildIdentitySettings::PROCESSING_COPY_DEFAULTS)) {
                $updates["child_identity_processing_{$key}"] = $value;
            }
        }

        foreach ($updates as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $request->user()->id]);
        }

        AdminActivityLogger::log(
            'child_identity.settings_updated',
            'تحديث إعدادات هويات الأطفال.',
            properties: [
                'enabled' => $request->boolean('enabled'),
                'image_size' => $validated['image_size'],
                'image_quality' => $validated['image_quality'],
                'prompt_version' => $validated['prompt_version'],
                'processing_copy_updated' => array_keys($validated['processing_copy'] ?? []),
            ],
        );

        return back()->with('success', 'تم حفظ إعدادات هويات الأطفال.');
    }
}
