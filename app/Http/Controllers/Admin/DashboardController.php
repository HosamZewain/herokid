<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Story;
use App\Models\ContactMessage;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        // Order stats
        $totalOrders    = Order::count();
        $newOrders      = Order::where('status', 'new')->count();
        $pendingPreview = Order::where('status', 'preview_uploaded')->count();
        $shippedOrders  = Order::where('status', 'shipped')->count();
        $deliveredOrders= Order::where('status', 'delivered')->count();

        // Content stats
        $totalStories   = Story::count();
        $activeStories  = Story::where('active', true)->count();
        $totalUsers     = User::where('role', '!=', 'admin')->count();
        $unreadMessages = ContactMessage::where('is_read', false)->count();

        // Recent orders
        $recentOrders = Order::with(['story', 'user'])
            ->latest()
            ->take(8)
            ->get();

        return view('admin.dashboard.index', compact(
            'totalOrders', 'newOrders', 'pendingPreview', 'shippedOrders', 'deliveredOrders',
            'totalStories', 'activeStories', 'totalUsers', 'unreadMessages',
            'recentOrders'
        ));
    }
}
