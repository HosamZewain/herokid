<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PuzzleGeneration;
use App\Services\ProductAutomation\PuzzleAutomation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PuzzleGenerationController extends Controller
{
    public function regenerate(Request $request, Order $order, OrderItem $item, PuzzleAutomation $service)
    {
        $data = $request->validate(['previous_run_id' => ['required', 'integer', 'min:1'], 'confirm' => ['required', 'accepted'], 'prompt' => ['nullable', 'string', 'min:20', 'max:65000']]);
        $service->regenerate($order, $item, $request->user(), $data['previous_run_id'], $data['prompt'] ?? null);

        return back()->with('success', 'تم تسجيل محاولة إعادة التوليد لهذا المنتج فقط؛ ستُضاف الصورة الجديدة مع الاحتفاظ بالصور السابقة.');
    }

    public function image(Order $order, PuzzleGeneration $run)
    {
        abort_unless($run->order_id === $order->id && $run->output_path && $run->disk, 404);

        return Storage::disk($run->disk)->response($run->output_path, basename($run->output_path), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
