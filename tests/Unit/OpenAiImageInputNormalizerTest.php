<?php

namespace Tests\Unit;

use App\Services\Ai\OpenAiImageInputNormalizer;
use RuntimeException;
use Tests\TestCase;

class OpenAiImageInputNormalizerTest extends TestCase
{
    public function test_it_converts_palette_png_to_a_truecolor_png_for_openai(): void
    {
        $source = imagecreate(18, 12);
        $background = imagecolorallocate($source, 22, 88, 160);
        imagefilledrectangle($source, 0, 0, 18, 12, $background);

        ob_start();
        imagepng($source);
        $contents = (string) ob_get_clean();
        imagedestroy($source);

        $result = app(OpenAiImageInputNormalizer::class)->normalizeDataUri(
            'data:image/png;base64,'.base64_encode($contents)
        );

        $this->assertSame('image/png', $result['mime']);
        $this->assertSame('png', $result['extension']);
        $this->assertSame(18, $result['width']);
        $this->assertSame(12, $result['height']);
        $this->assertSame('image/png', getimagesizefromstring($result['contents'])['mime']);

        $normalized = imagecreatefromstring($result['contents']);
        $this->assertTrue(imageistruecolor($normalized));
        imagedestroy($normalized);
    }

    public function test_it_rejects_non_image_bytes_before_calling_openai(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('الصورة المرجعية غير صالحة');

        app(OpenAiImageInputNormalizer::class)->normalizeDataUri(
            'data:image/png;base64,'.base64_encode('not-an-image')
        );
    }
}
