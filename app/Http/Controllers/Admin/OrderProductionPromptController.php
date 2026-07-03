<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\AdminActivityLogger;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\Request;

class OrderProductionPromptController extends Controller
{
    public function regenerate(Order $order)
    {
        return response()->json([
            'prompt' => StoryProductionPrompt::forOrder($order, useOverride: false),
            'template_updated_at' => StoryProductionPrompt::templateUpdatedAt()?->toIso8601String(),
        ]);
    }

    public function saveOverride(Request $request, Order $order)
    {
        $validated = $request->validate([
            'prompt_text' => 'required|string|max:'.StoryProductionPrompt::MAX_TEMPLATE_LENGTH,
        ]);

        $order->productionPromptOverride()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'prompt_text' => $validated['prompt_text'],
                'created_by' => $order->productionPromptOverride?->created_by ?? auth()->id(),
                'updated_by' => auth()->id(),
            ]
        );

        AdminActivityLogger::log(
            action: 'order.production_prompt_override_saved',
            description: 'حفظ برومبت خاص للطلب: '.$order->order_number,
            subject: $order,
            properties: ['length' => mb_strlen($validated['prompt_text'])],
            request: $request,
        );

        return back()->with('success', 'تم حفظ برومبت خاص لهذا الطلب.');
    }

    public function resetOverride(Request $request, Order $order)
    {
        $order->productionPromptOverride()?->delete();

        AdminActivityLogger::log(
            action: 'order.production_prompt_override_reset',
            description: 'إعادة الطلب للقالب العام: '.$order->order_number,
            subject: $order,
            request: $request,
        );

        return back()->with('success', 'تم الرجوع إلى قالب الإنتاج العام.');
    }

    public function saveSnapshot(Request $request, Order $order)
    {
        $validated = $request->validate([
            'prompt_text' => 'required|string|max:'.StoryProductionPrompt::MAX_TEMPLATE_LENGTH,
            'snapshot_reason' => 'nullable|string|max:255',
        ]);

        $order->productionPromptSnapshots()->create([
            'prompt_text' => $validated['prompt_text'],
            'template_updated_at' => StoryProductionPrompt::templateUpdatedAt(),
            'snapshot_reason' => $validated['snapshot_reason'] ?: 'manual',
            'created_by' => auth()->id(),
        ]);

        AdminActivityLogger::log(
            action: 'order.production_prompt_snapshot_saved',
            description: 'حفظ نسخة إنتاج للطلب: '.$order->order_number,
            subject: $order,
            properties: ['reason' => $validated['snapshot_reason'] ?: 'manual'],
            request: $request,
        );

        return back()->with('success', 'تم حفظ نسخة إنتاج من البرومبت.');
    }
}
