<?php

namespace App\Services\CodexScenes;

use App\Models\CodexSceneRun;
use App\Models\OrderAttachment;
use App\Services\Orders\AutomationActivity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

/** Packages saved pages only. Never dispatches generation or changes customer approval. */
class BookPdfService
{
    public function request(CodexSceneRun $run): void
    {
        $revision = $this->revision($run);
        if (($run->book_pdf['revision'] ?? null) !== $revision) {
            $run->book_pdf = ['status' => 'pending', 'revision' => $revision, 'attempts' => 0];
        }
    }

    private function revision(CodexSceneRun $run): string
    {
        $pages = $run->scenes ?? [];
        $canonical = function (array $value) use (&$canonical): array {
            ksort($value, SORT_STRING);
            foreach ($value as &$entry) {
                if (is_array($entry)) {
                    $entry = $canonical($entry);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical([$run->id, $run->order_item_id, $run->scene_count, $pages])));
    }

    public function retryPending(): void
    {
        // NULL means historical/unrequested: deliberately do not repack old orders.
        foreach (CodexSceneRun::where('production_kind', 'coloring_book')->whereNotNull('book_pdf')
            ->where(fn ($q) => $q->whereIn('book_pdf->status', ['pending', 'failed'])->where('book_pdf->attempts', '<', 3)->orWhere('book_pdf->status', 'processing'))->limit(5)->get() as $run) {
            $this->publish($run);
        }
    }

    public function publish(CodexSceneRun $run): void
    {
        if ($run->production_kind !== 'coloring_book' || ! in_array($run->book_pdf['status'] ?? '', ['pending', 'failed', 'processing'], true)) {
            return;
        }
        Cache::lock('book-pdf:'.$run->id, 900)->get(function () use ($run) {
            $run->refresh();
            $meta = $run->book_pdf;
            if (! in_array($meta['status'] ?? '', ['pending', 'failed', 'processing'], true)
                || (($meta['status'] ?? '') === 'processing' && now()->lt(Carbon::parse($meta['lease_until'])))
                || ! hash_equals($meta['revision'], $this->revision($run))) {
                return;
            }
            if (($meta['attempts'] ?? 0) >= 3) {
                $meta['status'] = 'failed';
                $meta['error_code'] = 'book_pdf_export_interrupted';
                $run->update(['book_pdf' => $meta]);

                return;
            }
            $meta['attempts']++;
            $meta['status'] = 'processing';
            $meta['lease_until'] = now()->addMinutes(15)->toIso8601String();
            $run->update(['book_pdf' => $meta]);
            try {
                $bytes = $this->render($run);
                $sha = hash('sha256', $bytes);
                $path = 'orders/'.$run->order_id.'/codex-scenes/'.$run->id.'/book-'.$meta['revision'].'.pdf';
                $disk = Storage::disk('s3_private');
                if (! $disk->put($path, $bytes, ['visibility' => 'private']) || ! hash_equals($sha, hash('sha256', $disk->get($path)))) {
                    throw new RuntimeException('PDF storage verification failed');
                }
                DB::transaction(function () use ($run, $meta, $bytes, $sha, $path) {
                    $current = CodexSceneRun::lockForUpdate()->findOrFail($run->id);
                    if (! hash_equals($meta['revision'], $this->revision($current)) || ($current->book_pdf['status'] ?? '') === 'ready') {
                        return;
                    }
                    $missing = array_values(array_diff(range(1, $run->scene_count), array_map('intval', array_keys($run->scenes))));
                    $complete = $missing === [];
                    $attachment = OrderAttachment::firstOrCreate(['order_id' => $run->order_id, 'disk' => 's3_private', 'path' => $path], [
                        'uploaded_by_user_id' => null, 'production_unit_key' => $run->snapshot['unit_key'],
                        'original_name' => 'Coloring-book-'.$run->order_item_id.($complete ? '' : '-INCOMPLETE').'.pdf',
                        'mime_type' => 'application/pdf', 'size' => strlen($bytes),
                        'note' => 'Codex / '.$run->id.' / '.($complete ? 'صفحات كتاب التلوين — A4 — راجع الجودة قبل الطباعة' : 'كتاب غير مكتمل — لا تطبع. الصفحات الناقصة: '.implode('، ', $missing)),
                    ]);
                    $current->update(['book_pdf' => $meta + ['attachment_id' => $attachment->id, 'path' => $path, 'sha256' => $sha, 'missing_pages' => $missing, 'complete' => $complete, 'published_at' => now()->toIso8601String()]]);
                    $published = $current->book_pdf;
                    $published['status'] = 'ready';
                    $current->update(['book_pdf' => $published]);
                    AutomationActivity::record($run->order, 'coloring_book_pdf_saved', $complete ? 'تجميع صفحات كتاب التلوين وحفظ PDF واحد في مرفقات الطلب.' : 'حفظ PDF غير مكتمل للمراجعة؛ الصفحات الناقصة محددة داخل الملف.',
                        ['run_id' => $run->id, 'order_item_id' => $run->order_item_id, 'attachment_id' => $attachment->id, 'sha256' => $sha, 'missing_pages' => $missing]);
                });
            } catch (\Throwable $e) {
                // Only assembly is retried. Accepted images and provider state remain untouched.
                $meta['status'] = 'failed';
                $meta['error_code'] = 'book_pdf_export_failed';
                $run->update(['book_pdf' => $meta]);
                AutomationActivity::record($run->order, 'coloring_book_pdf_failed', 'تعذر تجميع PDF كتاب التلوين؛ الصور محفوظة. إعادة محاولة التجميع فقط حتى ثلاث مرات، دون إعادة التوليد.',
                    ['run_id' => $run->id, 'attempt' => $meta['attempts']]);
                report(new RuntimeException('Book PDF export failed: '.$run->id.' ('.class_basename($e).')'));
            }
        });
    }

    public static function pageBytes(CodexSceneRun $run, int $number, array $scene): string
    {
        $sourceId = $scene['source_run_id'] ?? $run->id;
        $source = $sourceId === $run->id ? $run : CodexSceneRun::find($sourceId);
        if (! $source || $source->production_kind !== 'coloring_book' || $source->order_id !== $run->order_id || $source->order_item_id !== $run->order_item_id
            || ($source->snapshot['unit_key'] ?? null) !== ($run->snapshot['unit_key'] ?? null)
            || ($source->scenes[(string) $number]['sha256'] ?? null) !== ($scene['sha256'] ?? null)) {
            throw new RuntimeException('PDF page belongs to another book');
        }
        $prefix = 'orders/'.$run->order_id.'/codex-scenes/'.$sourceId.'/';
        if (! in_array($scene['path'] ?? '', [$prefix.'scene-'.sprintf('%02d', $number).'.png', $prefix.'page-'.sprintf('%02d', $number).'-a4.png'], true)) {
            throw new RuntimeException('PDF page path mismatch');
        }
        $bytes = Storage::disk('s3_private')->get($scene['path']);
        $size = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        if (! $size || $size['mime'] !== 'image/png' || min($size[0], $size[1]) < 1024 || $size[1] <= $size[0]
            || ! hash_equals($scene['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('PDF page integrity failed');
        }

        return $bytes;
    }

    public function render(CodexSceneRun $run): string
    {
        if ($run->production_kind !== 'coloring_book' || ! $run->order_item_id || ! $run->scenes || $run->scene_count < 1 || $run->scene_count > 1000) {
            throw new RuntimeException('Invalid book PDF input');
        }
        $temp = storage_path('app/private/book-pdf/'.bin2hex(random_bytes(12)));
        File::ensureDirectoryExists($temp, 0700);
        try {
            $pdf = new Mpdf(['format' => 'A4', 'orientation' => 'P', 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
                'tempDir' => $temp, 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
            $pdf->SetTitle('Coloring book '.$run->order_item_id);
            $pdf->SetAuthor('HeroKid Automation');
            $partial = count($run->scenes) !== $run->scene_count;
            foreach (range(1, $run->scene_count) as $number) {
                $pdf->AddPage();
                $scene = $run->scenes[(string) $number] ?? null;
                if ($scene) {
                    $bytes = self::pageBytes($run, $number, $scene);
                    $size = getimagesizefromstring($bytes);
                    $file = $temp.'/page-'.$number.'.png';
                    file_put_contents($file, $bytes);
                    chmod($file, 0600);
                    $scale = min(210 / $size[0], 297 / $size[1]);
                    $w = $size[0] * $scale;
                    $h = $size[1] * $scale;
                    $pdf->Image($file, (210 - $w) / 2, (297 - $h) / 2, $w, $h, 'png');
                    unset($bytes);
                } else {
                    $pdf->WriteFixedPosHTML('<div dir="rtl" style="text-align:center;font-size:26pt;color:#b91c1c">ملف غير مكتمل — لا تطبع<br>الصفحة '.$number.' غير متاحة<br>يلزم استكمالها ومراجعة الفريق</div>', 15, 110, 180, 70);
                }
                if ($partial) {
                    $pdf->WriteFixedPosHTML('<div dir="rtl" style="background:#fff;color:#b91c1c;text-align:center;font-size:12pt">غير مكتمل — للمراجعة فقط — صفحة '.$number.'</div>', 5, 3, 200, 12);
                }
            }

            return $pdf->Output('', Destination::STRING_RETURN);
        } finally {
            File::deleteDirectory($temp);
        }
    }
}
