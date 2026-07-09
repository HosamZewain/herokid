<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsDateRange;
use App\Services\Analytics\Ga4AnalyticsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(Request $request, Ga4AnalyticsRepository $analytics): View
    {
        $range = AnalyticsDateRange::fromRequest($request);

        return view('admin.analytics.index', [
            'analytics' => $analytics->dashboard($range),
            'range' => $range,
        ]);
    }

    public function refresh(Ga4AnalyticsRepository $analytics): RedirectResponse
    {
        $analytics->flushForProperty();

        return back()->with('success', 'تم تحديث كاش تحليلات الموقع. سيتم جلب أحدث بيانات من Google Analytics.');
    }
}
