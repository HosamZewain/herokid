<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPreview;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderDeletionService;
use App\Services\Orders\OrderDetailsUpdateService;
use App\Services\Orders\OrderSceneTextService;
use App\Services\Orders\OrderStatusService;
use App\Services\Uploads\OrderPhotoUploadService;
use App\Support\AdminActivityLogger;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request, AdminOrderGroupService $groups)
    {
        $result = $groups->paginate($request);

        return view('admin.orders.index', $result);
    }

    public function show(Order $order, AdminOrderGroupService $groups, OrderSceneTextService $sceneTexts)
    {
        $order->load([
            'user',
            'story.sceneTemplates',
            'sceneTextSnapshots',
            'statusLogs',
            'previews',
            'items.product',
            'items.variant',
            'items.linkedAddOns.product',
            'productionPromptOverride.editor',
            'productionPromptSnapshots.creator',
            'productionProject.assignedTo',
            'productionProject.scenes',
            'childIdentityRequest.photos',
            'childIdentityRequest.attempts',
            'childIdentityApprovedAttempt',
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

        $checkoutGroup = $groups->findByRepresentative($order->id);
        $sceneTextHandoff = $order->story ? $sceneTexts->present($order) : null;

        return view('admin.orders.show', compact('order', 'checkoutGroup', 'storyProductionPrompt', 'globalStoryProductionPrompt', 'productionPromptTemplateSetting', 'sceneTextHandoff'));
    }

    public function update(Request $request, Order $order, OrderStatusService $statuses)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:new,under_review,generating,preview_uploaded,approved_for_print,printing,shipped,delivered,cancelled',
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $statuses->update($order, $validated['status'], $validated['admin_notes'] ?? null, $request);

        return redirect()->route('admin.orders.show', $order)->with('success', 'تم تحديث الطلب بنجاح!');
    }

    public function updateDetails(Request $request, Order $order, OrderDetailsUpdateService $details)
    {
        $storyRules = $order->story_id
            ? ['child_name' => 'required|string|max:100', 'child_age' => 'required|integer|min:1|max:18', 'child_gender' => 'required|in:boy,girl']
            : ['child_name' => 'nullable|string|max:100', 'child_age' => 'nullable|integer|min:1|max:18', 'child_gender' => 'nullable|in:boy,girl'];

        $validated = $request->validate([
            'parent_name' => 'required|string|max:150',
            'phone' => 'required|string|max:30',
            ...$storyRules,
            'language' => 'nullable|in:ar,en',
            'lesson' => 'nullable|string|max:500',
            'interests' => 'nullable|string|max:1000',
            'gift_note' => 'nullable|string|max:1000',
            'parent_notes' => 'nullable|string|max:2000',
            'change_reason' => 'required|string|min:5|max:500',
        ], [
            'parent_name.required' => 'اكتب اسم ولي الأمر.',
            'phone.required' => 'اكتب رقم الهاتف أو واتساب.',
            'child_name.required' => 'اكتب اسم الطفل.',
            'child_age.required' => 'اكتب عمر الطفل.',
            'child_age.integer' => 'عمر الطفل يجب أن يكون رقمًا صحيحًا.',
            'child_age.min' => 'عمر الطفل يجب ألا يقل عن سنة.',
            'child_age.max' => 'عمر الطفل يجب ألا يزيد عن 18 سنة.',
            'child_gender.required' => 'اختر جنس الطفل.',
            'change_reason.required' => 'اكتب سبب تعديل بيانات الطلب لحفظه في سجل النشاط.',
            'change_reason.min' => 'سبب التعديل يجب ألا يقل عن 5 أحرف.',
        ]);

        $result = $details->update($order, $validated, $request->user(), $request);
        $message = 'تم تحديث بيانات الطلب ومزامنتها مع نصوص المشاهد وبرومبت الإنتاج.';

        if ($result['production_requires_review']) {
            $message .= ' مشروع Production Studio معلّم الآن للمراجعة حتى لا تُفقد التعديلات اليدوية أو تُستخدم أصول قديمة بالخطأ.';
        }

        return redirect()->route('admin.orders.show', $order)->with('success', $message);
    }

    public function destroy(Request $request, Order $order, OrderDeletionService $deletions)
    {
        $validated = $request->validate([
            'deletion_reason' => 'required|string|min:5|max:1000',
            'confirmation' => 'required|string',
        ]);

        if (! hash_equals($order->order_number, trim($validated['confirmation']))) {
            throw ValidationException::withMessages(['confirmation' => 'اكتب رقم الطلب كما هو لتأكيد الحذف.']);
        }

        $deletions->deleteOrder($order, $validated['deletion_reason'], $request->user(), $request);

        return redirect()->route('admin.orders.groups.show', $order->id)->with('success', 'تم نقل القصة/الطلب إلى سلة المحذوفات مع الاحتفاظ بكل البيانات.');
    }

    public function restore(Request $request, int $order, OrderDeletionService $deletions)
    {
        $trashed = Order::onlyTrashed()->findOrFail($order);
        $deletions->restoreOrder($trashed, $request->user(), $request);

        return redirect()->route('admin.orders.groups.show', $trashed->id)->with('success', 'تمت استعادة القصة/الطلب بنجاح.');
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

    public function serveApprovedChildIdentity(Order $order)
    {
        $attempt = $order->childIdentityApprovedAttempt;

        abort_unless(
            $attempt
            && $attempt->status === 'succeeded'
            && filled($attempt->output_storage_path)
            && ! str_contains($attempt->output_storage_path, '..'),
            404,
        );
        $disk = Storage::disk($attempt->output_disk ?: 'local');
        abort_unless($disk->exists($attempt->output_storage_path), 404);

        return response()->file($disk->path($attempt->output_storage_path), [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
}
