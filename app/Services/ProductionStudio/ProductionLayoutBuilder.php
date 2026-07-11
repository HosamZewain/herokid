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

    public const PAGE_MAP_VERSION = ProductionAutomationLayoutValidator::PAGE_MAP_VERSION;

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
            'pdf_page_representation' => 'one PDF page per printed A3 side',
            'page_map_version' => self::PAGE_MAP_VERSION,
            'layout_template_version' => config('production_studio.automation.layout_template_version', 'layout-print-v1'),
            'renderer' => 'mpdf',
            'font_package' => 'dejavusans',
            'paper_recommendation' => [
                'inner_pages' => 'A3 landscape duplex, fold to A4.',
                'cover' => 'Use the approved HeroKid cover stock policy.',
                'duplex_flip' => $settings['duplex_flip'] ?? 'short_edge',
                'binding_direction' => $settings['binding_direction'] ?? 'rtl',
            ],
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

        foreach ($pages as $page) {
            $pdf->AddPage();
            $this->renderPage($pdf, $page, $settings, 0, 210);
        }

        $pdf->Output(Storage::disk('local')->path($path), Destination::FILE);
    }

    private function writePrintPdf(array $pages, array $settings, array $manifest, string $path): void
    {
        $pdf = $this->newPdf('A3-L');

        foreach ($manifest['sheets'] as $sheet) {
            foreach (['front', 'back'] as $side) {
                $pdf->AddPage('L');

                $pair = $sheet[$side];
                $this->renderPage($pdf, $pages[$pair['left_page']], $settings, 0, 210);
                $this->renderPage($pdf, $pages[$pair['right_page']], $settings, 210, 210);
            }
        }

        $pdf->Output(Storage::disk('local')->path($path), Destination::FILE);
    }

    private function writeProofChecklist(ProductionProject $project, ProductionPrintLayout $layout, array $manifest, string $path): void
    {
        $pdf = $this->newPdf('A4');
        $orderNumber = e((string) $project->order?->order_number);
        $rows = collect([
            'اتساق هوية الطفل في الغلاف وجميع المشاهد.',
            'جودة الغلاف الأمامي وعدم وجود نص مولد داخل الصورة.',
            'مطابقة كل مشهد للنص المقابل له.',
            'سلامة النص العربي والتشكيل البصري واتجاه RTL.',
            'الإملاء وعلامات الترقيم سليمة.',
            'النص مقروء ولا يوجد قص أو فيضان خارج منطقة الأمان.',
            'جودة الصور ودقتها بعد الطباعة.',
            'ترتيب الصفحات في ملف Reader صحيح.',
            'موضع الغلاف الأمامي صحيح.',
            'موضع الغلاف الخلفي صحيح.',
            'إعداد الطباعة Duplex وFlip on short edge صحيح.',
            'اتجاه الطي والربط صحيح للكتاب العربي.',
            'الهوامش ومنطقة القص آمنة.',
            'لا يوجد مشهد مفقود.',
            'لا يوجد مشهد مكرر.',
            'لا توجد صفحات فارغة غير مقصودة.',
            'تمت مراجعة نسخة مطبوعة تجريبية.',
            'جودة الألوان مقبولة في العينة المطبوعة.',
            'الملف جاهز للطي والتجليد بعد المراجعة البشرية النهائية.',
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

    private function renderPage(Mpdf $pdf, array $page, array $settings, int $offsetMm, int $widthMm): void
    {
        if ($page['image_path']) {
            $pdf->Image(
                Storage::disk('local')->path($page['image_path']),
                $offsetMm,
                0,
                $widthMm,
                297,
                '',
                '',
                true,
                false
            );
        } else {
            $pdf->SetFillColor(17, 24, 39);
            $pdf->Rect($offsetMm, 0, $widthMm, 297, 'F');
        }

        if ($page['type'] === 'back_cover') {
            $html = '<div dir="rtl" style="color:#ffffff;text-align:center;font-family:dejavusans;font-size:18pt;line-height:1.7;">'
                .e((string) ($page['text'] ?? ''))
                .'<br><strong>'.e((string) ($page['website'] ?? '')).'</strong></div>';
            $pdf->WriteFixedPosHTML($html, $offsetMm + 20, 108, $widthMm - 40, 82, 'auto');

            return;
        }

        if ($page['type'] === 'front_cover' && filled($page['cover_title'] ?? null)) {
            $titleY = ($page['cover_title_position'] ?? 'top') === 'bottom' ? 226 : 16;
            $this->drawTextPanel($pdf, $offsetMm + 16, $titleY, $widthMm - 32, 55, 0.9);
            $html = '<div dir="rtl" style="text-align:center;color:#111827;font-family:dejavusans;">'
                .'<div style="font-size:25pt;font-weight:bold;line-height:1.35;">'.e((string) $page['cover_title']).'</div>'
                .(filled($page['cover_subtitle'] ?? null) ? '<div style="margin-top:3mm;font-size:15pt;">'.e((string) $page['cover_subtitle']).'</div>' : '')
                .'</div>';
            $pdf->WriteFixedPosHTML($html, $offsetMm + 22, $titleY + 6, $widthMm - 44, 43, 'auto');

            return;
        }

        if (! filled($page['text'])) {
            return;
        }

        $panelHeight = 90;
        $panelY = match ($page['text_position'] ?? 'bottom') {
            'top' => 12,
            'center' => 104,
            default => 195,
        };
        $panelX = $offsetMm + 12;
        $panelWidth = $widthMm - 24;
        $opacity = max(70, min(100, (int) ($settings['text_panel_opacity'] ?? 92))) / 100;
        $fontSize = max(14, min(30, (int) ($settings['font_size'] ?? 20)));

        $this->drawTextPanel($pdf, $panelX, $panelY, $panelWidth, $panelHeight, $opacity);
        $html = '<div dir="rtl" style="color:#111827;font-family:dejavusans;font-size:'.$fontSize.'pt;line-height:1.65;text-align:right;">'
            .nl2br(e((string) $page['text']))
            .'</div>';
        $pdf->WriteFixedPosHTML($html, $panelX + 7, $panelY + 6, $panelWidth - 14, $panelHeight - 12, 'auto');
    }

    private function drawTextPanel(Mpdf $pdf, float $x, float $y, float $width, float $height, float $opacity): void
    {
        $pdf->SetAlpha($opacity);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $width, $height, 'F');
        $pdf->SetAlpha(1);
    }

    public function manifestCsv(array $manifest): string
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
}
