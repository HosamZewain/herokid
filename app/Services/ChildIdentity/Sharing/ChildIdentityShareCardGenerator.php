<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityShare;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class ChildIdentityShareCardGenerator
{
    private const VARIANTS = [
        'feed' => ['pixels' => [1080, 1350], 'mm' => [108, 135], 'image_mm' => [91, 77]],
        'story' => ['pixels' => [1080, 1920], 'mm' => [108, 192], 'image_mm' => [91, 105]],
        'og' => ['pixels' => [1200, 630], 'mm' => [120, 63], 'image_mm' => [69, 51]],
    ];

    public function __construct(
        private readonly ChildIdentityShareSettings $settings,
        private readonly ChildIdentityShareText $text,
    ) {}

    public function generate(ChildIdentityShare $share, int $generationVersion): array
    {
        $share->loadMissing(['identityRequest', 'generationAttempt']);
        $attempt = $share->generationAttempt;

        if (! $attempt || $attempt->status !== 'succeeded' || blank($attempt->output_storage_path)) {
            throw new \RuntimeException('The approved identity output is unavailable.');
        }

        $identityContents = Storage::disk($attempt->output_disk ?: 'local')->get($attempt->output_storage_path);
        $logoContents = file_get_contents(public_path('images/logo-320.png'));

        if ($logoContents === false) {
            throw new \RuntimeException('The official HeroKid logo is unavailable.');
        }

        $publicUrl = route('child-identity-shares.show', $share->public_token);
        $paths = [];

        foreach (self::VARIANTS as $variant => $definition) {
            $path = "child-identity-shares/{$share->id}/v{$generationVersion}/{$variant}.jpg";
            $jpeg = $this->render(
                $share,
                $variant,
                $definition,
                $identityContents,
                $logoContents,
                $publicUrl,
            );
            Storage::disk($share->card_disk)->put($path, $jpeg);
            $paths[$variant] = $path;
        }

        return $paths;
    }

    private function render(
        ChildIdentityShare $share,
        string $variant,
        array $definition,
        string $identityContents,
        string $logoContents,
        string $publicUrl,
    ): string {
        [$widthPx, $heightPx] = $definition['pixels'];
        [$imageWidthMm, $imageHeightMm] = $definition['image_mm'];
        $identityWidth = (int) ($imageWidthMm * 10);
        $identityHeight = (int) ($imageHeightMm * 10);
        $identity = $this->normalizedImage($identityContents, $identityWidth, $identityHeight);
        $logo = $this->normalizedLogo($logoContents, $variant === 'og' ? 210 : 230, $variant === 'story' ? 210 : 170);
        $qr = $this->settings->qrEnabled() ? $this->qrImage($publicUrl, $variant === 'og' ? 150 : 180) : null;
        $firstName = $share->display_child_first_name
            ? $this->text->firstName($share->identityRequest?->child_name)
            : '';
        $headline = htmlspecialchars(str_replace('✨', '', $this->settings->cardHeadline()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cta = htmlspecialchars($this->settings->cardCta(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nameLine = $firstName !== ''
            ? '<div style="margin-top:8px;color:#db2777;font-size:24px">هوية '.htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div>'
            : '';
        $canvas = new \Imagick;
        $canvas->newImage($widthPx, $heightPx, new \ImagickPixel('#f8f7ff'), 'jpeg');
        $this->drawAccent($canvas, $widthPx, $heightPx);

        if ($variant === 'og') {
            $this->roundedPanel($canvas, 735, 20, 445, 590, '#4f46e5', 38);
            $this->framedIdentity($canvas, $identity, 35, 60, $identityWidth, $identityHeight);
            $canvas->compositeImage($logo, \Imagick::COMPOSITE_OVER, 850, 38);
            $headlineBlock = $this->textBlock(
                "<div>{$headline}{$nameLine}</div>",
                390,
                170,
                30,
                '#ffffff',
                '#4f46e5',
            );
            $canvas->compositeImage($headlineBlock, \Imagick::COMPOSITE_OVER, 760, 205);
            $this->roundedPanel($canvas, 765, 410, 375, 155, '#db2777', 28);
            $ctaBlock = $this->textBlock(
                "<div>{$cta}</div><div style=\"font-size:18px;margin-top:4px;direction:ltr\">hero-kid.com</div>",
                $qr ? 225 : 340,
                125,
                25,
                '#ffffff',
                '#db2777',
            );
            $canvas->compositeImage($ctaBlock, \Imagick::COMPOSITE_OVER, $qr ? 900 : 782, 425);
            if ($qr) {
                $canvas->compositeImage($qr, \Imagick::COMPOSITE_OVER, 785, 412);
            }
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
                $qr ? 665 : 900,
                $footerHeight - 90,
                $isStory ? 39 : 34,
                '#ffffff',
                '#4f46e5',
            );
            $canvas->compositeImage($ctaBlock, \Imagick::COMPOSITE_OVER, $qr ? 315 : 90, $footerY + 22);
            if ($qr) {
                $canvas->compositeImage(
                    $qr,
                    \Imagick::COMPOSITE_OVER,
                    90,
                    (int) ($footerY + (($footerHeight - 45 - $qr->getImageHeight()) / 2)),
                );
            }
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
        $qr?->clear();
        $headlineBlock->clear();
        $ctaBlock->clear();

        return $contents;
    }

    private function normalizedImage(string $contents, int $width, int $height): \Imagick
    {
        $image = new \Imagick;
        $image->readImageBlob($contents);
        $image->setIteratorIndex(0);
        $image->autoOrient();
        $image->setImageBackgroundColor('#ffffff');
        $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $image->cropThumbnailImage($width, $height);
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(92);
        $image->stripImage();

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

    private function qrImage(string $url, int $size): \Imagick
    {
        $qrCode = new QrCode(
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 260,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(49, 46, 129),
            backgroundColor: new Color(255, 255, 255),
        );
        $qr = new \Imagick;
        $qr->readImageBlob((new PngWriter)->write($qrCode)->getString());
        $qr->thumbnailImage($size, $size, true, true);

        return $qr;
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
        $canvas->drawImage($draw);
        $draw->clear();
    }
}
