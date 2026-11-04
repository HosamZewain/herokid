<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Services\CodexScenes\ManualSceneService;
use App\Services\CodexScenes\OrderGenerationBanner;
use App\Services\CodexScenes\SceneTiming;
use App\Services\Orders\OrderAccess;
use App\Services\Orders\PrivateOrderThumbnail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CodexSceneController extends Controller
{
    public function index(Request $request)
    {
        $data = $this->monitorData($request);

        return view('admin.order-automation.codex-scenes', $data);
    }

    public function monitor(Request $request)
    {
        $html = view('admin.order-automation._codex-scene-runs', $this->monitorData($request))->render();

        return response()->json(['html' => $html, 'version' => hash('sha256', $html)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function monitorData(Request $request): array
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(array_keys(CodexSceneRun::LABELS))]])['status'] ?? null;
        $runs = CodexSceneRun::with(['order.story', 'order.checkoutReference'])->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw('CASE WHEN active_slot = 1 THEN 0 ELSE 1 END')->latest('created_at')->paginate(25)
            ->withPath(route('admin.order-automation.codex-scenes'))->appends($request->only('status'));

        return ['sceneRuns' => $runs, 'sceneStatus' => $status, 'sceneCounts' => CodexSceneRun::select('status')->selectRaw('count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'sceneHealth' => app(ManualSceneService::class)->health(), 'sceneTiming' => SceneTiming::summary()];
    }

    public function start(Request $request, Order $order, ManualSceneService $service)
    {
        abort_unless(app(OrderAccess::class)->controls($order, $request->user()), 403);
        $request->validate(['confirm' => ['accepted']]);
        $service->start($order, $request->user());

        return back()->with('success', 'تم تسجيل القصة في طابور التوليد؛ ستظهر المشاهد هنا تدريجيًا.');
    }

    public function stop(Request $request, Order $order, CodexSceneRun $run, ManualSceneService $service)
    {
        abort_unless($run->order_id === $order->id, 404);
        abort_unless(app(OrderAccess::class)->controls($order, $request->user()), 403);
        $service->cancel($run, $request->user());

        return back()->with('success', 'تم طلب الإيقاف. انتظر تأكيد توقف Codex قبل استكمال العمل.');
    }

    public function banner(Order $order, OrderGenerationBanner $banner)
    {
        $html = view('admin.orders._codex-generation-banner-content', $banner->data($order))->render();

        return response()->json(['html' => $html, 'version' => hash('sha256', $html)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function progress(Order $order)
    {
        $codexRuns = CodexSceneRun::where('order_id', $order->id)->latest()->limit(3)->get();
        $html = view('admin.orders._codex-scenes-progress', compact('order', 'codexRuns'))->render();

        return response()->json(['html' => $html, 'version' => hash('sha256', $html)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function download(Order $order, CodexSceneRun $run)
    {
        abort_unless($run->order_id === $order->id, 404);
        $scenes = $run->scenes ?? [];
        abort_unless(count($scenes), 404);
        ksort($scenes, SORT_NUMERIC);
        $path = tempnam(sys_get_temp_dir(), 'codex-scenes-');
        abort_if($path === false, 503);
        $zip = new \ZipArchive;
        $opened = false;
        try {
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot prepare scene archive.');
            }
            $opened = true;
            foreach ($scenes as $number => $scene) {
                $bytes = Storage::disk('s3_private')->get($scene['path']);
                if (! is_string($bytes) || ! hash_equals($scene['sha256'], hash('sha256', $bytes))) {
                    throw new \RuntimeException('Scene storage verification failed.');
                }
                if (! $zip->addFromString(sprintf('scene-%02d.png', $number), $bytes)) {
                    throw new \RuntimeException('Cannot add scene to archive.');
                }
            }
            if (! $zip->close()) {
                throw new \RuntimeException('Cannot finalize scene archive.');
            }
            $opened = false;
            clearstatcache(true, $path);

            return response()->download($path, 'order-'.$order->id.'-scenes-'.$run->id.'.zip', [
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
            report($e);
            abort(503, 'تعذر تجهيز تحميل المشاهد؛ حاول مرة أخرى.');
        }
    }

    public function image(Order $order, CodexSceneRun $run, int $scene)
    {
        abort_unless($run->order_id === $order->id, 404);
        $data = ($run->scenes ?? [])[(string) $scene] ?? null;
        abort_unless($data, 404);

        if (request()->boolean('thumbnail')) {
            return app(PrivateOrderThumbnail::class)->response('s3_private', $data['path']);
        }

        return Storage::disk('s3_private')->response($data['path'], 'scene-'.sprintf('%02d', $scene).'.png', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
