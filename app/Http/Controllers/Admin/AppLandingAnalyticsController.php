<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AppLandingAnalytics;
use Illuminate\Http\Request;

class AppLandingAnalyticsController extends Controller
{
    public function index(Request $request, AppLandingAnalytics $analytics)
    {
        return view('admin.analytics.app-download', $analytics->report($request));
    }
}
