<?php

namespace App\Services\ProductionStudio;

use App\Models\ProductionPrintLayout;
use App\Models\ProductionProjectAsset;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Storage;

class ProductionAutomationLayoutValidator
{
    public const PAGE_MAP_VERSION = 'herokid-reader-28-a3-side-map-v1';

    public const RENDERER_VERSION = 'mpdf';

    public const FONT_PACKAGE_VERSION = 'dejavusans-mpdf';

    public function __construct(
        private readonly ProductionAutomationFingerprint $fingerprints,
    ) {}

    public function validate(ProductionPrintLayout $layout): array
    {
        $layout->loadMissing(['project.order.story', 'project.scenes.approvedFinalImage', 'project.assets']);

        $manifest = $layout->manifest_json ?? [];
        $result = [
            'ok' => true,
            'errors' => [],
            'warnings' => [],
            'structure' => [],
            'content' => [],
            'typography' => [],
            'images' => [],
            'files' => [],
            'layout_template_version' => config('production_studio.automation.layout_template_version', 'layout-print-v1'),
            'page_map_version' => self::PAGE_MAP_VERSION,
            'renderer_version' => $this->rendererVersion(),
            'font_package_version' => self::FONT_PACKAGE_VERSION,
            'generated_at' => now()->toIso8601String(),
            'input_fingerprint' => $layout->input_fingerprint,
        ];

        $files = [
            'reader_pdf' => [$layout->reader_pdf_path, 'pdf', 28, 'A4 portrait'],
            'print_pdf' => [$layout->print_pdf_path, 'pdf', 14, 'A3 landscape printed-side PDF'],
            'proof_checklist' => [$layout->proof_checklist_path, 'pdf', null, 'A4 proof checklist'],
            'manifest' => [$layout->manifest_path, 'csv', null, 'print manifest'],
        ];

        foreach ($files as $key => [$path, $type, $expectedPages, $expectedSize]) {
            $file = $this->validateFile($key, $path, $type, $expectedPages, $expectedSize);
            $result['files'][$key] = $file;

            if (! $file['ok']) {
                $result['ok'] = false;
                array_push($result['errors'], ...$file['errors']);
            }

            array_push($result['warnings'], ...$file['warnings']);
        }

        $manifestCheck = $this->validateManifest($manifest);
        $result['structure'] = $manifestCheck;
        if (! $manifestCheck['ok']) {
            $result['ok'] = false;
            array_push($result['errors'], ...$manifestCheck['errors']);
        }

        $contentCheck = $this->validateContent($layout);
        $result['content'] = $contentCheck;
        if (! $contentCheck['ok']) {
            $result['ok'] = false;
            array_push($result['errors'], ...$contentCheck['errors']);
        }

        $imageCheck = $this->validateImageResolution($layout);
        $result['images'] = $imageCheck;
        array_push($result['warnings'], ...$imageCheck['warnings']);
        if (! $imageCheck['ok']) {
            $result['ok'] = false;
            array_push($result['errors'], ...$imageCheck['errors']);
        }

        $result['typography'] = [
            'rtl_configuration' => true,
            'arabic_shaping_renderer' => 'mpdf autoScriptToLang autoLangToFont',
            'embedded_font_required' => true,
            'font_family' => 'dejavusans',
            'human_proof_required_for_visual_glyph_review' => true,
        ];
        $result['known_non_automatable_checks'] = [
            'Final Arabic proofreading and punctuation.',
            'Human visual confirmation that shaped Arabic glyphs and paragraph direction look correct on printed output.',
            'Test-print color quality and physical fold/binding inspection.',
            'CMYK compliance is not guaranteed by the current mPDF/GD pipeline.',
        ];

        $result['output_fingerprint'] = $this->outputFingerprint($layout, $result);

        return $result;
    }

    public function outputFingerprint(ProductionPrintLayout $layout, array $validation): string
    {
        return $this->fingerprints->hash([
            'type' => 'phase4_layout_output',
            'layout_id' => $layout->id,
            'input_fingerprint' => $layout->input_fingerprint,
            'file_checksums' => collect($validation['files'] ?? [])->map(fn (array $file): ?string => $file['sha256'] ?? null)->all(),
            'page_map_version' => self::PAGE_MAP_VERSION,
            'renderer_version' => $validation['renderer_version'] ?? null,
            'layout_template_version' => $validation['layout_template_version'] ?? null,
        ]);
    }

    public function enrichManifest(ProductionPrintLayout $layout, array $manifest, array $validation): array
    {
        $layout->loadMissing(['project.order.story', 'project.scenes.approvedFinalImage', 'project.assets']);
        $settings = $layout->settings_json ?? [];
        $cover = $layout->project->assets->firstWhere('id', (int) data_get($settings, 'cover_asset_id'));

        return $manifest + [
            'project_id' => $layout->production_project_id,
            'order_id' => $layout->project->order_id,
            'order_number' => $layout->project->order?->order_number,
            'automation_run_id' => $layout->production_automation_run_id,
            'layout_id' => $layout->id,
            'layout_version' => $layout->version_number,
            'story_id' => $layout->project->order?->story_id,
            'cover_asset' => $cover ? $this->assetReference($cover) : null,
            'scene_assets' => $layout->project->scenes
                ->sortBy('scene_number')
                ->map(fn ($scene): array => [
                    'scene_number' => (int) $scene->scene_number,
                    'scene_id' => $scene->id,
                    'asset' => $scene->approvedFinalImage ? $this->assetReference($scene->approvedFinalImage) : null,
                    'text_hash' => hash('sha256', (string) data_get($settings, "scenes.{$scene->id}.text_content", $scene->story_text)),
                ])
                ->values()
                ->all(),
            'files' => $validation['files'] ?? [],
            'validation' => collect($validation)->except(['files'])->all(),
            'proof_status' => 'pending_human_review',
        ];
    }

    public function manifestCsv(array $manifest): string
    {
        $lines = [
            ['Section', 'Key', 'Value'],
            ['Summary', 'Project ID', data_get($manifest, 'project_id')],
            ['Summary', 'Order Number', data_get($manifest, 'order_number')],
            ['Summary', 'Automation Run ID', data_get($manifest, 'automation_run_id')],
            ['Summary', 'Layout Version', data_get($manifest, 'layout_version')],
            ['Summary', 'Reader Pages', data_get($manifest, 'page_count')],
            ['Summary', 'A3 Sheets', data_get($manifest, 'sheet_count')],
            ['Summary', 'Printed Sides / PDF Pages', data_get($manifest, 'printed_sides')],
            ['Summary', 'Binding Direction', data_get($manifest, 'binding_direction')],
            ['Summary', 'Duplex Flip', data_get($manifest, 'duplex_flip')],
            ['Summary', 'Page Map Version', data_get($manifest, 'page_map_version')],
            ['Summary', 'Renderer Version', data_get($manifest, 'validation.renderer_version')],
            ['Summary', 'Font Package', data_get($manifest, 'validation.font_package_version')],
            ['Summary', 'Proof Status', data_get($manifest, 'proof_status')],
        ];

        foreach (data_get($manifest, 'files', []) as $key => $file) {
            if ($key === 'manifest') {
                continue;
            }

            $lines[] = ['File', $key.' checksum', data_get($file, 'sha256')];
            $lines[] = ['File', $key.' size', data_get($file, 'bytes')];
            if (data_get($file, 'page_count') !== null) {
                $lines[] = ['File', $key.' pages', data_get($file, 'page_count')];
            }
        }

        foreach (data_get($manifest, 'scene_assets', []) as $scene) {
            $lines[] = ['Scene', 'Scene '.data_get($scene, 'scene_number'), 'asset '.data_get($scene, 'asset.id').' v'.data_get($scene, 'asset.version_number').' text '.data_get($scene, 'text_hash')];
        }

        $lines[] = ['Sheet', 'Side', 'Left Page | Right Page | Flip Direction'];
        foreach (data_get($manifest, 'sheets', []) as $sheet) {
            foreach (['front', 'back'] as $side) {
                $lines[] = [
                    'Sheet '.data_get($sheet, 'sheet_number'),
                    ucfirst($side),
                    data_get($sheet, "{$side}.left_page").' | '.data_get($sheet, "{$side}.right_page").' | '.data_get($sheet, 'flip_direction'),
                ];
            }
        }

        foreach (data_get($manifest, 'validation.warnings', []) as $warning) {
            $lines[] = ['Warning', 'Automated validation', $warning];
        }

        foreach (data_get($manifest, 'validation.known_non_automatable_checks', []) as $check) {
            $lines[] = ['Human Proof', 'Required', $check];
        }

        return "\xEF\xBB\xBF".collect($lines)
            ->map(fn (array $row): string => collect($row)->map(fn ($value): string => '"'.str_replace('"', '""', (string) $value).'"')->implode(','))
            ->implode("\n");
    }

    private function validateFile(string $key, ?string $path, string $type, ?int $expectedPages, string $expectedSize): array
    {
        $result = [
            'ok' => true,
            'path_key' => $key,
            'type' => $type,
            'expected_size' => $expectedSize,
            'errors' => [],
            'warnings' => [],
            'sha256' => null,
            'bytes' => null,
            'page_count' => null,
            'dimensions' => null,
            'font_embedded' => null,
        ];

        if (! is_string($path) || $path === '' || str_contains($path, '..') || ! Storage::disk('local')->exists($path)) {
            $result['ok'] = false;
            $result['errors'][] = "{$key} file is missing from private storage.";

            return $result;
        }

        $contents = Storage::disk('local')->get($path);
        $result['sha256'] = hash('sha256', $contents);
        $result['bytes'] = strlen($contents);

        if ($type !== 'pdf') {
            return $result;
        }

        if (! str_starts_with($contents, '%PDF')) {
            $result['ok'] = false;
            $result['errors'][] = "{$key} is not a readable PDF.";

            return $result;
        }

        preg_match_all('/\/Type\s*\/Page\b/', $contents, $pageMatches);
        $result['page_count'] = count($pageMatches[0]);
        if ($expectedPages !== null && $result['page_count'] !== $expectedPages) {
            $result['ok'] = false;
            $result['errors'][] = "{$key} has {$result['page_count']} pages; expected {$expectedPages}.";
        }

        preg_match('/\/MediaBox\s*\[\s*0\s+0\s+([0-9.]+)\s+([0-9.]+)\s*\]/', $contents, $mediaBox);
        if ($mediaBox) {
            $result['dimensions'] = [
                'width_points' => (float) $mediaBox[1],
                'height_points' => (float) $mediaBox[2],
            ];
            if (! $this->dimensionsMatch($key, (float) $mediaBox[1], (float) $mediaBox[2])) {
                $result['ok'] = false;
                $result['errors'][] = "{$key} page dimensions do not match {$expectedSize}.";
            }
        } else {
            $result['ok'] = false;
            $result['errors'][] = "{$key} MediaBox could not be read.";
        }

        $result['font_embedded'] = str_contains($contents, '/FontFile') || str_contains($contents, '/FontFile2') || str_contains($contents, '/FontFile3');
        if (! $result['font_embedded']) {
            $result['ok'] = false;
            $result['errors'][] = "{$key} does not expose an embedded font marker.";
        }

        return $result;
    }

    private function validateManifest(array $manifest): array
    {
        $errors = [];
        $expected = [
            'page_count' => ProductionLayoutBuilder::PAGE_COUNT,
            'scene_count' => ProductionLayoutBuilder::SCENE_COUNT,
            'sheet_count' => ProductionLayoutBuilder::SHEET_COUNT,
            'printed_sides' => ProductionLayoutBuilder::SHEET_COUNT * 2,
        ];

        foreach ($expected as $key => $value) {
            if ((int) data_get($manifest, $key) !== $value) {
                $errors[] = "Manifest {$key} is invalid.";
            }
        }

        $expectedSheets = $this->normalizeSheets(app(ProductionLayoutBuilder::class)->buildManifest([
            'binding_direction' => data_get($manifest, 'binding_direction', 'rtl'),
            'duplex_flip' => data_get($manifest, 'duplex_flip', 'short_edge'),
        ])['sheets']);

        if ($this->normalizeSheets(data_get($manifest, 'sheets', [])) !== $expectedSheets) {
            $errors[] = 'Manifest imposition map does not match the deterministic page map.';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'reader_pages' => ProductionLayoutBuilder::PAGE_COUNT,
            'a3_sheets' => ProductionLayoutBuilder::SHEET_COUNT,
            'imposed_pdf_pages' => ProductionLayoutBuilder::SHEET_COUNT * 2,
            'pdf_page_representation' => 'one PDF page per printed A3 side',
        ];
    }

    private function validateContent(ProductionPrintLayout $layout): array
    {
        $settings = $layout->settings_json ?? [];
        $errors = [];
        $requiresFingerprints = filled($layout->production_automation_run_id);
        $cover = $layout->project->assets->firstWhere('id', (int) data_get($settings, 'cover_asset_id'));

        if (! $cover || $cover->asset_type !== 'cover_image' || $cover->status !== 'approved' || ! $cover->is_final || ($requiresFingerprints && blank($cover->output_fingerprint))) {
            $errors[] = 'Approved fingerprinted cover asset is missing from the layout input.';
        }

        $seenSceneAssetIds = [];
        foreach ($layout->project->scenes->sortBy('scene_number') as $scene) {
            $asset = $scene->approvedFinalImage;
            if (! $asset || $asset->asset_type !== 'scene_image' || $asset->status !== 'approved' || ! $asset->is_final || ($requiresFingerprints && blank($asset->output_fingerprint))) {
                $errors[] = "Scene {$scene->scene_number} approved fingerprinted image is missing.";

                continue;
            }

            if (in_array($asset->id, $seenSceneAssetIds, true)) {
                $errors[] = "Scene {$scene->scene_number} duplicates an approved image asset.";
            }
            $seenSceneAssetIds[] = $asset->id;

            if (blank(data_get($settings, "scenes.{$scene->id}.text_content", $scene->story_text))) {
                $errors[] = "Scene {$scene->scene_number} text is missing.";
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'cover_asset_id' => $cover?->id,
            'scene_asset_ids' => $seenSceneAssetIds,
        ];
    }

    private function normalizeSheets(array $sheets): array
    {
        return collect($sheets)
            ->map(fn (array $sheet): array => [
                'sheet_number' => (int) data_get($sheet, 'sheet_number'),
                'front' => [
                    'left_page' => (int) data_get($sheet, 'front.left_page'),
                    'right_page' => (int) data_get($sheet, 'front.right_page'),
                ],
                'back' => [
                    'left_page' => (int) data_get($sheet, 'back.left_page'),
                    'right_page' => (int) data_get($sheet, 'back.right_page'),
                ],
                'flip_direction' => (string) data_get($sheet, 'flip_direction'),
            ])
            ->values()
            ->all();
    }

    private function validateImageResolution(ProductionPrintLayout $layout): array
    {
        $warnings = [];
        $errors = [];
        $policy = config('production_studio.automation.phase4.dpi_policy', 'warn');
        $minDpi = (int) config('production_studio.automation.phase4.min_effective_dpi', 180);
        $assets = $layout->project->assets
            ->filter(fn (ProductionProjectAsset $asset): bool => in_array($asset->asset_type, ['cover_image', 'scene_image'], true) && $asset->is_final && $asset->status === 'approved')
            ->values();

        foreach ($assets as $asset) {
            if (! is_string($asset->file_path) || str_contains($asset->file_path, '..') || ! Storage::disk('local')->exists($asset->file_path)) {
                $errors[] = "Asset {$asset->id} is not readable from private storage.";

                continue;
            }

            $size = @getimagesize(Storage::disk('local')->path($asset->file_path));
            if (! $size) {
                $errors[] = "Asset {$asset->id} image dimensions could not be read.";

                continue;
            }

            $targetWidthInches = $asset->asset_type === 'cover_image' ? 8.27 : 16.54;
            $effectiveDpi = (int) floor($size[0] / $targetWidthInches);
            if ($effectiveDpi < $minDpi) {
                $message = "Asset {$asset->id} effective DPI is {$effectiveDpi}; target minimum is {$minDpi}.";
                if ($policy === 'fail') {
                    $errors[] = $message;
                } else {
                    $warnings[] = $message;
                }
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'minimum_effective_dpi' => $minDpi,
            'policy' => $policy,
            'color_space_note' => 'CMYK conversion is not guaranteed by the current mPDF/GD pipeline.',
        ];
    }

    private function dimensionsMatch(string $key, float $width, float $height): bool
    {
        if ($key === 'reader_pdf' || $key === 'proof_checklist') {
            return abs($width - 595.28) < 2 && abs($height - 841.89) < 2;
        }

        if ($key === 'print_pdf') {
            return abs($width - 1190.55) < 3 && abs($height - 841.89) < 2;
        }

        return true;
    }

    private function assetReference(ProductionProjectAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'version_number' => $asset->version_number,
            'asset_type' => $asset->asset_type,
            'output_fingerprint' => $asset->output_fingerprint,
            'sha256' => is_string($asset->file_path) && ! str_contains($asset->file_path, '..') && Storage::disk('local')->exists($asset->file_path)
                ? hash('sha256', Storage::disk('local')->get($asset->file_path))
                : null,
        ];
    }

    private function rendererVersion(): string
    {
        if (class_exists(InstalledVersions::class)) {
            return 'mpdf/'.(InstalledVersions::getPrettyVersion('mpdf/mpdf') ?: 'unknown');
        }

        return self::RENDERER_VERSION;
    }
}
