<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderApprovedChildIdentityUploadService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;

class OrderApprovedChildIdentityController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        OrderApprovedChildIdentityUploadService $uploads,
    ) {
        abort_unless($order->story_id, 422, 'لا يمكن رفع هوية طفل إلا لطلب قصة.');

        $validated = $request->validate([
            'approved_identity' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:15360'],
        ], [
            'approved_identity.required' => 'اختر صورة الهوية المعتمدة أولًا.',
            'approved_identity.mimetypes' => 'صورة الهوية يجب أن تكون JPG أو PNG أو WebP.',
            'approved_identity.max' => 'حجم صورة الهوية يجب ألا يزيد عن 15 ميجا.',
        ]);

        $attempt = $uploads->upload($order, $validated['approved_identity'], $request->user());

        AdminActivityLogger::log(
            action: 'order.approved_child_identity_uploaded',
            description: 'رفع وربط هوية طفل معتمدة بالطلب: '.$order->order_number,
            subject: $order,
            properties: [
                'attempt_id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'file_name' => basename($validated['approved_identity']->getClientOriginalName()),
                'output_checksum' => $attempt->output_checksum,
            ],
            request: $request,
        );

        return redirect()
            ->to(route('admin.orders.groups.show', $order->id).'#story-identity-'.$order->id)
            ->with('success', 'تم رفع الهوية المعتمدة وربطها بالقصة. رابطها أصبح متاحًا لبرومبت إنتاج القصة.');
    }
}
