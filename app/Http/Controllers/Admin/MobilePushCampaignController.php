<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchMobilePushCampaign;
use App\Models\MobilePushCampaign;
use App\Models\User;
use App\Services\Mobile\MobilePushAudience;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MobilePushCampaignController extends Controller
{
    public const DESTINATIONS = [
        'home' => 'الرئيسية', 'catalog' => 'المتجر', 'cart' => 'السلة',
        'orders' => 'طلباتي', 'notifications' => 'الإشعارات', 'profile' => 'حسابي',
    ];

    public function index(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q')), 0, 100);
        $customers = $q === '' ? collect() : User::where('role', 'customer')->where('is_active', true)
            ->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')->orWhere('phone', 'like', '%'.$q.'%'))
            ->select('id', 'name', 'email', 'phone')->orderBy('id')->limit(30)->get();
        $campaigns = MobilePushCampaign::with('creator:id,name', 'target:id,name')->withCount('notifications')->latest()->paginate(20);

        return view('admin.mobile-push.index', ['customers' => $customers, 'campaigns' => $campaigns, 'destinations' => self::DESTINATIONS, 'q' => $q]);
    }

    public function preview(Request $request, MobilePushAudience $audience)
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(['all', 'customer'])],
            'user_id' => ['required_if:audience,customer', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', 'customer')->where('is_active', true)],
            'category' => ['required', Rule::in(['marketing', 'operational'])],
            'title' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:1000'],
            'destination' => ['required', Rule::in(array_keys(self::DESTINATIONS))],
        ]);
        $data['user_id'] = $data['audience'] === 'customer' ? (int) $data['user_id'] : null;
        $count = $audience->query($data['category'], $data['user_id'])->count();
        if (! $count) {
            return back()->withErrors(['audience' => 'لا توجد أجهزة مسجلة ومسموح لها بهذا النوع من الإشعارات ضمن الجمهور المحدد.'])->withInput();
        }
        $token = (string) Str::uuid();
        $draft = [...$data, 'count' => $count, 'expires' => now()->addMinutes(15)->timestamp];
        $request->session()->put('mobile_push_draft', ['token' => $token, 'data' => $draft]);

        return view('admin.mobile-push.confirm', ['draft' => $draft, 'token' => $token, 'destinations' => self::DESTINATIONS,
            'customer' => $data['user_id'] ? User::find($data['user_id']) : null]);
    }

    public function store(Request $request)
    {
        $request->validate(['token' => ['required', 'uuid'], 'confirmed' => ['accepted']]);
        $existing = MobilePushCampaign::where('uuid', $request->input('token'))->where('created_by', $request->user()->id)->first();
        if ($existing) {
            return redirect()->route('admin.mobile-push.show', $existing);
        }
        $saved = $request->session()->get('mobile_push_draft');
        abort_unless($saved && hash_equals($saved['token'], $request->input('token')) && $saved['data']['expires'] >= now()->timestamp, 419, 'انتهت صلاحية التأكيد؛ اعرض المعاينة مرة أخرى.');
        $draft = $saved['data'];
        $campaign = DB::transaction(function () use ($draft, $request) {
            // Lock the sender to serialize double-clicks and concurrent submissions.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $campaign = MobilePushCampaign::firstOrCreate(['uuid' => $request->input('token')], [
                'created_by' => $request->user()->id, 'target_user_id' => $draft['user_id'],
                'audience' => $draft['audience'], 'category' => $draft['category'],
                'title' => $draft['title'], 'body' => $draft['body'], 'data' => ['screen' => $draft['destination']],
                'preview_recipients' => $draft['count'], 'status' => 'queued',
            ]);
            if ($campaign->wasRecentlyCreated) {
                DispatchMobilePushCampaign::dispatch($campaign->id)->afterCommit();
                AdminActivityLogger::log('mobile.push.queued', 'تأكيد إرسال إشعار التطبيق.', $campaign, properties: ['audience' => $draft['audience'], 'category' => $draft['category'], 'recipients_at_preview' => $draft['count']]);
            }

            return $campaign;
        });

        return redirect()->route('admin.mobile-push.show', $campaign)->with('success', 'تمت جدولة الإرسال. تابع النتائج أدناه.');
    }

    public function show(MobilePushCampaign $campaign)
    {
        $notifications = $campaign->notifications()->with('user:id,name', 'deliveries:id,mobile_notification_id,status,receipt_status,error_code,attempts')->latest()->paginate(30);
        $counts = DB::table('mobile_push_deliveries')->join('mobile_notifications', 'mobile_notifications.id', '=', 'mobile_push_deliveries.mobile_notification_id')
            ->where('mobile_push_campaign_id', $campaign->id)->selectRaw('mobile_push_deliveries.status, COUNT(*) as total')->groupBy('mobile_push_deliveries.status')->pluck('total', 'status');

        $receiptCounts = DB::table('mobile_push_deliveries')->join('mobile_notifications', 'mobile_notifications.id', '=', 'mobile_push_deliveries.mobile_notification_id')
            ->where('mobile_push_campaign_id', $campaign->id)->selectRaw('receipt_status, COUNT(*) as total')->groupBy('receipt_status')->pluck('total', 'receipt_status');

        return view('admin.mobile-push.show', compact('campaign', 'notifications', 'counts', 'receiptCounts'));
    }
}
