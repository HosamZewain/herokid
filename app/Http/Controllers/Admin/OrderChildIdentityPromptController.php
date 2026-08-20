<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderChildIdentityPromptService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;

class OrderChildIdentityPromptController extends Controller
{
    public function regenerate(Order $order, OrderChildIdentityPromptService $prompts)
    {
        return response()->json([
            'prompt' => $prompts->forOrder($order, useOverride: false),
            'prompt_version' => OrderChildIdentityPromptService::VERSION,
        ]);
    }

    public function saveOverride(Request $request, Order $order, OrderChildIdentityPromptService $prompts)
    {
        $validated = $request->validate([
            'prompt_text' => 'required|string|max:'.OrderChildIdentityPromptService::MAX_LENGTH,
        ]);
        $prompt = $prompts->withCurrentContext($validated['prompt_text'], $order);

        $order->childIdentityPromptOverride()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'prompt_text' => $prompt,
                'created_by' => $order->childIdentityPromptOverride?->created_by ?? $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ],
        );

        AdminActivityLogger::log(
            action: 'order.child_identity_prompt_override_saved',
            description: 'حفظ برومبت هوية طفل خاص للطلب: '.$order->order_number,
            subject: $order,
            properties: ['length' => mb_strlen($prompt), 'prompt_version' => OrderChildIdentityPromptService::VERSION],
            request: $request,
        );

        return back()->with('success', 'تم حفظ برومبت هوية طفل خاص لهذا الطلب.');
    }

    public function resetOverride(Request $request, Order $order)
    {
        $order->childIdentityPromptOverride()?->delete();

        AdminActivityLogger::log(
            action: 'order.child_identity_prompt_override_reset',
            description: 'إعادة برومبت هوية الطفل للقالب الافتراضي: '.$order->order_number,
            subject: $order,
            request: $request,
        );

        return back()->with('success', 'تم الرجوع إلى قالب هوية الطفل الافتراضي.');
    }

    public function saveSnapshot(Request $request, Order $order, OrderChildIdentityPromptService $prompts)
    {
        $validated = $request->validate([
            'prompt_text' => 'required|string|max:'.OrderChildIdentityPromptService::MAX_LENGTH,
            'snapshot_reason' => 'nullable|string|max:255',
        ]);
        $prompt = $prompts->withCurrentContext($validated['prompt_text'], $order);

        $order->childIdentityPromptSnapshots()->create([
            'prompt_text' => $prompt,
            'prompt_version' => OrderChildIdentityPromptService::VERSION,
            'snapshot_reason' => $validated['snapshot_reason'] ?: 'manual',
            'created_by' => $request->user()?->id,
        ]);

        AdminActivityLogger::log(
            action: 'order.child_identity_prompt_snapshot_saved',
            description: 'حفظ نسخة برومبت هوية طفل للطلب: '.$order->order_number,
            subject: $order,
            properties: ['reason' => $validated['snapshot_reason'] ?: 'manual'],
            request: $request,
        );

        return back()->with('success', 'تم حفظ نسخة من برومبت هوية الطفل.');
    }
}
