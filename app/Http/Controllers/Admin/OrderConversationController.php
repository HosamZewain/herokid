<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\RoboDesk\Conversations\OrderConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OrderConversationController extends Controller
{
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
