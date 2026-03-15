<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPreview;
use Illuminate\Http\Request;

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
        return view('admin.orders.show', compact('order'));
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

        $path = $request->file('preview_file')->store('orders/previews/' . $order->id);

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

    // Stubs for resource controller compliance
    public function create() {}
    public function store(Request $request) {}
    public function edit(string $id) {}
    public function destroy(string $id) {}
}
