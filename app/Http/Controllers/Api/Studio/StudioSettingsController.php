<?php

namespace App\Http\Controllers\Api\Studio;

use App\Http\Controllers\Controller;
use App\Services\RemoteStudio\StudioSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudioSettingsController extends Controller
{
    public function show(Request $request, StudioSettingsService $settings): mixed
    {
        $revision = $settings->current();
        abort_unless($revision, 503, 'Studio settings have not been published.');
        $response = response()->json(['data' => $settings->snapshot($revision)])
            ->header('Cache-Control', 'private, no-cache')->header('Vary', 'Authorization')->setEtag('studio-'.$revision->id.'-'.$revision->checksum);
        $response->isNotModified($request);

        return $response;
    }

    public function asset(string $assetId, StudioSettingsService $settings): mixed
    {
        $asset = $settings->publishedAsset($assetId);

        return Storage::disk('studio_private')->download($asset->path, $asset->id.'.png', [
            'Content-Type' => $asset->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'X-Checksum-SHA256' => $asset->checksum,
        ]);
    }
}
