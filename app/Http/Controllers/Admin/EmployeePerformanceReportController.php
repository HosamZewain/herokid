<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Orders\EmployeePerformanceReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeePerformanceReportController extends Controller
{
    public function index(Request $request, EmployeePerformanceReportService $reports): View
    {
        return view('admin.employee-performance.index', ['report' => $reports->report($request)]);
    }
}
