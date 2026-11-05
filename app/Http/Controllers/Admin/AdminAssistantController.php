<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAssistant\AssistantAccess;
use App\Services\AdminAssistant\AssistantPageContext;
use App\Services\AdminAssistant\AssistantPlanValidator;
use App\Services\AdminAssistant\AssistantProvider;
use App\Services\AdminAssistant\AssistantReader;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;

final class AdminAssistantController extends Controller
{
    public function access(AssistantAccess $access)
    {
        $user = $access->user();

        return response()->json(['signature' => $access->signature($user), 'tools' => $access->tools($user), 'read_only' => true])->header('Cache-Control', 'private, no-store');
    }

    public function ask(Request $request, AssistantAccess $access, AssistantProvider $provider, AssistantPageContext $pages, AssistantReader $reader)
    {
        $user = $access->user();
        $input = $request->validate(['question' => 'required|string|max:2000', 'path' => 'required|string|max:200',
            'query' => 'present|array|max:25', 'query.*' => 'nullable|string|max:160', 'previous' => 'nullable|string|max:8192', 'signature' => 'required|string|size:64']);
        $signature = $input['signature'];
        $access->check($signature);
        $rate = 'admin-assistant:'.$user->id;
        abort_if(RateLimiter::tooManyAttempts($rate, (int) config('admin_assistant.per_user_per_minute')), 429, 'انتظر قليلًا قبل السؤال التالي.');
        $lock = Cache::lock($rate.':lock', 60);
        abort_unless($lock->get(), 409, 'يوجد سؤال قيد الإجابة.');
        try {
            RateLimiter::hit($rate, 60);
            $tools = $access->tools($user);
            $page = $pages->resolve($input['path'], $input['query'], $tools);
            if (! empty($input['previous'])) {
                try {
                    $previous = json_decode(Crypt::decryptString($input['previous']), true, 16, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    abort(422, 'سياق المحادثة غير صالح. ابدأ سؤالًا جديدًا.');
                }
                abort_unless(($previous['user'] ?? null) === $user->id && ($previous['signature'] ?? null) === $signature && ($previous['expires'] ?? 0) > now()->timestamp, 403);
                // Only the previous resource selector is carried forward. No earlier answer/facts.
                if ($page['tool'] === 'help') {
                    $page = $previous['page'];
                }
            }
            AdminActivityLogger::log('admin_assistant.requested', 'طلب قراءة من مساعد Auto Pilot', properties: ['question_sha256' => hash('sha256', $input['question']), 'read_only' => true]);
            try {
                $plan = $provider->plan(['question' => $input['question'], 'available_tools' => $tools,
                    'page' => ['tool' => $page['tool'], 'reference' => $page['reference'], 'range' => $page['filters']['range'] ?? null],
                    'previous_reference' => $previous['page']['reference'] ?? null, 'today_cairo' => now('Africa/Cairo')->toDateString()]);
            } catch (\Throwable) {
                return response()->json(['message' => 'مساعد المحادثة غير متاح الآن. لم يتغيّر أي أوردر؛ المتابعة والتنفيذ الحاليان مستمران.'], 503)->header('Cache-Control', 'private, no-store');
            }
            $plan = app(AssistantPlanValidator::class)->validate($plan, $tools);
            $user = $access->check($signature);
            if ($plan['tool'] === 'unsupported') {
                $result = ['text' => 'السؤال خارج المصادر المتاحة لك في هذه المرحلة أو يحتاج صلاحية غير متاحة. لا أقدر أستنتج بيانات غير مسموح بها. جرّب حالة أوردر، الطلبات النشطة، أو تقريرًا متاحًا لك.', 'facts' => [], 'sources' => []];
            } elseif ($plan['range'] === 'page' && $page['unsupported_filters'] !== [] && $plan['tool'] === $page['tool']) {
                $result = ['text' => 'الصفحة عليها فلاتر غير مدعومة في الشات حاليًا. حدّد فترة أو أوردرًا في سؤالك؛ لم أعرض إجماليًا يتجاهل الفلاتر.', 'facts' => [], 'sources' => []];
            } else {
                $filters = $plan['range'] === 'page' && $plan['tool'] === $page['tool'] ? $page['filters'] : [];
                if ($plan['range'] !== 'page') {
                    $filters = ['range' => $plan['range']];
                    if ($plan['range'] === 'custom') {
                        abort_unless($plan['start_date'] && $plan['end_date'], 422);
                        $filters += ['start_date' => $plan['start_date'], 'end_date' => $plan['end_date']];
                    }
                }
                $filters['range'] ??= 'this_month';
                $plan['reference'] ??= $plan['tool'] === 'order' ? $page['reference'] : null;
                $result = $reader->read($user, $plan, $filters);
                $page = ['tool' => $plan['tool'], 'reference' => $result['reference'] ?? $plan['reference'], 'filters' => $filters, 'unsupported_filters' => []];
            }
            $access->check($signature);
            if ($plan['intent'] === 'execute') {
                $result['text'] = 'لم يتم تنفيذ أي خطوة. التنفيذ من الشات لسه غير مفعّل؛ افتح مصدر الأوردر لاستخدام إجراءاته المعتمدة. '.$result['text'];
            }
            AdminActivityLogger::log('admin_assistant.read', 'اكتملت قراءة مساعد Auto Pilot', properties: ['tool' => $plan['tool'], 'intent' => $plan['intent'], 'read_only' => true]);
            unset($result['reference']);

            return response()->json($result + ['read_only' => true, 'read_at' => now('Africa/Cairo')->format('Y-m-d H:i:s').' القاهرة', 'signature' => $signature,
                'previous' => Crypt::encryptString(json_encode(['user' => $user->id, 'signature' => $signature, 'expires' => now()->addMinutes(15)->timestamp, 'page' => $page], JSON_THROW_ON_ERROR))])->header('Cache-Control', 'private, no-store');
        } finally {
            $lock->release();
        }
    }
}
