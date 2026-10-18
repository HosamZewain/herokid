<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Support\SiteBranding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class SiteLogoController extends Controller
{
    public function __invoke(Request $request, int $width): Response
    {
        abort_unless($width >= 1 && $width <= 768, 404);
        $source = SiteBranding::source($width);
        $etag = hash('sha256', ($source['disk'] ?? 'bundled').'|'.$source['path']);
        $headers = [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=0, s-maxage=0, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $conditional = response('', 200, $headers)->setEtag($etag);
        if ($conditional->isNotModified($request)) {
            return $conditional;
        }
        if ($source['disk'] === null) {
            return response()->file(public_path($source['path']), $headers)->setEtag($etag);
        }

        // Stream the configured public disk via our own origin, including S3.
        // Stable URLs update even in previously cached HTML and work in canvas exports.
        return Storage::disk($source['disk'])->response($source['path'], null, $headers, 'inline')->setEtag($etag);
    }
}
