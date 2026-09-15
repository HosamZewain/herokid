<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminDashboardNote;
use App\Models\AdminDashboardNoteAttachment;
use App\Models\ContactMessage;
use App\Models\Story;
use App\Models\User;
use App\Services\Analytics\Ga4AnalyticsRepository;
use App\Services\Orders\AdminOrderGroupService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DashboardController extends Controller
{
    public function index(
        Ga4AnalyticsRepository $analytics,
        AdminOrderGroupService $orderGroups
    ) {
        $canViewStatistics = auth()->user()->hasPermission('dashboard.statistics.view');
        $orderStats = $canViewStatistics ? $orderGroups->dashboardStats() : null;

        // Numeric dashboard data is not queried unless the separate sensitive
        // statistics permission is present.
        $totalOrders = data_get($orderStats, 'checkouts.total');
        $newOrders = data_get($orderStats, 'checkouts.new');
        $pendingPreview = data_get($orderStats, 'checkouts.preview_uploaded');
        $shippedOrders = data_get($orderStats, 'checkouts.shipped');
        $deliveredOrders = data_get($orderStats, 'checkouts.delivered');
        $orderRecordCounts = data_get($orderStats, 'records');
        $todayStats = data_get($orderStats, 'today');
        $operationsStats = data_get($orderStats, 'operations');
        $lastSevenDaysStats = data_get($orderStats, 'last_seven_days', []);

        $totalStories = $canViewStatistics ? Story::count() : null;
        $activeStories = $canViewStatistics ? Story::where('active', true)->count() : null;
        $totalUsers = $canViewStatistics ? User::where('role', '!=', 'admin')->count() : null;
        $unreadMessages = $canViewStatistics ? ContactMessage::where('is_read', false)->count() : null;

        $recentOrders = $orderGroups->recent();
        $analyticsWidget = $canViewStatistics && auth()->user()->hasPermission('analytics.view')
            ? ['status' => 'loading']
            : null;
        $managementNotes = AdminDashboardNote::query()
            ->with(['author:id,name', 'attachments'])
            ->latest('id')
            ->limit(100)
            ->get();

        return view('admin.dashboard.index', compact(
            'totalOrders', 'newOrders', 'pendingPreview', 'shippedOrders', 'deliveredOrders',
            'totalStories', 'activeStories', 'totalUsers', 'unreadMessages',
            'recentOrders', 'analyticsWidget', 'orderRecordCounts', 'todayStats', 'operationsStats',
            'lastSevenDaysStats', 'canViewStatistics', 'managementNotes'
        ));
    }

    public function storeManagementNote(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:20000', 'required_without:attachments'],
            'attachments' => ['nullable', 'array', 'max:5', 'required_without:body'],
            'attachments.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,txt,csv,doc,docx,xls,xlsx', 'max:20480'],
        ], [
            'body.max' => 'ملاحظات الإدارة لا يمكن أن تتجاوز 20,000 حرف.',
            'body.required_without' => 'اكتب ملاحظة أو أضف مرفقًا واحدًا على الأقل.',
            'attachments.required_without' => 'اكتب ملاحظة أو أضف مرفقًا واحدًا على الأقل.',
            'attachments.max' => 'يمكن إضافة 5 مرفقات بحد أقصى لكل ملاحظة.',
            'attachments.*.mimes' => 'كل مرفق يجب أن يكون صورة أو PDF أو ملفًا نصيًا أو Word أو Excel.',
            'attachments.*.max' => 'حجم كل مرفق لا يمكن أن يتجاوز 20 ميجابايت.',
        ]);

        $uploads = collect($request->file('attachments', []));
        if ($uploads->sum(fn ($file): int => (int) $file->getSize()) > 50 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'attachments' => 'إجمالي حجم مرفقات الملاحظة لا يمكن أن يتجاوز 50 ميجابايت.',
            ]);
        }

        $stored = [];

        try {
            foreach ($uploads as $upload) {
                $path = $upload->store('admin/dashboard-management-notes', 'local');
                if (! $path) {
                    throw ValidationException::withMessages(['attachments' => 'تعذر حفظ أحد المرفقات. حاول مرة أخرى.']);
                }

                $stored[] = [
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => Str::limit(basename($upload->getClientOriginalName()), 240, ''),
                    'mime_type' => $upload->getMimeType(),
                    'size' => $upload->getSize(),
                ];
            }

            DB::transaction(function () use ($request, $validated, $stored): void {
                $note = AdminDashboardNote::query()->create([
                    'body' => $validated['body'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
                $note->attachments()->createMany($stored);

                AdminActivityLogger::log(
                    action: 'admin.dashboard_management_note.created',
                    description: 'تمت إضافة ملاحظة إدارية مشتركة.',
                    subject: $note,
                    properties: [
                        'body_length' => mb_strlen((string) ($validated['body'] ?? '')),
                        'attachment_count' => count($stored),
                        'attachment_names' => array_column($stored, 'original_name'),
                    ],
                    request: $request,
                );

            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_column($stored, 'path'));

            throw $exception;
        }

        return redirect()->route('admin.dashboard.index', [], 303)
            ->withFragment('management-notes')
            ->with('success', 'تم حفظ ملاحظات الإدارة.');
    }

    public function downloadManagementNoteAttachment(AdminDashboardNoteAttachment $attachment): StreamedResponse
    {
        $disk = $attachment->disk ?: 'local';

        abort_unless(Storage::disk($disk)->exists($attachment->path), 404);

        return Storage::disk($disk)->download(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
