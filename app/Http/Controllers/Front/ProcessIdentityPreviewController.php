<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Services\OrderAutomation\Process\ProcessRuntime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

class ProcessIdentityPreviewController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff'];

    public function show(Request $request, OrderProcessRun $run)
    {
        $this->guard($run);
        $tasks = OrderProcessIdentity::where('run_id', $run->id)->with(['order', 'attempt'])->get();
        abort_unless($tasks->isNotEmpty() && $tasks->every(fn ($t) => $t->status === 'succeeded'), 404);
        $images = $tasks->map(fn ($task) => ['name' => $task->order->child_name,
            'url' => URL::temporarySignedRoute('order-process.identity-image', now()->addMinutes(15)->min(Carbon::createFromTimestamp((int) $request->query('expires'))), ['run' => $run->id, 'identity' => $task->id])]);

        return response()->view('front.order-process-identity-preview', compact('images'), 200, self::HEADERS);
    }

    public function image(OrderProcessRun $run, OrderProcessIdentity $identity)
    {
        $this->guard($run);
        abort_unless($identity->run_id === $run->id && $identity->status === 'succeeded', 404);
        $attempt = $identity->attempt;
        abort_unless($attempt?->status === 'succeeded' && $attempt->output_storage_path, 404);
        $disk = Storage::disk($attempt->output_disk);
        abort_unless($disk->exists($attempt->output_storage_path) && $disk->size($attempt->output_storage_path) <= 15 * 1024 * 1024, 404);
        $bytes = $disk->get($attempt->output_storage_path);
        abort_unless(hash_equals($attempt->output_checksum, hash('sha256', $bytes)), 404);
        $mime = data_get($attempt->response_metadata, 'output_mime_type');
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);

        return response($bytes, 200, self::HEADERS + ['Content-Type' => $mime, 'Content-Disposition' => 'inline']);
    }

    private function guard(OrderProcessRun $run): void
    {
        abort_if(in_array($run->status, ['cancelled', 'blocked', 'uncertain'], true), 404);
        try {
            app(ProcessRuntime::class)->guard($run);
        } catch (Throwable) {
            abort(404);
        }
    }
}
