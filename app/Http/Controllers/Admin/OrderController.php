<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPreview;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['user', 'story'])->latest();

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->paginate(15)->withQueryString();

        return view('admin.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        $order->load(['user', 'story', 'statusLogs', 'previews']);
        $storyProductionPrompt = StoryProductionPrompt::forOrder($order);

        return view('admin.orders.show', compact('order', 'storyProductionPrompt'));
    }

    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status'       => 'required|string|in:new,under_review,generating,preview_uploaded,approved_for_print,printing,shipped,delivered,cancelled',
            'admin_notes'  => 'nullable|string|max:2000',
        ]);

        $statusChanged = $order->status !== $validated['status'];

        $order->update([
            'status' => $validated['status'],
            'notes'  => $validated['admin_notes'] ?? $order->notes,
        ]);

        if ($statusChanged) {
            $order->statusLogs()->create([
                'status' => $validated['status'],
                'notes'  => $request->admin_notes ?? 'تم تحديث الحالة من لوحة الإدارة.',
            ]);
        }

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

        $path = $request->file('preview_file')->store('orders/previews/' . $order->id, 'local');

        OrderPreview::create([
            'order_id'     => $order->id,
            'file_path'    => $path,
            'note'         => $request->preview_note,
            'uploaded_by'  => auth()->id(),
        ]);

        // Update order status to preview_uploaded
        $order->update(['status' => 'preview_uploaded']);
        $order->statusLogs()->create([
            'status' => 'preview_uploaded',
            'notes'  => 'تم رفع التصميم الأولي وإرساله للعميل للموافقة.',
        ]);

        return redirect()->route('admin.orders.show', $order)->with('success', 'تم رفع التصميم وتحديث حالة الطلب إلى "في انتظار موافقة العميل".');
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

        if (!isset($photos[$index])) {
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
        $legacyPath = storage_path('app/' . ltrim($photoPath, '/'));
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
