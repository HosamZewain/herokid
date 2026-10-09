<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RoboDeskConversationSetting;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoboDeskConversationSettingsController extends Controller
{
    public function edit()
    {
        $conversationSettings = RoboDeskConversationSetting::find(1);

        return response()->view('admin.robodesk.conversation-settings', compact('conversationSettings'))->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['nullable', 'string', 'max:1024'],
            'enabled' => ['nullable', 'boolean'], 'conversation_limit' => ['required', 'integer', 'between:1,50']]);
        $settings = RoboDeskConversationSetting::firstOrNew(['id' => 1]);
        if ($request->boolean('enabled') && ! filled($data['password'] ?? null) && ! filled($settings->password)) {
            throw ValidationException::withMessages(['password' => 'أدخل كلمة مرور حساب التكامل قبل التفعيل.']);
        }
        $settings->fill(['email' => $data['email'], 'enabled' => $request->boolean('enabled'), 'conversation_limit' => $data['conversation_limit']]);
        if (filled($data['password'] ?? null)) {
            $settings->password = $data['password'];
        }
        $settings->save();
        // No submitted credentials in audit, including the email address.
        AdminActivityLogger::log('robodesk.conversation_settings.updated', 'تحديث إعدادات قراءة محادثات واتساب',
            properties: ['enabled' => $settings->enabled, 'conversation_limit' => $settings->conversation_limit], request: $request);

        return back()->with('success', 'تم حفظ إعدادات المحادثات. كلمة المرور محفوظة مشفّرة ولن تظهر مرة أخرى.');
    }
}
