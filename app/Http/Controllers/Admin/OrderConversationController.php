<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\RoboDesk\Conversations\ConversationAccess;
use App\Services\RoboDesk\Conversations\ConversationNotifications;
use App\Services\RoboDesk\Conversations\ConversationReplyException;
use App\Services\RoboDesk\Conversations\ConversationReplyService;
use App\Services\RoboDesk\Conversations\OrderConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OrderConversationController extends Controller
{
    public function notifications(Request $request, ConversationNotifications $notifications): JsonResponse
    {
        return response()->json($notifications->awaitingReply($request->user()))->header('Cache-Control', 'no-store, private');
    }

    public function read(Request $request, Order $order, ConversationNotifications $notifications, ConversationAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $order);
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);
        $notifications->markRead($request->user(), $order, (int) $data['version']);

        return response()->json(['read' => true])->header('Cache-Control', 'no-store, private');
    }

    public function reply(Request $request, Order $order, ConversationReplyService $replies, ConversationAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $order);
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'text' => ['nullable', 'string', 'max:4096'],
            'image' => ['nullable', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120', 'dimensions:max_width=12000,max_height=12000'],
            'attachment' => ['prohibited'], 'phone' => ['prohibited'], 'channel' => ['prohibited']]);
        $text = (string) ($data['text'] ?? '');
        if (trim($text) === '' && ! $request->hasFile('image')) {
            throw ValidationException::withMessages(['text' => 'اكتب رسالة أو أضف صورة.']);
        }
        try {
            return response()->json($replies->send($order, $request->user(), $data['request_id'], $text, $request->file('image')))
                ->header('Cache-Control', 'no-store, private');
        } catch (ConversationReplyException $e) {
            return response()->json(['code' => $e->reason, 'message' => $e->getMessage()], $e->status)->header('Cache-Control', 'no-store, private');
        } catch (\Throwable) {
            return response()->json(['code' => 'SEND_UNCERTAIN', 'message' => 'تعذر تأكيد الإرسال. حدّث المحادثة قبل إعادة الإرسال.'], 502)
                ->header('Cache-Control', 'no-store, private');
        }
    }

    public function show(Request $request, Order $order, OrderConversationService $service): JsonResponse
    {
        return $this->respond($request, $order, $service, false);
    }

    public function sync(Request $request, Order $order, OrderConversationService $service): JsonResponse
    {
        return $this->respond($request, $order, $service, true);
    }

    private function respond(Request $request, Order $order, OrderConversationService $service, bool $sync): JsonResponse
    {
        app(ConversationAccess::class)->authorize($request->user(), $order);
        $validated = $request->validate(['before' => ['nullable', 'integer', 'min:1'], 'full' => ['nullable', 'boolean']]);
        $before = isset($validated['before']) ? (int) $validated['before'] : null;
        try {
            $payload = $sync ? $service->sync($order, $before, $request->boolean('full')) : $service->history($order, $before);

            return response()->json($payload)->header('Cache-Control', 'no-store, private');
        } catch (ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            $code = in_array($e->getMessage(), ['setup_required', 'provider_authentication', 'invalid_provider_response'], true)
                ? $e->getMessage() : 'provider_unavailable';

            return response()->json(['reason' => $code, 'message' => match ($code) {
                'setup_required' => 'أضف بيانات ربط المحادثات في إعدادات RoboDesk أولاً.',
                'provider_authentication' => 'تعذر تسجيل الدخول إلى RoboDesk. راجع بيانات الربط وصلاحيات الحساب.',
                default => 'تعذر تحديث المحادثة حالياً. الرسائل المحفوظة ما زالت متاحة.',
            }], $code === 'setup_required' ? 422 : 502)->header('Cache-Control', 'no-store, private');
        }
    }
}
