<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPreview;
use App\Services\Uploads\OrderPhotoUploadService;
use App\Support\AdminActivityLogger;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['user', 'story', 'items.product', 'items.variant'])->latest();

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->paginate(15)->withQueryString();

        return view('admin.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        $order->load([
            'user',
            'story',
            'statusLogs',
            'previews',
            'items.product',
            'items.variant',
            'items.linkedAddOns.product',
            'productionPromptOverride.editor',
            'productionPromptSnapshots.creator',
            'productionProject.assignedTo',
        ]);
        $storyProductionPrompt = null;
        $globalStoryProductionPrompt = null;
        $productionPromptTemplateSetting = null;

        if (auth()->user()->hasPermission('orders.production_prompt.manage')) {
            $storyProductionPrompt = StoryProductionPrompt::forOrder($order);
            $globalStoryProductionPrompt = StoryProductionPrompt::forOrder($order, useOverride: false);
            $productionPromptTemplateSetting = StoryProductionPrompt::templateSetting();
        }

        AdminActivityLogger::log(
            action: 'order.viewed',
            description: 'عرض تفاصيل الطلب: '.$order->order_number,
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'story_title' => $order->story?->title,
            ],
            request: request(),
        );

        return view('admin.orders.show', compact('order', 'storyProductionPrompt', 'globalStoryProductionPrompt', 'productionPromptTemplateSetting'));
    }

    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:new,under_review,generating,preview_uploaded,approved_for_print,printing,shipped,delivered,cancelled',
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $oldStatus = $order->status;
        $oldNotes = $order->notes;
        $statusChanged = $oldStatus !== $validated['status'];

        $order->update([
            'status' => $validated['status'],
            'notes' => $validated['admin_notes'] ?? $order->notes,
        ]);

        if ($statusChanged) {
            $order->statusLogs()->create([
                'status' => $validated['status'],
                'notes' => $request->admin_notes ?? 'تم تحديث الحالة من لوحة الإدارة.',
            ]);

            if (in_array($validated['status'], ['generating', 'approved_for_print', 'printing'], true) && ! $order->productionPromptSnapshots()->exists()) {
                $order->productionPromptSnapshots()->create([
                    'prompt_text' => StoryProductionPrompt::forOrder($order->fresh(['story', 'productionPromptOverride'])),
                    'template_updated_at' => StoryProductionPrompt::templateUpdatedAt(),
                    'snapshot_reason' => 'status:'.$validated['status'],
                    'created_by' => auth()->id(),
                ]);
            }
        }

        AdminActivityLogger::log(
            action: $statusChanged ? 'order.status_updated' : 'order.updated',
            description: 'تحديث الطلب: '.$order->order_number,
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'status' => [
                    'old' => $oldStatus,
                    'new' => $order->status,
                    'changed' => $statusChanged,
                ],
                'notes_changed' => $oldNotes !== $order->notes,
                'admin_notes' => $validated['admin_notes'] ?? null,
            ],
            request: $request,
        );

        return redirect()->route('admin.orders.show', $order)->with('success', 'تم تحديث الطلب بنجاح!');
    }

    /**
     * Upload a preview file for the order and notify customer.
     */
    public function uploadPreview(Request $request, Order $order)
    {
        $request->validate([
            'preview_file' => 'required|file|mimes:jpeg,png,jpg,pdf|max:10240',
            'preview_note' => 'nullable|string|max:1000',
        ]);

        $oldStatus = $order->status;
        $path = $request->file('preview_file')->store('orders/previews/'.$order->id, 'local');

        $preview = OrderPreview::create([
            'order_id' => $order->id,
            'file_path' => $path,
            'note' => $request->preview_note,
            'uploaded_by' => auth()->id(),
        ]);

        // Update order status to preview_uploaded
        $order->update(['status' => 'preview_uploaded']);
        $order->statusLogs()->create([
            'status' => 'preview_uploaded',
            'notes' => 'تم رفع التصميم الأولي وإرساله للعميل للموافقة.',
        ]);

        AdminActivityLogger::log(
            action: 'order.preview_uploaded',
            description: 'رفع تصميم معاينة للطلب: '.$order->order_number,
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'preview_id' => $preview->id,
                'preview_file' => [
                    'path' => $path,
                    'original_name' => $request->file('preview_file')?->getClientOriginalName(),
                    'mime_type' => $request->file('preview_file')?->getClientMimeType(),
                    'size' => $request->file('preview_file')?->getSize(),
                ],
                'status' => [
                    'old' => $oldStatus,
                    'new' => 'preview_uploaded',
                ],
            ],
            request: $request,
        );

        return redirect()->route('admin.orders.show', $order)->with('success', 'تم رفع التصميم وتحديث حالة الطلب إلى "في انتظار موافقة العميل".');
    }

    /**
     * Append supplemental child photos supplied after the order was placed.
     */
    public function uploadPhotos(Request $request, Order $order, OrderPhotoUploadService $photoUploads)
    {
        $maximum = (int) config('photo_uploads.admin_max_files', 10);
        $validated = $request->validate([
            'photos' => 'required|array|min:1|max:'.$maximum,
            'photos.*' => 'required|file|max:'.((int) config('photo_uploads.max_size_mb', 15) * 1024),
        ], [
            'photos.required' => 'اختر صورة واحدة واضحة على الأقل لإضافتها إلى الطلب.',
            'photos.array' => 'تعذر قراءة الصور المرفوعة.',
            'photos.min' => 'اختر صورة واحدة واضحة على الأقل لإضافتها إلى الطلب.',
            'photos.max' => 'يمكن رفع '.$maximum.' صور كحد أقصى في المرة الواحدة.',
            'photos.*.file' => 'تعذر قراءة إحدى الصور المرفوعة.',
            'photos.*.max' => 'حجم كل صورة يجب ألا يزيد عن '.config('photo_uploads.max_size_mb', 15).' ميجا.',
        ]);

        $result = $photoUploads->append($order, $validated['photos']);

        AdminActivityLogger::log(
            action: 'order.child_photos_added',
            description: 'إضافة صور جديدة للطفل إلى الطلب: '.$order->order_number,
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'added_count' => $result['added_count'],
                'total_count' => $result['total_count'],
                'files' => $result['files'],
                'production_project_id' => $order->productionProject?->id,
            ],
            request: $request,
        );

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('success', 'تمت إضافة '.$result['added_count'].' صورة جديدة. برومبت الإنتاج يعرض الآن جميع صور الطفل وعددها '.$result['total_count'].'.');
    }

    /**
     * Serve a private child photo from local storage (admin only).
     */
    public function servePhoto(Order $order, int $index)
    {
        return $this->photoResponse($order, $index);
    }

    /**
     * Serve a signed child photo URL for production prompts.
     */
    public function serveProductionPhoto(Order $order, int $index)
    {
        return $this->photoResponse($order, $index);
    }

    private function photoResponse(Order $order, int $index)
    {
        $photos = $order->uploaded_photos ?? [];

        if (! isset($photos[$index])) {
            abort(404);
        }

        $photoPath = $photos[$index];

        if (! is_string($photoPath) || str_contains($photoPath, '..')) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if ($disk->exists($photoPath)) {
            return response()->file($disk->path($photoPath), [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        }

        $publicDisk = Storage::disk('public');
        if ($publicDisk->exists($photoPath)) {
            return response()->file($publicDisk->path($photoPath), [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        }

        // Backward compatibility for files saved before Laravel's local disk moved to storage/app/private.
        $legacyPath = storage_path('app/'.ltrim($photoPath, '/'));
        if (file_exists($legacyPath) && is_file($legacyPath)) {
            return response()->file($legacyPath, [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        }

        abort(404);
    }

    // Stubs for resource controller compliance
    public function create() {}

    public function store(Request $request) {}

    public function edit(string $id) {}

    public function destroy(string $id) {}
}
