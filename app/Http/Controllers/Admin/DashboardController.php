<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Story;
use App\Models\User;
use App\Services\Analytics\Ga4AnalyticsRepository;
use App\Services\Orders\AdminOrderGroupService;

class DashboardController extends Controller
{
    public function index(
        Ga4AnalyticsRepository $analytics,
        AdminOrderGroupService $orderGroups
    ) {
        $canViewStatistics = auth()->user()->hasPermission('dashboard.statistics.view');
        $orderStats = $canViewStatistics ? $orderGroups->dashboardStats() : null;

        // Numeric dashboard data is not queried unless the separate sensitive
        // statistics permission is present.
        $totalOrders = data_get($orderStats, 'checkouts.total');
        $newOrders = data_get($orderStats, 'checkouts.new');
        $pendingPreview = data_get($orderStats, 'checkouts.preview_uploaded');
        $shippedOrders = data_get($orderStats, 'checkouts.shipped');
        $deliveredOrders = data_get($orderStats, 'checkouts.delivered');
        $orderRecordCounts = data_get($orderStats, 'records');
        $todayStats = data_get($orderStats, 'today');
        $operationsStats = data_get($orderStats, 'operations');
        $lastSevenDaysStats = data_get($orderStats, 'last_seven_days', []);

        $totalStories = $canViewStatistics ? Story::count() : null;
        $activeStories = $canViewStatistics ? Story::where('active', true)->count() : null;
        $totalUsers = $canViewStatistics ? User::where('role', '!=', 'admin')->count() : null;
        $unreadMessages = $canViewStatistics ? ContactMessage::where('is_read', false)->count() : null;

        $recentOrders = $orderGroups->recent();
        $analyticsWidget = $canViewStatistics && auth()->user()->hasPermission('analytics.view')
            ? $analytics->widget()
            : null;

        return view('admin.dashboard.index', compact(
            'totalOrders', 'newOrders', 'pendingPreview', 'shippedOrders', 'deliveredOrders',
            'totalStories', 'activeStories', 'totalUsers', 'unreadMessages',
            'recentOrders', 'analyticsWidget', 'orderRecordCounts', 'todayStats', 'operationsStats',
            'lastSevenDaysStats', 'canViewStatistics'
        ));
    }
}
