<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Services\CodexScenes\ManualSceneService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CodexSceneController extends Controller
{
    public function start(Request $request, Order $order, ManualSceneService $service)
    {
        $request->validate(['confirm' => ['accepted']]);
        $service->start($order, $request->user());

        return back()->with('success', 'تم تسجيل التجربة اليدوية؛ ستظهر المشاهد هنا تدريجيًا.');
    }

    public function stop(Request $request, Order $order, CodexSceneRun $run, ManualSceneService $service)
    {
        abort_unless($run->order_id === $order->id, 404);
        $service->cancel($run, $request->user());

        return back()->with('success', 'تم طلب الإيقاف. انتظر تأكيد توقف Codex قبل استكمال العمل.');
    }

    public function image(Order $order, CodexSceneRun $run, int $scene)
    {
        abort_unless($run->order_id === $order->id, 404);
        $data = ($run->scenes ?? [])[(string) $scene] ?? null;
        abort_unless($data, 404);

        return Storage::disk('s3_private')->response($data['path'], 'scene-'.sprintf('%02d', $scene).'.png', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
