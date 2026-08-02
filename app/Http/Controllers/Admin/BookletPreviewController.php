<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookletPreview;
use App\Models\BookletPreviewVersion;
use App\Models\Order;
use App\Models\OrderPreview;
use App\Models\Story;
use App\Services\BookletPreviews\BookletPreviewManager;
use App\Services\Orders\OrderStatusService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BookletPreviewController extends Controller
{
    public function index(Request $request)
    {
        $trash = $request->boolean('trash');
        $query = BookletPreview::query()
            ->when($trash, fn ($builder) => $builder->onlyTrashed())
            ->with(['currentVersion', 'story:id,title,slug', 'order:id,order_number', 'creator:id,name'])
            ->latest();

        $query->when($request->filled('q'), function ($builder) use ($request): void {
            $search = trim((string) $request->query('q'));
            $builder->where(function ($nested) use ($search): void {
                $nested->where('title', 'like', '%'.$search.'%')
                    ->orWhereHas('story', fn ($story) => $story->where('title', 'like', '%'.$search.'%'))
                    ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', '%'.$search.'%'));
            });
        });
        $query->when($request->filled('source_type'), fn ($builder) => $builder->where('source_type', $request->query('source_type')));
        $query->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->query('status')));
        $query->when($request->filled('story_id'), fn ($builder) => $builder->where('story_id', $request->integer('story_id')));

        return view('admin.booklet-previews.index', [
            'previews' => $query->paginate(20)->withQueryString(),
            'stories' => Story::query()->where('active', true)->orderBy('title')->get(['id', 'title']),
            'trash' => $trash,
            'stats' => [
                'active' => BookletPreview::query()->where('status', 'active')->count(),
                'revoked' => BookletPreview::query()->where('status', 'revoked')->count(),
                'published' => BookletPreview::query()->where('show_on_story', true)->where('status', 'active')->count(),
                'trashed' => BookletPreview::onlyTrashed()->count(),
            ],
        ]);
    }

    public function create()
    {
        return view('admin.booklet-previews.create', [
            'stories' => Story::query()->where('active', true)->orderBy('title')->get(['id', 'title', 'language']),
            'maxUploadMb' => (int) config('booklet_previews.max_upload_mb', 50),
            'maxPages' => (int) config('booklet_previews.max_pages', 100),
        ]);
    }

    public function store(Request $request, BookletPreviewManager $manager)
    {
        $validated = $request->validate($this->createRules(), [
            'pdf_file.required' => 'اختر ملف PDF للمعاينة.',
            'pdf_file.mimes' => 'يجب رفع ملف PDF فقط.',
            'story_id.required_if' => 'اختر القصة المرتبطة بالمعاينة.',
        ]);

        $preview = $manager->create([
            'source_type' => $validated['source_type'],
            'story_id' => $validated['source_type'] === 'story' ? $validated['story_id'] : null,
            'title' => $validated['title'],
            'reading_direction' => $validated['reading_direction'],
            'note' => $validated['note'] ?? null,
        ], $validated['pdf_file'], $request->user());

        AdminActivityLogger::log(
            action: 'booklet_preview.created',
            description: 'إنشاء معاينة كتاب: '.$preview->title,
            subject: $preview,
            properties: $this->auditProperties($preview),
            request: $request,
        );

        return redirect()->route('admin.booklet-previews.show', $preview)->with('success', 'تم إنشاء المعاينة والرابط الخاص بنجاح.');
    }

    public function show(BookletPreview $bookletPreview)
    {
        $bookletPreview->load(['versions.uploader:id,name', 'currentVersion', 'story:id,title,slug', 'order:id,order_number', 'creator:id,name', 'revokedBy:id,name']);

        return view('admin.booklet-previews.show', [
            'preview' => $bookletPreview,
            'stories' => Story::query()->where('active', true)->orderBy('title')->get(['id', 'title']),
            'maxUploadMb' => (int) config('booklet_previews.max_upload_mb', 50),
        ]);
    }

    public function update(Request $request, BookletPreview $bookletPreview, BookletPreviewManager $manager)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'story_id' => ['nullable', 'integer', 'exists:stories,id'],
            'reading_direction' => ['required', Rule::in(['rtl', 'ltr'])],
        ]);

        $before = $bookletPreview->only(['title', 'story_id', 'reading_direction']);
        $preview = $manager->updateMetadata($bookletPreview, $validated, $request->user());

        AdminActivityLogger::log(
            action: 'booklet_preview.updated',
            description: 'تحديث بيانات معاينة كتاب: '.$preview->title,
            subject: $preview,
            properties: ['changes' => AdminActivityLogger::changedValues($before, $preview->only(array_keys($before)))],
            request: $request,
        );

        return back()->with('success', 'تم تحديث بيانات المعاينة.');
    }

    public function replace(Request $request, BookletPreview $bookletPreview, BookletPreviewManager $manager)
    {
        $validated = $request->validate($this->pdfRules());
        $preview = $manager->replace($bookletPreview, $validated['pdf_file'], $validated['note'] ?? null, $request->user());

        AdminActivityLogger::log(
            action: 'booklet_preview.version_created',
            description: 'رفع إصدار جديد لمعاينة: '.$preview->title,
            subject: $preview,
            properties: $this->auditProperties($preview),
            request: $request,
        );

        return back()->with('success', 'تم رفع الإصدار الجديد مع الاحتفاظ بنفس رابط العميل.');
    }

    public function storeForOrder(
        Request $request,
        Order $order,
        BookletPreviewManager $manager,
        OrderStatusService $statuses,
    ) {
        abort_unless($order->story_id, 422, 'يمكن إنشاء قارئ معاينة لطلبات القصص فقط.');
        $existing = $order->bookletPreview()->withTrashed()->exists();
        abort_unless(
            $request->user()->hasPermission($existing ? 'booklet_previews.update' : 'booklet_previews.create'),
            403,
        );

        $validated = $request->validate($this->pdfRules());
        $preview = $manager->createOrReplaceForOrder($order, $validated['pdf_file'], $validated['note'] ?? null, $request->user());
        $statuses->update($order, 'preview_uploaded', null, $request);

        AdminActivityLogger::log(
            action: 'order.booklet_preview_uploaded',
            description: 'رفع معاينة كتاب للطلب: '.$order->order_number,
            subject: $order,
            properties: $this->auditProperties($preview),
            request: $request,
        );

        return back()->with('success', 'تم رفع المعاينة وتحديث حالة الطلب. رابط العميل ثابت ويمكن نسخه الآن.');
    }

    public function promoteLegacy(
        Request $request,
        Order $order,
        OrderPreview $legacyPreview,
        BookletPreviewManager $manager,
        OrderStatusService $statuses,
    ) {
        abort_unless($legacyPreview->order_id === $order->id, 404);
        abort_unless($order->story_id, 422, 'يمكن إنشاء قارئ معاينة لطلبات القصص فقط.');
        abort_unless($request->user()->hasPermission('booklet_previews.create'), 403);
        $preview = $manager->promoteLegacy($legacyPreview, $request->user());
        $statuses->update($order, 'preview_uploaded', null, $request);

        AdminActivityLogger::log(
            action: 'order.legacy_preview_promoted',
            description: 'ترقية معاينة PDF قديمة للطلب: '.$order->order_number,
            subject: $order,
            properties: ['legacy_preview_id' => $legacyPreview->id, ...$this->auditProperties($preview)],
            request: $request,
        );

        return back()->with('success', 'تم إنشاء رابط قارئ للمعاينة القديمة.');
    }

    public function publish(Request $request, BookletPreview $bookletPreview, BookletPreviewManager $manager)
    {
        $preview = $manager->publish($bookletPreview, true, $request->user());
        $this->logSimpleAction($request, $preview, 'booklet_preview.published', 'نشر معاينة على صفحة القصة: ');

        return back()->with('success', 'ظهر زر معاينة القصة الآن في صفحة القصة.');
    }

    public function unpublish(Request $request, BookletPreview $bookletPreview, BookletPreviewManager $manager)
    {
        $preview = $manager->publish($bookletPreview, false, $request->user());
        $this->logSimpleAction($request, $preview, 'booklet_preview.unpublished', 'إخفاء معاينة من صفحة القصة: ');

        return back()->with('success', 'تم إخفاء زر المعاينة من صفحة القصة مع بقاء الرابط الخاص فعالًا.');
    }

    public function revoke(Request $request, BookletPreview $bookletPreview, BookletPreviewManager $manager)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $preview = $manager->revoke($bookletPreview, $validated['reason'], $request->user());
        $this->logSimpleAction($request, $preview, 'booklet_preview.revoked', 'إيقاف رابط معاينة: ', ['reason' => $validated['reason']]);

        return back()->with('success', 'تم إيقاف رابط المعاينة.');
    }

    public function reenable(Request $request, BookletPreview $bookletPreview, BookletPreviewManager $manager)
    {
        $preview = $manager->reenable($bookletPreview, $request->user());
        $this->logSimpleAction($request, $preview, 'booklet_preview.reenabled', 'إعادة تفعيل رابط معاينة: ');

        return back()->with('success', 'تمت إعادة تفعيل رابط المعاينة.');
    }

    public function destroy(Request $request, BookletPreview $bookletPreview, BookletPreviewManager $manager)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->logSimpleAction($request, $bookletPreview, 'booklet_preview.deleted', 'نقل معاينة إلى سلة المحذوفات: ', ['reason' => $validated['reason']]);
        $manager->delete($bookletPreview, $request->user());

        return redirect()->route('admin.booklet-previews.index')->with('success', 'تم نقل المعاينة إلى سلة المحذوفات مع الاحتفاظ بالملفات والإصدارات.');
    }

    public function restore(Request $request, string $bookletPreview, BookletPreviewManager $manager)
    {
        $preview = BookletPreview::onlyTrashed()->where('uuid', $bookletPreview)->firstOrFail();
        $preview = $manager->restore($preview, $request->user());
        $this->logSimpleAction($request, $preview, 'booklet_preview.restored', 'استعادة معاينة كتاب: ');

        return redirect()->route('admin.booklet-previews.show', $preview)->with('success', 'تمت استعادة المعاينة.');
    }

    public function download(BookletPreview $bookletPreview, BookletPreviewVersion $version)
    {
        abort_unless($version->booklet_preview_id === $bookletPreview->id, 404);
        abort_unless(Storage::disk($version->disk)->exists($version->file_path), 404);

        return Storage::disk($version->disk)->download(
            $version->file_path,
            $version->original_filename,
            ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function createRules(): array
    {
        return [
            'source_type' => ['required', Rule::in(['story', 'standalone'])],
            'story_id' => ['nullable', 'required_if:source_type,story', 'integer', 'exists:stories,id'],
            'title' => ['required', 'string', 'max:255'],
            'reading_direction' => ['required', Rule::in(['rtl', 'ltr'])],
            ...$this->pdfRules(),
        ];
    }

    private function pdfRules(): array
    {
        return [
            'pdf_file' => ['required', 'file', 'mimes:pdf', 'max:'.((int) config('booklet_previews.max_upload_mb', 50) * 1024)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function auditProperties(BookletPreview $preview): array
    {
        $version = $preview->currentVersion;

        return [
            'preview_uuid' => $preview->uuid,
            'source_type' => $preview->source_type,
            'order_id' => $preview->order_id,
            'story_id' => $preview->story_id,
            'version_number' => $version?->version_number,
            'page_count' => $version?->page_count,
            'file_size' => $version?->file_size,
            'checksum' => $version?->checksum,
        ];
    }

    private function logSimpleAction(Request $request, BookletPreview $preview, string $action, string $prefix, array $properties = []): void
    {
        AdminActivityLogger::log(
            action: $action,
            description: $prefix.$preview->title,
            subject: $preview,
            properties: [...$this->auditProperties($preview), ...$properties],
            request: $request,
        );
    }
}
