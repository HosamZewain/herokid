<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class ChildIdentityShareCardGenerator
{
    private const VARIANTS = [
        'feed' => ['pixels' => [1200, 900], 'mm' => [120, 90], 'image_mm' => [108, 66]],
        'story' => ['pixels' => [1080, 1920], 'mm' => [108, 192], 'image_mm' => [91, 105]],
        'og' => ['pixels' => [1200, 630], 'mm' => [120, 63], 'image_mm' => [80, 53]],
    ];

    public function __construct(
        private readonly ChildIdentityShareSettings $settings,
        private readonly ChildIdentityShareText $text,
    ) {}

    public function generate(ChildIdentityShare $share, int $generationVersion): array
    {
        $share->loadMissing(['identityRequest', 'generationAttempt']);

        return $this->generateCards(
            $share->generationAttempt,
            $share->identityRequest,
            $share->display_child_first_name,
            "child-identity-shares/{$share->id}/v{$generationVersion}",
        );
    }

    public function generateDraft(ChildIdentityGenerationAttempt $attempt): array
    {
        $attempt->loadMissing('identityRequest');
        $identity = $attempt->identityRequest;

        return $this->generateCards(
            $attempt,
            $identity,
            false,
            "child-identities/{$identity->uuid}/attempts/{$attempt->attempt_number}/share-cards",
        );
    }

    private function generateCards(
        ?ChildIdentityGenerationAttempt $attempt,
        ?ChildIdentityRequest $identity,
        bool $displayFirstName,
        string $directory,
    ): array {
        if (! $attempt || ! $identity || $attempt->status !== 'succeeded' || blank($attempt->output_storage_path)) {
            throw new \RuntimeException('The approved identity output is unavailable.');
        }

        $identityContents = Storage::disk($attempt->output_disk ?: 'local')->get($attempt->output_storage_path);
        $logoContents = file_get_contents(public_path('images/logo-320.png'));
        $globeContents = file_get_contents(public_path('images/icons/globe-alt-indigo.svg'));

        if ($logoContents === false || $globeContents === false) {
            throw new \RuntimeException('A required HeroKid sharing-card asset is unavailable.');
        }

        $paths = [];

        foreach (self::VARIANTS as $variant => $definition) {
            $path = "{$directory}/{$variant}.jpg";
            $jpeg = $this->render(
                $identity,
                $displayFirstName,
                $variant,
                $definition,
                $identityContents,
                $logoContents,
                $globeContents,
            );
            Storage::disk('local')->put($path, $jpeg);
            $paths[$variant] = $path;
        }

        return $paths;
    }

    private function render(
        ChildIdentityRequest $identityRequest,
        bool $displayFirstName,
        string $variant,
        array $definition,
        string $identityContents,
        string $logoContents,
        string $globeContents,
    ): string {
        [$widthPx, $heightPx] = $definition['pixels'];
        [$imageWidthMm, $imageHeightMm] = $definition['image_mm'];
        $identityWidth = (int) ($imageWidthMm * 10);
        $identityHeight = (int) ($imageHeightMm * 10);
        $identity = $this->normalizedImage($identityContents, $identityWidth, $identityHeight);
        $logo = $this->normalizedLogo(
            $logoContents,
            $variant === 'feed' ? 150 : 230,
            $variant === 'feed' ? 110 : 210,
        );
        $globeDataUri = 'data:image/svg+xml;base64,'.base64_encode($globeContents);
        $firstName = $displayFirstName
            ? $this->text->firstName($identityRequest->child_name)
            : '';
        $headline = htmlspecialchars(str_replace('✨', '', $this->settings->cardHeadline()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cta = htmlspecialchars($this->settings->cardCta(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $footer = htmlspecialchars($this->settings->cardFooter(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nameLine = $firstName !== ''
            ? '<div style="margin-top:8px;color:#db2777;font-size:24px">هوية '.htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div>'
            : '';
        $referenceName = $firstName !== ''
            ? ' • هوية '.htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : '';
        $canvas = new \Imagick;
        $canvas->newImage($widthPx, $heightPx, new \ImagickPixel('#f8f7ff'), 'jpeg');
        $this->drawAccent($canvas, $widthPx, $heightPx);

        if ($variant === 'feed') {
            $canvas->compositeImage($logo, \Imagick::COMPOSITE_OVER, 1035, 18);
            $headlineBlock = $this->textBlock(
                "<div>{$headline}</div>",
                870,
                68,
                38,
                '#312e81',
                '#f8f7ff',
            );
            $canvas->compositeImage($headlineBlock, \Imagick::COMPOSITE_OVER, 140, 18);
            $ctaBlock = $this->textBlock(
                "<div>{$cta}{$referenceName}</div>",
                720,
                48,
                26,
                '#2aa8b2',
                '#f8f7ff',
            );
            $canvas->compositeImage($ctaBlock, \Imagick::COMPOSITE_OVER, 240, 83);
            $this->framedIdentity($canvas, $identity, 60, 165, $identityWidth, $identityHeight);
            $footerBlock = $this->textBlock(
                "<div>{$footer}</div>",
                540,
                62,
                23,
                '#4f46e5',
                '#f8f7ff',
            );
            $canvas->compositeImage($footerBlock, \Imagick::COMPOSITE_OVER, 610, 838);
            $siteBlock = $this->textBlock(
                '<div style="direction:ltr">hero-kid.com&nbsp; <img src="'.$globeDataUri.'" style="width:24px;height:24px;vertical-align:middle"></div>',
                390,
                62,
                22,
                '#4f46e5',
                '#f8f7ff',
            );
            $canvas->compositeImage($siteBlock, \Imagick::COMPOSITE_OVER, 80, 838);
            $this->verticalDivider($canvas, 565, 848, 890);
        } elseif ($variant === 'og') {
            $this->framedIdentity($canvas, $identity, 30, 50, $identityWidth, $identityHeight);
            $this->verticalDivider($canvas, 860, 40, 590);
            $canvas->compositeImage($logo, \Imagick::COMPOSITE_OVER, 940, 22);
            $headlineBlock = $this->textBlock(
                "<div>{$headline}</div>",
                330,
                130,
                31,
                '#312e81',
                '#f8f7ff',
            );
            $canvas->compositeImage($headlineBlock, \Imagick::COMPOSITE_OVER, 850, 175);
            $ctaBlock = $this->textBlock(
                "<div>{$cta}{$referenceName}</div>",
                300,
                78,
                22,
                '#2aa8b2',
                '#f8f7ff',
            );
            $canvas->compositeImage($ctaBlock, \Imagick::COMPOSITE_OVER, 865, 310);
            $footerBlock = $this->textBlock(
                "<div>{$footer}</div>",
                330,
                72,
                20,
                '#4f46e5',
                '#f8f7ff',
            );
            $canvas->compositeImage($footerBlock, \Imagick::COMPOSITE_OVER, 850, 410);
            $siteBlock = $this->textBlock(
                '<div style="direction:ltr">hero-kid.com&nbsp; <img src="'.$globeDataUri.'" style="width:22px;height:22px;vertical-align:middle"></div>',
                290,
                70,
                20,
                '#4f46e5',
                '#f8f7ff',
            );
            $canvas->compositeImage($siteBlock, \Imagick::COMPOSITE_OVER, 870, 505);
        } else {
            $isStory = $variant === 'story';
            $headerHeight = $isStory ? 285 : 210;
            $identityY = $isStory ? 285 : 220;
            $footerY = $isStory ? 1390 : 1030;
            $footerHeight = $isStory ? 430 : 270;
            $logoX = 800;
            $logoY = $isStory ? 35 : 20;
            $canvas->compositeImage($logo, \Imagick::COMPOSITE_OVER, $logoX, $logoY);
            $headlineBlock = $this->textBlock(
                "<div>{$headline}{$nameLine}</div>",
                690,
                $headerHeight - 35,
                $isStory ? 39 : 34,
                '#312e81',
                '#f8f7ff',
            );
            $canvas->compositeImage($headlineBlock, \Imagick::COMPOSITE_OVER, 65, 18);
            $this->framedIdentity($canvas, $identity, 85, $identityY, $identityWidth, $identityHeight);
            $this->roundedPanel($canvas, 50, $footerY, 980, $footerHeight - 45, '#4f46e5', 42);
            $ctaBlock = $this->textBlock(
                "<div>{$cta}</div><div style=\"font-size:24px;margin-top:6px;direction:ltr\">hero-kid.com</div>",
                900,
                $footerHeight - 90,
                $isStory ? 39 : 34,
                '#ffffff',
                '#4f46e5',
            );
            $canvas->compositeImage($ctaBlock, \Imagick::COMPOSITE_OVER, 90, $footerY + 22);
            $brandBlock = $this->textBlock(
                '<div style="direction:ltr">HeroKid • hero-kid.com</div>',
                600,
                40,
                17,
                '#64748b',
                '#f8f7ff',
            );
            $canvas->compositeImage($brandBlock, \Imagick::COMPOSITE_OVER, 240, $heightPx - 43);
            $brandBlock->clear();
        }

        $canvas->setImageFormat('jpeg');
        $canvas->setImageCompression(\Imagick::COMPRESSION_JPEG);
        $canvas->setImageCompressionQuality($this->settings->quality($variant));
        $canvas->stripImage();
        $contents = $canvas->getImagesBlob();
        $canvas->clear();
        $identity->clear();
        $logo->clear();
        $headlineBlock->clear();
        $ctaBlock->clear();
        if (isset($footerBlock)) {
            $footerBlock->clear();
        }
        if (isset($siteBlock)) {
            $siteBlock->clear();
        }

        return $contents;
    }

    private function normalizedImage(string $contents, int $width, int $height): \Imagick
    {
        $source = new \Imagick;
        $source->readImageBlob($contents);
        $source->setIteratorIndex(0);
        $source->autoOrient();
        $source->setImageBackgroundColor('#ffffff');
        $source = $source->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $source->thumbnailImage($width, $height, true, true);

        $image = new \Imagick;
        $image->newImage($width, $height, new \ImagickPixel('#ffffff'), 'jpeg');
        $image->compositeImage(
            $source,
            \Imagick::COMPOSITE_OVER,
            (int) (($width - $source->getImageWidth()) / 2),
            (int) (($height - $source->getImageHeight()) / 2),
        );
        $image->setImageCompressionQuality(92);
        $image->stripImage();
        $source->clear();

        return $image;
    }

    private function normalizedLogo(string $contents, int $width, int $height): \Imagick
    {
        $logo = new \Imagick;
        $logo->readImageBlob($contents);
        $logo->setIteratorIndex(0);
        $logo->setImageBackgroundColor('transparent');
        $logo = $logo->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $logo->thumbnailImage($width, $height, true, true);

        return $logo;
    }

    private function textBlock(
        string $html,
        int $width,
        int $height,
        int $fontSize,
        string $color,
        string $background,
    ): \Imagick {
        $widthMm = $width / 10;
        $heightMm = $height / 10;
        $tempDir = storage_path('app/mpdf-child-identity-share');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => [$widthMm, $heightMm],
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'default_font' => 'dejavusans',
            'tempDir' => $tempDir,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->WriteHTML(<<<HTML
<html dir="rtl"><head><style>
@page { margin:0; }
body { margin:0; padding:0; background:{$background}; color:{$color}; font-family:dejavusans; }
table { width:100%; height:{$heightMm}mm; border-collapse:collapse; table-layout:fixed; }
td { padding:0 3mm; text-align:center; vertical-align:middle; font-size:{$fontSize}px; line-height:1.45; font-weight:bold; }
</style></head><body><table><tr><td>{$html}</td></tr></table></body></html>
HTML);
        $pdf = $mpdf->Output('', Destination::STRING_RETURN);
        $image = new \Imagick;
        $image->setResolution(254, 254);
        $image->readImageBlob($pdf);
        $image->setIteratorIndex(0);
        $image->setImageBackgroundColor($background);
        $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $image->setImageFormat('png');
        $image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, false);

        return $image;
    }

    private function framedIdentity(
        \Imagick $canvas,
        \Imagick $identity,
        int $x,
        int $y,
        int $width,
        int $height,
    ): void {
        $this->roundedPanel($canvas, $x - 10, $y - 10, $width + 20, $height + 20, '#ddd6fe', 34);
        // Composite the normalized output directly. Alpha-mask composition behaves
        // differently across the ImageMagick 6 builds used locally and in production
        // and can turn the entire child image transparent.
        $canvas->compositeImage($identity, \Imagick::COMPOSITE_OVER, $x, $y);
    }

    private function roundedPanel(
        \Imagick $canvas,
        int $x,
        int $y,
        int $width,
        int $height,
        string $color,
        int $radius,
    ): void {
        $draw = new \ImagickDraw;
        $draw->setFillColor($color);
        $draw->roundRectangle($x, $y, $x + $width, $y + $height, $radius, $radius);
        $canvas->drawImage($draw);
        $draw->clear();
    }

    private function drawAccent(\Imagick $canvas, int $width, int $height): void
    {
        $draw = new \ImagickDraw;
        $draw->setFillColor('#ede9fe');
        $draw->circle(10, 10, 120, 120);
        $draw->setFillColor('#fce7f3');
        $draw->circle($width - 5, $height - 5, $width - 130, $height - 130);
        $draw->setFillColor('#ccfbf1');
        $draw->circle(0, $height, 105, $height - 105);
        $canvas->drawImage($draw);
        $draw->clear();
    }

    private function verticalDivider(\Imagick $canvas, int $x, int $startY, int $endY): void
    {
        $draw = new \ImagickDraw;
        $draw->setStrokeColor('#a78bfa');
        $draw->setStrokeWidth(2);
        $draw->line($x, $startY, $x, $endY);
        $canvas->drawImage($draw);
        $draw->clear();
    }
}
