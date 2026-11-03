<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\OrderIdentityRegeneration;
use App\Services\Orders\OrderIdentityRegenerationService;
use Illuminate\Support\Facades\Storage;

class OrderRegeneratedIdentityPreviewController extends Controller
{
    public function __invoke(OrderIdentityRegeneration $regeneration, OrderIdentityRegenerationService $service)
    {
        abort_unless($service->previewReady($regeneration), 404);
        $attempt = $regeneration->attempt;
        $bytes = Storage::disk($attempt->output_disk)->get($attempt->output_storage_path);
        abort_unless(hash_equals($attempt->output_checksum, hash('sha256', $bytes)), 404);

        return response($bytes, 200, ['Content-Type' => getimagesizefromstring($bytes)['mime'], 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
