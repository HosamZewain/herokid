<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesConversation;
use App\Models\SalesTurn;
use App\Models\Setting;
use App\Services\Orders\OrderAccess;
use App\Services\SalesAssistant\SalesCatalog;
use App\Services\SalesAssistant\SalesSessions;
use App\Services\SalesAssistant\SalesSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class SalesAssistantController extends Controller
{
    private function access(Request $request): void
    {
        $assignedOnly = Setting::where('key', 'orders_visibility')->value('value') === 'assigned' && ! $request->user()?->hasPermission('orders.view_all');
        abort_if($assignedOnly || app(OrderAccess::class)->restricted($request->user()) || $request->user()?->agent_api_enabled, 403);
    }

    public function index(Request $request, SalesSettings $settings, SalesCatalog $catalog)
    {
        $this->access($request);
        $input = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from = CarbonImmutable::parse($input['from'] ?? now('Africa/Cairo')->subDays(6)->toDateString(), 'Africa/Cairo')->startOfDay();
        $to = CarbonImmutable::parse($input['to'] ?? now('Africa/Cairo')->toDateString(), 'Africa/Cairo')->endOfDay();
        abort_if($from->diffInDays($to) > 93, 422);
        $events = DB::table('sales_assistant_events')->whereBetween('created_at', [$from->utc(), $to->utc()]);
        $counts = (clone $events)->select('type')->selectRaw('COUNT(*) as total')->groupBy('type')->pluck('total', 'type')->all();
        $canFinance = $request->user()->hasPermission('sales_reports.view');
        $usage = $canFinance ? (clone $events)->selectRaw('SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(cached_tokens) as cached_tokens, SUM(reasoning_tokens) as reasoning_tokens, SUM(spent_micros) as spent_micros, SUM(order_value_cents) as order_value_cents')->first() : null;
        $performance = (clone $events)->where('duration_ms', '>', 0)->selectRaw('AVG(duration_ms) as avg_ms, MAX(duration_ms) as max_ms, SUM(tool_calls) as tool_calls')->first();
        $visitors = (clone $events)->distinct()->count('visitor_hash');
        $conversations = (clone $events)->distinct()->count('conversation_id');
        $top = (clone $events)->whereIn('type', ['recommended', 'upsell_recommended', 'selected', 'cart_added'])->whereNotNull('catalog_key')->select('catalog_key', 'type')->selectRaw('COUNT(*) as total')->groupBy('catalog_key', 'type')->orderByDesc('total')->limit(40)->get();
        $recent = SalesConversation::latest('updated_at')->paginate(20);
        $budgets = $canFinance ? DB::table('sales_daily_budgets')->whereBetween('day', [$from->toDateString(), $to->toDateString()])->orderByDesc('day')->get() : collect();

        $assistantSettings = $settings;

        return view('admin.sales-assistant.index', compact('counts', 'usage', 'performance', 'visitors', 'conversations', 'top', 'recent', 'budgets', 'assistantSettings', 'catalog', 'canFinance', 'from', 'to'));
    }

    public function conversation(Request $request, string $conversation, SalesSessions $sessions)
    {
        $this->access($request);
        $conversation = SalesConversation::findOrFail($conversation);
        abort_if($conversation->updated_at->lt(now()->subDays(30)), 404);

        if ($request->expectsJson()) {
            return response()->json(['turns' => $sessions->turns($conversation)])->header('Cache-Control', 'private, no-store');
        }
        $turns = SalesTurn::where('conversation_id', $conversation->id)->where('created_at', '>', now()->subDays(30))->latest()->paginate(25);
        $events = DB::table('sales_assistant_events')->where('conversation_id', $conversation->id)->orderByDesc('id')->limit(100)->get();
        $canFinance = $request->user()->hasPermission('sales_reports.view');

        return view('admin.sales-assistant.conversation', compact('conversation', 'turns', 'events', 'canFinance'));
    }

    public function update(Request $request, SalesSettings $settings)
    {
        $this->access($request);
        $input = $request->validate(['enabled' => 'nullable|boolean', 'experience_approved' => 'nullable|boolean', 'daily_budget_usd' => 'required|numeric|min:0|max:100']);
        if ($request->boolean('enabled') && (! $request->boolean('experience_approved') || ! $settings->verified() || ! $settings->workerReady())) {
            throw ValidationException::withMessages(['enabled' => 'راجع التجربة واعتمدها، وافحص إتاحة GPT-6 Luna بالمفتاح الحالي، وتأكد إن عامل المساعد متاح قبل التفعيل.']);
        }
        foreach (['enabled' => $request->boolean('enabled') ? '1' : '0', 'daily_budget_micros' => (string) (int) round($input['daily_budget_usd'] * 1000000), 'experience_approved' => $request->boolean('experience_approved') ? now()->toIso8601String() : ''] as $key => $value) {
            Setting::updateOrCreate(['key' => 'sales_assistant_'.$key], ['value' => $value, 'updated_by' => $request->user()->id]);
        }

        return back()->with('success', 'تم حفظ إعدادات مساعد المبيعات.');
    }

    public function verify(Request $request, SalesSettings $settings)
    {
        $this->access($request);
        $key = $settings->credential();
        $available = false;
        if ($key) {
            try {
                $response = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(12)->get('https://api.openai.com/v1/models/gpt-6-luna');
                $available = $response->successful() && $response->json('id') === 'gpt-6-luna';
            } catch (\Throwable) {
            }
        }
        Setting::updateOrCreate(['key' => 'sales_assistant_model_verified'], ['value' => $available ? json_encode(['model' => 'gpt-6-luna', 'credential_hash' => hash('sha256', $key), 'at' => now()->timestamp], JSON_THROW_ON_ERROR) : '', 'updated_by' => $request->user()->id]);
        if (! $available) {
            Setting::updateOrCreate(['key' => 'sales_assistant_enabled'], ['value' => '0']);
        }

        return back()->with($available ? 'success' : 'error', $available ? 'GPT-6 Luna ظاهر للمفتاح الحالي. الفحص لا ينشئ ردًا مدفوعًا؛ نجاح أول رد فعلي يظل مطلوبًا بعد التفعيل.' : 'تعذر التحقق من إتاحة GPT-6 Luna. المساعد الذكي يظل متوقفًا، من غير تبديل الموديل.');
    }

    public function profile(Request $request, SalesCatalog $catalog)
    {
        $this->access($request);
        $input = $request->validate(['key' => ['required', 'string', 'regex:/^(story|product|package):[1-9][0-9]*$/'], 'keywords' => 'nullable|string|max:1500']);
        $catalog->item($input['key']);
        DB::table('sales_catalog_profiles')->updateOrInsert(['catalog_key' => $input['key']], ['keywords' => strip_tags($input['keywords'] ?? ''), 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget(SalesCatalog::CACHE_KEY);

        return back()->with('success', 'تم حفظ وسوم البحث والمرادفات.');
    }
}
