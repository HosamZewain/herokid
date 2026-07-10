<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\VisitorCart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VisitorCartController extends Controller
{
    public function index(Request $request): View
    {
        $query = VisitorCart::query()
            ->with(['user:id,name,email,phone', 'relatedOrder:id,order_number', 'activeItems.story:id,title'])
            ->withCount(['activeItems', 'activities']);

        $this->applyFilters($query, $request);

        $summary = [
            'active' => VisitorCart::where('status', 'active')->count(),
            'abandoned' => VisitorCart::where('status', 'abandoned')->count(),
            'converted' => VisitorCart::where('status', 'converted')->count(),
            'abandoned_value' => ((int) VisitorCart::where('status', 'abandoned')->sum('cart_total_cents')) / 100,
            'conversion_rate' => $this->conversionRate(),
        ];

        $carts = $query->latest('last_activity_at')->paginate(20)->withQueryString();
        $stories = Story::where('active', true)->orderBy('title')->get(['id', 'title']);

        return view('admin.visitor-carts.index', [
            'carts' => $carts,
            'summary' => $summary,
            'stories' => $stories,
            'canViewCustomers' => $request->user()?->hasPermission('customers.view') ?? false,
        ]);
    }

    public function show(Request $request, VisitorCart $visitorCart): View
    {
        $visitorCart->load([
            'user:id,name,email,phone',
            'relatedOrder:id,order_number',
            'items.story:id,title,slug',
            'items.product:id,name_ar,slug',
            'activities' => fn ($query) => $query->with(['item:id,title_snapshot', 'user:id,name'])->latest(),
        ]);

        return view('admin.visitor-carts.show', [
            'cart' => $visitorCart,
            'canViewCustomers' => $request->user()?->hasPermission('customers.view') ?? false,
        ]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('status'), fn (Builder $builder) => $builder->where('status', $request->string('status')))
            ->when($request->input('customer_type') === 'guest', fn (Builder $builder) => $builder->whereNull('user_id'))
            ->when($request->input('customer_type') === 'known', fn (Builder $builder) => $builder->whereNotNull('user_id'))
            ->when($request->filled('story_id'), function (Builder $builder) use ($request): void {
                $builder->whereHas('items', fn (Builder $items) => $items->where('story_id', $request->integer('story_id')));
            })
            ->when($request->filled('date_from'), fn (Builder $builder) => $builder->whereDate('first_added_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn (Builder $builder) => $builder->whereDate('first_added_at', '<=', $request->date('date_to')))
            ->when($request->filled('q'), function (Builder $builder) use ($request): void {
                $term = trim((string) $request->input('q'));
                $builder->where(function (Builder $nested) use ($term): void {
                    $nested
                        ->where('cart_identifier', 'like', '%'.$term.'%')
                        ->orWhereHas('user', function (Builder $user) use ($term): void {
                            $user->where('name', 'like', '%'.$term.'%')
                                ->orWhere('email', 'like', '%'.$term.'%')
                                ->orWhere('phone', 'like', '%'.$term.'%');
                        })
                        ->orWhereHas('relatedOrder', fn (Builder $order) => $order->where('order_number', 'like', '%'.$term.'%'));
                });
            });
    }

    private function conversionRate(): ?float
    {
        $total = VisitorCart::whereIn('status', ['active', 'abandoned', 'converted'])->count();

        if ($total === 0) {
            return null;
        }

        return round((VisitorCart::where('status', 'converted')->count() / $total) * 100, 2);
    }
}
