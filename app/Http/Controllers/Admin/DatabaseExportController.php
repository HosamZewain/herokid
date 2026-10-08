<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DatabaseExport;
use App\Services\DatabaseExports\DatabaseExportService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DatabaseExportController extends Controller
{
    public function index(Request $request, DatabaseExportService $service)
    {
        $exports = DatabaseExport::where('requested_by', $request->user()->id)->latest('id')->limit(20)->get();
        if ($request->expectsJson()) {
            return response()->json(['exports' => $exports->map(fn ($export) => $export->only(['uuid', 'status', 'size']))])
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->view('admin.database-exports.index', ['exports' => $exports, 'available' => $service->available()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, DatabaseExportService $service)
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password:web']]);
        $service->request($request->user());

        return redirect()->route('admin.database-exports.index')->with('success', 'تم طلب التصدير. يبدأ التجهيز خلال دقيقة عند تشغيل مجدول الموقع.');
    }

    public function download(Request $request, DatabaseExport $export)
    {
        abort_unless((int) $export->requested_by === (int) $request->user()->id, 403);
        $request->validate(['current_password' => ['required', 'string', 'current_password:web']]);
        abort_unless($export->status === 'ready' && $export->expires_at?->isFuture(), 410);
        $expected = 'admin/database-exports/files/'.$export->uuid.'.sql.gz';
        abort_unless($export->disk && $export->path === $expected && Storage::disk($export->disk)->exists($expected), 404);
        AdminActivityLogger::log('database_export.downloaded', 'تحميل نسخة كاملة من قاعدة البيانات', $export, ['size' => $export->size]);

        return Storage::disk($export->disk)->download($expected, $export->filename, [
            'Content-Type' => 'application/gzip', 'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
