<?php

namespace App\Services\ProductionStudio;

use App\Models\ProductionPrintLayout;
use App\Models\ProductionProject;
use App\Models\ProductionProjectAsset;
use App\Models\ProductionScene;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

class ProductionLayoutBuilder
{
    public const PAGE_COUNT = 28;

    public const SCENE_COUNT = 13;

    public const SHEET_COUNT = 7;

    public function defaults(ProductionProject $project): array
    {
        $project->loadMissing(['order.story', 'scenes']);

        return [
            'book_title' => $project->order?->story?->title ?: setting('site_name', ''),
            'cover_subtitle' => str_replace('{{child_name}}', (string) $project->order?->child_name, setting('production_cover_subtitle_template', '')),
            'cover_title_position' => 'top',
            'back_cover_text' => setting('production_back_cover_text', ''),
            'website' => setting('production_layout_website', ''),
            'binding_direction' => 'rtl',
            'duplex_flip' => 'short_edge',
            'font_size' => 20,
            'text_panel_opacity' => 92,
            'cover_asset_id' => $project->assets()->where('asset_type', 'cover_image')->where('status', 'approved')->where('is_final', true)->value('id'),
            'back_cover_asset_id' => $project->assets()->where('asset_type', 'back_cover_image')->where('status', 'approved')->latest()->value('id'),
            'scenes' => $project->scenes->mapWithKeys(fn (ProductionScene $scene): array => [
                (string) $scene->id => [
                    'text_content' => $scene->story_text,
                    'text_side' => $scene->scene_number % 2 === 1 ? 'left' : 'right',
                    'text_position' => 'bottom',
                ],
            ])->all(),
        ];
    }

    public function normalizedSettings(ProductionProject $project, array $settings): array
    {
        $defaults = $this->defaults($project);
        $normalized = array_replace($defaults, $settings);
        $normalized['scenes'] = array_replace($defaults['scenes'], $settings['scenes'] ?? []);

        foreach (['book_title', 'cover_title_position', 'binding_direction', 'duplex_flip', 'font_size', 'text_panel_opacity'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $normalized) || $normalized[$requiredKey] === null || $normalized[$requiredKey] === '') {
                $normalized[$requiredKey] = $defaults[$requiredKey];
            }
        }

        return $normalized;
    }

    public function readiness(ProductionProject $project, array $settings): array
    {
        $project->loadMissing(['scenes.approvedFinalImage', 'assets']);
        $errors = [];
        $scenes = $project->scenes->sortBy('scene_number')->values();

        if ($scenes->count() !== self::SCENE_COUNT || $scenes->pluck('scene_number')->all() !== range(1, self::SCENE_COUNT)) {
            $errors[] = 'يجب أن يحتوي المشروع على 13 مشهدًا مرقمة من 1 إلى 13.';
        }

        foreach ($scenes as $scene) {
            if (! $scene->approvedFinalImage?->file_path) {
                $errors[] = "المشهد {$scene->scene_number} لا يحتوي على صورة نهائية معتمدة.";
            }

            if (blank(data_get($settings, "scenes.{$scene->id}.text_content"))) {
                $errors[] = "المشهد {$scene->scene_number} لا يحتوي على نص إخراج.";
            }
        }

        $cover = $this->selectedAsset($project, data_get($settings, 'cover_asset_id'), ['cover_image']);
        if (! $cover || $cover->status !== 'approved') {
            $errors[] = 'اختر غلافًا أماميًا معتمدًا قبل التوليد.';
        }

        return [
            'ready' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'approved_scenes' => $scenes->filter(fn (ProductionScene $scene): bool => filled($scene->approvedFinalImage?->file_path))->count(),
            'scene_count' => $scenes->count(),
            'cover_ready' => (bool) $cover,
        ];
    }

    public function generate(ProductionPrintLayout $layout): array
    {
        $layout->loadMissing(['project.order.story', 'project.scenes.approvedFinalImage', 'project.assets']);
        $project = $layout->project;
        $settings = $this->normalizedSettings($project, $layout->settings_json ?? []);
        $readiness = $this->readiness($project, $settings);

        if (! $readiness['ready']) {
            throw new RuntimeException(implode(' ', $readiness['errors']));
        }

        $base = "production-studio/projects/{$project->id}/layout/v{$layout->version_number}";
        Storage::disk('local')->deleteDirectory($base);
        Storage::disk('local')->makeDirectory($base.'/pages');

        $pages = $this->buildReaderPages($project, $settings, $base);
        $manifest = $this->buildManifest($settings);
        $readerPath = $base.'/reader-order.pdf';
        $printPath = $base.'/print-ready-a3-booklet.pdf';
        $manifestPath = $base.'/print-manifest.csv';
        $proofPath = $base.'/proof-print-checklist.pdf';

        $this->writeReaderPdf($pages, $settings, $readerPath);
        $this->writePrintPdf($pages, $settings, $manifest, $printPath);
        Storage::disk('local')->put($manifestPath, $this->manifestCsv($manifest));
        $this->writeProofChecklist($project, $layout, $manifest, $proofPath);

        return [
            'settings' => $settings,
            'manifest' => $manifest,
            'reader_pdf_path' => $readerPath,
            'print_pdf_path' => $printPath,
            'manifest_path' => $manifestPath,
            'proof_checklist_path' => $proofPath,
        ];
    }

    public function buildManifest(array $settings): array
    {
        $rtl = ($settings['binding_direction'] ?? 'rtl') === 'rtl';
        $sheets = [];

        for ($sheet = 1; $sheet <= self::SHEET_COUNT; $sheet++) {
            $offset = ($sheet - 1) * 2;
            $frontPair = [self::PAGE_COUNT - $offset, 1 + $offset];
            $backPair = [2 + $offset, self::PAGE_COUNT - 1 - $offset];

            if ($rtl) {
                $frontPair = array_reverse($frontPair);
                $backPair = array_reverse($backPair);
            }

            $sheets[] = [
                'sheet_number' => $sheet,
                'front' => ['left_page' => $frontPair[0], 'right_page' => $frontPair[1]],
                'back' => ['left_page' => $backPair[0], 'right_page' => $backPair[1]],
                'flip_direction' => $settings['duplex_flip'] ?? 'short_edge',
            ];
        }

        return [
            'page_count' => self::PAGE_COUNT,
            'scene_count' => self::SCENE_COUNT,
            'sheet_count' => self::SHEET_COUNT,
            'printed_sides' => self::SHEET_COUNT * 2,
            'binding_direction' => $settings['binding_direction'] ?? 'rtl',
            'duplex_flip' => $settings['duplex_flip'] ?? 'short_edge',
            'canvas' => 'A3 landscape 420 × 297 mm',
            'canvas_pixels' => '4961 × 3508 px at 300 DPI',
            'reader_page' => 'A4 portrait 210 × 297 mm',
            'sheets' => $sheets,
        ];
    }

    private function buildReaderPages(ProductionProject $project, array $settings, string $base): array
    {
        $pages = [];
        $cover = $this->selectedAsset($project, $settings['cover_asset_id'], ['cover_image']);
        $pages[1] = $this->imagePage(1, 'front_cover', $cover?->file_path, $base, null, null);
        $pages[1]['cover_title'] = $settings['book_title'] ?? null;
        $pages[1]['cover_subtitle'] = $settings['cover_subtitle'] ?? null;
        $pages[1]['cover_title_position'] = $settings['cover_title_position'] ?? 'top';

        foreach ($project->scenes->sortBy('scene_number') as $scene) {
            $firstPage = $scene->scene_number * 2;
            $sceneSettings = data_get($settings, "scenes.{$scene->id}", []);
            $textSide = $sceneSettings['text_side'] ?? 'left';
            $text = trim((string) ($sceneSettings['text_content'] ?? $scene->story_text));
            $source = $scene->approvedFinalImage->file_path;

            $pages[$firstPage] = $this->imagePage(
                $firstPage,
                'scene_right',
                $source,
                $base,
                $textSide === 'right' ? $text : null,
                $sceneSettings['text_position'] ?? 'bottom',
                'right'
            );
            $pages[$firstPage + 1] = $this->imagePage(
                $firstPage + 1,
                'scene_left',
                $source,
                $base,
                $textSide === 'left' ? $text : null,
                $sceneSettings['text_position'] ?? 'bottom',
                'left'
            );
        }

        $backCover = $this->selectedAsset($project, $settings['back_cover_asset_id'] ?? null, ['back_cover_image', 'cover_image']);
        $pages[self::PAGE_COUNT] = $backCover
            ? $this->imagePage(self::PAGE_COUNT, 'back_cover', $backCover->file_path, $base, null, null)
            : [
                'number' => self::PAGE_COUNT,
                'type' => 'back_cover',
                'image_path' => null,
                'text' => $settings['back_cover_text'] ?? null,
                'text_position' => 'center',
                'website' => $settings['website'] ?? null,
            ];

        ksort($pages);

        return $pages;
    }

    private function imagePage(int $number, string $type, ?string $sourcePath, string $base, ?string $text, ?string $textPosition, ?string $half = null): array
    {
        if (! $sourcePath) {
            throw new RuntimeException("مصدر صورة الصفحة {$number} غير موجود.");
        }

        $source = $this->absolutePrivatePath($sourcePath);
        $contents = file_get_contents($source);
        $image = $contents === false ? false : imagecreatefromstring($contents);

        if (! $image) {
            throw new RuntimeException("تعذر قراءة صورة الصفحة {$number}.");
        }

        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        $cropX = 0;
        $cropY = 0;
        $cropWidth = $sourceWidth;
        $cropHeight = $sourceHeight;

        if ($half !== null) {
            $spreadRatio = 4961 / 3508;
            $sourceRatio = $sourceWidth / $sourceHeight;

            if ($sourceRatio > $spreadRatio) {
                $cropWidth = (int) round($sourceHeight * $spreadRatio);
                $cropX = (int) floor(($sourceWidth - $cropWidth) / 2);
            } elseif ($sourceRatio < $spreadRatio) {
                $cropHeight = (int) round($sourceWidth / $spreadRatio);
                $cropY = (int) floor(($sourceHeight - $cropHeight) / 2);
            }

            $leftSourceWidth = (int) floor($cropWidth * (2480 / 4961));
            if ($half === 'right') {
                $cropX += $leftSourceWidth;
                $cropWidth -= $leftSourceWidth;
            } else {
                $cropWidth = $leftSourceWidth;
            }
        } else {
            $targetRatio = 2480 / 3508;
            $sourceRatio = $sourceWidth / $sourceHeight;
            if ($sourceRatio > $targetRatio) {
                $cropWidth = (int) round($sourceHeight * $targetRatio);
                $cropX = (int) floor(($sourceWidth - $cropWidth) / 2);
            } elseif ($sourceRatio < $targetRatio) {
                $cropHeight = (int) round($sourceWidth / $targetRatio);
                $cropY = (int) floor(($sourceHeight - $cropHeight) / 2);
            }
        }

        $targetWidth = $half === 'right' ? 2481 : 2480;
        $targetHeight = 3508;
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (function_exists('imageresolution')) {
            imageresolution($target, 300, 300);
        }

        imagecopyresampled($target, $image, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
        $path = $base.'/pages/page-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).'.jpg';
        $absolute = Storage::disk('local')->path($path);
        imagejpeg($target, $absolute, 93);
        imagedestroy($target);
        imagedestroy($image);

        return [
            'number' => $number,
            'type' => $type,
            'image_path' => $path,
            'text' => $text,
            'text_position' => $textPosition,
            'website' => null,
        ];
    }

    private function writeReaderPdf(array $pages, array $settings, string $path): void
    {
        $pdf = $this->newPdf('A4');

        foreach ($pages as $index => $page) {
            if ($index > 1) {
                $pdf->AddPage();
            }
            $pdf->WriteHTML($this->pageHtml($page, $settings, 0, 210));
        }

        $pdf->Output(Storage::disk('local')->path($path), Destination::FILE);
    }

    private function writePrintPdf(array $pages, array $settings, array $manifest, string $path): void
    {
        $pdf = $this->newPdf('A3-L');
        $sideIndex = 0;

        foreach ($manifest['sheets'] as $sheet) {
            foreach (['front', 'back'] as $side) {
                if ($sideIndex > 0) {
                    $pdf->AddPage('L');
                }

                $pair = $sheet[$side];
                $pdf->WriteHTML($this->pageHtml($pages[$pair['left_page']], $settings, 0, 210));
                $pdf->WriteHTML($this->pageHtml($pages[$pair['right_page']], $settings, 210, 210));
                $sideIndex++;
            }
        }

        $pdf->Output(Storage::disk('local')->path($path), Destination::FILE);
    }

    private function writeProofChecklist(ProductionProject $project, ProductionPrintLayout $layout, array $manifest, string $path): void
    {
        $pdf = $this->newPdf('A4');
        $orderNumber = e((string) $project->order?->order_number);
        $rows = collect([
            'تمت مراجعة الغلاف الأمامي والخلفي.',
            'تمت مراجعة جميع المشاهد الثلاثة عشر.',
            'النص العربي سليم وغير مقطوع.',
            'لا يوجد نص فوق الوجه أو العناصر المهمة.',
            'جميع الحواف ومنطقة الطي آمنة.',
            'ترتيب الطباعة مطابق للمانيفست.',
            'إعداد الطباعة Duplex وFlip on short edge صحيح.',
            'تمت مراجعة نسخة مطبوعة تجريبية.',
        ])->map(fn (string $item): string => '<div style="padding:4mm;border-bottom:0.3mm solid #ddd;">☐ '.e($item).'</div>')->implode('');

        $pdf->WriteHTML('<div dir="rtl" style="font-family:dejavusans;text-align:right;padding:12mm;">'
            .'<h1>قائمة مراجعة الطباعة</h1>'
            .'<p>الطلب: '.$orderNumber.' | إصدار الإخراج: '.e((string) $layout->version_number).'</p>'
            .'<p>28 صفحة A4 | 13 مشهدًا | '.e((string) $manifest['sheet_count']).' شيت A3 Duplex</p>'
            .$rows
            .'</div>');
        $pdf->Output(Storage::disk('local')->path($path), Destination::FILE);
    }

    private function newPdf(string $format): Mpdf
    {
        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'tempDir' => $tempDir,
            'default_font' => 'dejavusans',
        ]);
        $pdf->autoScriptToLang = true;
        $pdf->autoLangToFont = true;
        $pdf->SetTitle('Hero Kid Production Layout');

        return $pdf;
    }

    private function pageHtml(array $page, array $settings, int $offsetMm, int $widthMm): string
    {
        $image = $page['image_path']
            ? '<img src="'.$this->fileUri(Storage::disk('local')->path($page['image_path'])).'" style="position:absolute;left:0;top:0;width:'.$widthMm.'mm;height:297mm;" />'
            : '<div style="position:absolute;inset:0;background:#111827;"></div>';
        $content = '';

        if (filled($page['text'])) {
            $position = match ($page['text_position'] ?? 'bottom') {
                'top' => 'top:12mm;',
                'center' => 'top:104mm;',
                default => 'bottom:12mm;',
            };
            $fontSize = max(14, min(30, (int) ($settings['font_size'] ?? 20)));
            $opacity = max(70, min(100, (int) ($settings['text_panel_opacity'] ?? 92))) / 100;
            $content .= '<div dir="rtl" style="position:absolute;'.$position.'left:12mm;width:'.($widthMm - 24).'mm;padding:7mm;box-sizing:border-box;background:rgba(255,255,255,'.$opacity.');color:#111827;font-family:dejavusans;font-size:'.$fontSize.'pt;line-height:1.65;text-align:right;border-radius:3mm;">'.nl2br(e((string) $page['text'])).'</div>';
        }

        if ($page['type'] === 'back_cover') {
            $content .= '<div dir="rtl" style="position:absolute;top:110mm;left:20mm;width:'.($widthMm - 40).'mm;color:white;text-align:center;font-family:dejavusans;font-size:18pt;line-height:1.7;">'.e((string) ($page['text'] ?? '')).'<br><strong>'.e((string) ($page['website'] ?? '')).'</strong></div>';
        }

        if ($page['type'] === 'front_cover' && filled($page['cover_title'] ?? null)) {
            $titlePosition = ($page['cover_title_position'] ?? 'top') === 'bottom' ? 'bottom:16mm;' : 'top:16mm;';
            $content .= '<div dir="rtl" style="position:absolute;'.$titlePosition.'left:16mm;width:'.($widthMm - 32).'mm;padding:6mm;background:rgba(255,255,255,0.90);text-align:center;color:#111827;font-family:dejavusans;">'
                .'<div style="font-size:25pt;font-weight:bold;line-height:1.35;">'.e((string) $page['cover_title']).'</div>'
                .(filled($page['cover_subtitle'] ?? null) ? '<div style="margin-top:3mm;font-size:15pt;">'.e((string) $page['cover_subtitle']).'</div>' : '')
                .'</div>';
        }

        return '<div style="position:absolute;left:'.$offsetMm.'mm;top:0;width:'.$widthMm.'mm;height:297mm;overflow:hidden;">'.$image.$content.'</div>';
    }

    private function manifestCsv(array $manifest): string
    {
        $lines = [
            ['Sheet', 'Side', 'Left Page', 'Right Page', 'Flip Direction'],
        ];

        foreach ($manifest['sheets'] as $sheet) {
            foreach (['front', 'back'] as $side) {
                $lines[] = [
                    $sheet['sheet_number'],
                    ucfirst($side),
                    $sheet[$side]['left_page'],
                    $sheet[$side]['right_page'],
                    $sheet['flip_direction'],
                ];
            }
        }

        return "\xEF\xBB\xBF".collect($lines)
            ->map(fn (array $row): string => collect($row)->map(fn ($value): string => '"'.str_replace('"', '""', (string) $value).'"')->implode(','))
            ->implode("\n");
    }

    private function selectedAsset(ProductionProject $project, mixed $assetId, array $types): ?ProductionProjectAsset
    {
        if (! $assetId) {
            return null;
        }

        return $project->assets->first(fn (ProductionProjectAsset $asset): bool => $asset->id === (int) $assetId && in_array($asset->asset_type, $types, true));
    }

    private function absolutePrivatePath(string $path): string
    {
        if (str_contains($path, '..') || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('أحد أصول الإخراج غير متاح في التخزين الخاص.');
        }

        return Storage::disk('local')->path($path);
    }

    private function fileUri(string $path): string
    {
        return 'file://'.str_replace('%2F', '/', rawurlencode($path));
    }
}
