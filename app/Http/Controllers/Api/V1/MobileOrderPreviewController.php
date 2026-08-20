<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BookletPreview;
use App\Models\Order;
use App\Services\BookletPreviews\CustomerPreviewDecisionService;
use App\Services\Mobile\MobileAnalyticsRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MobileOrderPreviewController extends Controller
{
    public function show(Request $request, Order $order, MobileAnalyticsRecorder $analytics): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $preview = $this->preview($order);
        $version = $preview->currentVersion;
        $decision = $version->decision;
        $analytics->record($request, 'preview_viewed', ['order_id' => $order->id, 'preview_version_id' => $version->id]);

        return response()->json(['data' => [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'preview_id' => $preview->uuid,
            'version' => [
                'id' => $version->id,
                'number' => $version->version_number,
                'page_count' => $version->page_count,
                'file_size' => $version->file_size,
                'checksum' => $version->checksum,
                'created_at' => $version->created_at?->toISOString(),
                'document_url' => url('/api/v1/orders/'.$order->id.'/preview/document'),
            ],
            'decision' => $decision ? [
                'type' => $decision->decision,
                'page_number' => $decision->page_number,
                'comments' => $decision->comments,
                'decided_at' => $decision->decided_at?->toISOString(),
            ] : null,
            'can_decide' => $order->status === 'preview_uploaded' && ! $decision,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function document(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);
        $version = $this->preview($order)->currentVersion;
        abort_unless(Storage::disk($version->disk)->exists($version->file_path), 404);

        return response()->file(Storage::disk($version->disk)->path($version->file_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="herokid-preview-v'.$version->version_number.'.pdf"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    public function approve(Request $request, Order $order, CustomerPreviewDecisionService $decisions, MobileAnalyticsRecorder $analytics): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $request->validate(['preview_version_id' => ['required', 'integer']]);
        $this->assertCurrentVersion($order, $request->integer('preview_version_id'));
        $decision = $decisions->approve($order, $request->user(), $request);
        $analytics->record($request, 'preview_approved', ['order_id' => $order->id, 'preview_version_id' => $decision->booklet_preview_version_id]);

        return response()->json(['data' => ['decision' => $decision->decision, 'status' => 'approved_for_printing', 'version_id' => $decision->booklet_preview_version_id, 'decided_at' => $decision->decided_at?->toISOString()]])->header('Cache-Control', 'private, no-store');
    }

    public function requestRevision(Request $request, Order $order, CustomerPreviewDecisionService $decisions, MobileAnalyticsRecorder $analytics): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validate([
            'preview_version_id' => ['required', 'integer'],
            'page_number' => ['required', 'integer', 'min:1'],
            'comments' => ['required', 'string', 'min:3', 'max:4000'],
        ]);
        $this->assertCurrentVersion($order, (int) $data['preview_version_id']);
        $decision = $decisions->requestRevision($order, $request->user(), $request, (int) $data['page_number'], $data['comments']);
        $analytics->record($request, 'revision_requested', ['order_id' => $order->id, 'preview_version_id' => $decision->booklet_preview_version_id, 'page_number' => $decision->page_number]);

        return response()->json(['data' => ['decision' => $decision->decision, 'status' => 'revision_requested', 'version_id' => $decision->booklet_preview_version_id, 'page_number' => $decision->page_number, 'decided_at' => $decision->decided_at?->toISOString()]])->header('Cache-Control', 'private, no-store');
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless($order->user_id === $request->user()->id, 404);
    }

    private function preview(Order $order): BookletPreview
    {
        $preview = BookletPreview::query()
            ->where('order_id', $order->id)
            ->where('status', 'active')
            ->with(['currentVersion.decision'])
            ->first();
        abort_unless($preview?->currentVersion, 404);

        return $preview;
    }

    private function assertCurrentVersion(Order $order, int $versionId): void
    {
        abort_unless((int) $this->preview($order)->current_version_id === $versionId, 409, 'A newer preview version is available. Refresh before deciding.');
    }
}
