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
        // A checkout may contain more than one story order. Dashboard totals
        // intentionally match the grouped admin orders list.
        $orderStats = $orderGroups->dashboardStats();
        $totalOrders = $orderStats['checkouts']['total'];
        $newOrders = $orderStats['checkouts']['new'];
        $pendingPreview = $orderStats['checkouts']['preview_uploaded'];
        $shippedOrders = $orderStats['checkouts']['shipped'];
        $deliveredOrders = $orderStats['checkouts']['delivered'];
        $orderRecordCounts = $orderStats['records'];

        // Content stats
        $totalStories = Story::count();
        $activeStories = Story::where('active', true)->count();
        $totalUsers = User::where('role', '!=', 'admin')->count();
        $unreadMessages = ContactMessage::where('is_read', false)->count();

        $recentOrders = $orderGroups->recent();
        $analyticsWidget = auth()->user()->hasPermission('analytics.view') ? $analytics->widget() : null;

        return view('admin.dashboard.index', compact(
            'totalOrders', 'newOrders', 'pendingPreview', 'shippedOrders', 'deliveredOrders',
            'totalStories', 'activeStories', 'totalUsers', 'unreadMessages',
            'recentOrders', 'analyticsWidget', 'orderRecordCounts'
        ));
    }
}
