<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Orders\AdminShippingReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShippingReportController extends Controller
{
    public function index(Request $request, AdminShippingReportService $reports): View
    {
        return view('admin.shipping-report.index', ['report' => $reports->report($request)]);
    }
}
