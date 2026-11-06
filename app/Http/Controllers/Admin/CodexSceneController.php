<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Services\CodexScenes\BookPageRecovery;
use App\Services\CodexScenes\BookPdfService;
use App\Services\CodexScenes\ManualSceneService;
use App\Services\CodexScenes\OrderGenerationBanner;
use App\Services\CodexScenes\SceneTiming;
use App\Services\Orders\OrderAccess;
use App\Services\Orders\PrivateOrderThumbnail;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CodexSceneController extends Controller
{
    public function bookPages(Request $request, Order $order, CodexSceneRun $run, BookPageRecovery $recovery)
    {
        abort_unless($run->order_id === $order->id && $run->production_kind === 'coloring_book'
            && $run->scene_count >= 1 && $run->scene_count <= 1000, 404);
        if (($run->book_pdf['recovery']['status'] ?? '') === 'completed') {
            $final = CodexSceneRun::where('order_id', $order->id)->where('order_item_id', $run->order_item_id)
                ->whereKey($run->book_pdf['recovery']['final_run_id'])->where('production_kind', 'coloring_book')->firstOrFail();

            return redirect()->route('admin.orders.codex-scenes.book-pages', [$order, $final]);
        }
        $missing = array_values(array_diff(range(1, $run->scene_count), array_map('intval', array_keys($run->scenes ?? []))));
        $page = min(max(1, $request->integer('page', 1)), (int) ceil($run->scene_count / 48));
        $numbers = range(($page - 1) * 48 + 1, min($page * 48, $run->scene_count));
        $pages = new LengthAwarePaginator($numbers, $run->scene_count, 48, $page, ['path' => $request->url()]);
        $review = null;
        $reviewError = null;
        $canReview = app(OrderAccess::class)->controls($order, $request->user()) && $request->user()->hasPermission('orders.production_prompt.manage');
        if ($canReview && ! isset($run->book_pdf['recovery']['reviewed_pages'])
            && collect($missing)->contains(fn ($n) => ($run->scene_progress[$n]['code'] ?? '') === 'content_review')) {
            try {
                $review = $recovery->reviewPlan($run, $request->user());
            } catch (ValidationException $e) {
                $reviewError = collect($e->errors())->flatten()->first();
            } catch (\RuntimeException) {
                $reviewError = 'تعذر التحقق من اعتماد الكتاب أو مراجعه الحالية؛ الصور المحفوظة تظل متاحة للعرض والتحميل.';
            }
        }

        return response()->view('admin.orders.book-pages', compact('order', 'run', 'missing', 'pages', 'review', 'reviewError', 'canReview'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function retryReviewedBook(Request $request, Order $order, CodexSceneRun $run, BookPageRecovery $recovery)
    {
        abort_unless($run->order_id === $order->id && $run->production_kind === 'coloring_book', 404);
        abort_unless(app(OrderAccess::class)->controls($order, $request->user()), 403);
        $data = $request->validate(['confirm_review' => 'required|accepted', 'preview_token' => 'required|string|max:10000']);
        $recovery->retryReviewed($run, $request->user(), $request, $data['preview_token']);

        return redirect()->route('admin.orders.codex-scenes.book-pages', [$order, $run])
            ->with('success', 'سُجلت مراجعة المحتوى وطلب محاولة واحدة للصفحات الناقصة. يُجهز PDF الطباعة بعد اكتمال العدد؛ الصفحات المحفوظة كما هي.');
    }

    public function recoverBook(Request $request, Order $order, CodexSceneRun $run, BookPageRecovery $recovery)
    {
        abort_unless($run->order_id === $order->id && $run->production_kind === 'coloring_book', 404);
        abort_unless(app(OrderAccess::class)->controls($order, $request->user()), 403);
        $data = $request->validate(['confirm' => 'required|accepted', 'expected_updated_at' => 'required|string']);
        abort_unless($run->updated_at->toISOString() === $data['expected_updated_at'], 409, 'تغير تقدم الكتاب؛ حدّث الصفحة.');
        $recovery->advance($run, true, $request->user());
        $state = $run->fresh()->book_pdf['recovery'] ?? [];
        if (! $state) {
            throw ValidationException::withMessages(['book_recovery' => 'لم يبدأ الاستكمال: راجع الاتصال بالعامل ووضع الإيقاف، ثم أعد الطلب.']);
        }
        if (($state['status'] ?? '') === 'blocked') {
            throw ValidationException::withMessages(['book_recovery' => $state['reason']]);
        }

        return back()->with('success', 'تم طلب استكمال الصفحات الناقصة فقط؛ الملف النهائي يظهر بعد اكتمال العدد. الصور المحفوظة لن تُعاد.');
    }

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
        $files = [];
        try {
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot prepare scene archive.');
            }
            $opened = true;
            foreach ($scenes as $number => $scene) {
                $bytes = $run->production_kind === 'coloring_book' ? BookPdfService::pageBytes($run, (int) $number, $scene)
                    : Storage::disk('s3_private')->get($scene['path']);
                if (! is_string($bytes) || ! hash_equals($scene['sha256'], hash('sha256', $bytes))) {
                    throw new \RuntimeException('Scene storage verification failed.');
                }
                $file = tempnam(sys_get_temp_dir(), 'codex-page-');
                if ($file === false) {
                    throw new \RuntimeException('Cannot prepare page file.');
                }
                $files[] = $file;
                if (file_put_contents($file, $bytes) !== strlen($bytes)
                    || ! $zip->addFile($file, sprintf(($run->production_kind === 'coloring_book' ? 'page' : 'scene').'-%02d.png', $number))) {
                    throw new \RuntimeException('Cannot add scene to archive.');
                }
                unset($bytes);
            }
            if ($run->production_kind === 'coloring_book') {
                $saved = array_map('intval', array_keys($scenes));
                $missing = array_values(array_diff(range(1, $run->scene_count), $saved));
                if (! $zip->addFromString('manifest.json', json_encode(['book_item_id' => $run->order_item_id,
                    'expected_pages' => $run->scene_count, 'saved_pages' => $saved, 'missing_pages' => $missing,
                    'complete' => $missing === [], 'purpose' => 'saved_images_review'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                    throw new \RuntimeException('Cannot add archive index.');
                }
            }
            if (! $zip->close()) {
                throw new \RuntimeException('Cannot finalize scene archive.');
            }
            $opened = false;
            clearstatcache(true, $path);

            return response()->download($path, 'order-'.$order->id.($run->production_kind === 'coloring_book' ? '-book-pages-' : '-scenes-').$run->id.'.zip', [
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
            report($e);
            abort(503, 'تعذر تجهيز تحميل المشاهد؛ حاول مرة أخرى.');
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    public function image(Order $order, CodexSceneRun $run, int $scene)
    {
        abort_unless($run->order_id === $order->id, 404);
        $data = ($run->scenes ?? [])[(string) $scene] ?? null;
        abort_unless($data, 404);

        if ($run->production_kind === 'coloring_book') {
            try {
                $path = BookPdfService::pagePath($run, $scene, $data);
                if (request()->boolean('thumbnail') && ! request()->boolean('download')) {
                    return app(PrivateOrderThumbnail::class)->response('s3_private', $path);
                }

                return response(BookPdfService::pageBytes($run, $scene, $data), 200, ['Content-Type' => 'image/png',
                    'Content-Disposition' => (request()->boolean('download') ? 'attachment' : 'inline').'; filename="page-'.sprintf('%02d', $scene).'.png"',
                    'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
            } catch (\RuntimeException) {
                abort(404, 'تعذر التحقق من ملف الصفحة الحالية.');
            }
        }

        if (request()->boolean('thumbnail')) {
            return app(PrivateOrderThumbnail::class)->response('s3_private', $data['path']);
        }

        return Storage::disk('s3_private')->response($data['path'], 'scene-'.sprintf('%02d', $scene).'.png', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
