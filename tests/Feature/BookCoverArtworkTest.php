<?php

namespace Tests\Feature;

use App\Support\BookArtwork;
use Tests\TestCase;

class BookCoverArtworkTest extends TestCase
{
    private function source(): string
    {
        $im = imagecreatetruecolor(1024, 1536);
        imagefill($im, 0, 0, imagecolorallocate($im, 32, 149, 171));
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    public function test_transparent_logo_has_no_rectangle_and_cover_is_native_a4_without_sidebars(): void
    {
        $result = BookArtwork::a4($this->source(), true, 'a4-cover-v2');
        $this->assertSame([1024, 1448], [$result['width'], $result['height']]);
        $this->assertSame([0, 44], $result['crop_origin']);
        $this->assertFalse($result['resampled']);
        $this->assertLessThan(300, $result['effective_ppi']);
        $im = imagecreatefromstring($result['bytes']);
        $background = (32 << 16) | (149 << 8) | 171;
        foreach ([[0, 0], [1023, 1447], [512, 600], [400, 1295]] as [$x, $y]) {
            $this->assertSame($background, imagecolorat($im, $x, $y) & 0xFFFFFF);
        }
        $changed = 0;
        for ($y = 1295; $y < 1383; $y++) {
            for ($x = 399; $x < 625; $x++) {
                if ((imagecolorat($im, $x, $y) & 0xFFFFFF) !== $background) {
                    $changed++;
                }
            }
        }
        $this->assertGreaterThan(1000, $changed);
        $this->assertLessThan(18000, $changed); // Alpha leaves the scene visible, not a filled rectangle.
        imagedestroy($im);
    }

    public function test_existing_contract_and_interiors_keep_their_original_padding(): void
    {
        foreach ([false, true] as $cover) {
            $result = BookArtwork::a4($this->source(), $cover);
            $this->assertSame([1087, 1536], [$result['width'], $result['height']]);
            $this->assertSame('paper_padding', $result['fit']);
        }
    }
}
