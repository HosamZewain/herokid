<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerStoryView;
use App\Models\Order;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = $this->customerRows();

        if ($request->filled('q')) {
            $term = Str::lower((string) $request->q);
            $customers = $customers->filter(function (array $customer) use ($term): bool {
                return Str::contains(Str::lower(implode(' ', [
                    $customer['name'],
                    $customer['email'],
                    $customer['phone'],
                    $customer['address'],
                    $customer['type_label'],
                ])), $term);
            });
        }

        $customers = $customers
            ->sortByDesc(fn (array $customer) => optional($customer['last_activity_at'])->timestamp ?? 0)
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 20;

        $paginatedCustomers = new LengthAwarePaginator(
            $customers->forPage($page, $perPage)->values(),
            $customers->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );

        return view('admin.customers.index', [
            'customers' => $paginatedCustomers,
            'totalCustomers' => $customers->count(),
        ]);
    }

    public function show(string $customerKey)
    {
        $customer = $this->resolveCustomer($customerKey);

        abort_if(! $customer, 404);

        return view('admin.customers.show', $customer);
    }

    public function edit(string $customerKey): View
    {
        $customer = $this->resolveCustomer($customerKey);

        abort_if(! $customer, 404);

        return view('admin.customers.edit', array_merge($customer, [
            'customerKey' => $customerKey,
        ]));
    }

    public function update(Request $request, string $customerKey): RedirectResponse
    {
        $resolved = $this->resolveCustomer($customerKey);

        abort_if(! $resolved, 404);

        $isRegistered = $resolved['customer']['type'] === 'registered';
        $userId = $isRegistered ? (int) Str::after($customerKey, 'user-') : null;
        $normalizedPhone = Phone::normalize($request->input('phone'));

        $request->merge([
            'email' => $request->filled('email') ? mb_strtolower(trim((string) $request->input('email'))) : null,
            'phone' => $normalizedPhone,
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($userId),
            ],
            'phone' => [
                'required',
                'string',
                'max:32',
                Rule::unique(User::class, 'phone')->ignore($userId),
            ],
            'password' => [
                $isRegistered ? 'nullable' : 'required',
                'confirmed',
                'string',
                'min:8',
                'max:255',
            ],
        ], [
            'password.required' => 'كلمة المرور مطلوبة لتحويل العميل إلى حساب مسجل.',
            'phone.required' => 'رقم الهاتف مطلوب لإنشاء أو تحديث حساب العميل.',
        ]);

        $plainPassword = (string) ($validated['password'] ?? '');

        $user = DB::transaction(function () use ($resolved, $customerKey, $isRegistered, $userId, $validated, $plainPassword): User {
            if ($isRegistered) {
                $user = User::where('role', '!=', 'admin')->findOrFail($userId);
                $user->fill([
                    'name' => $validated['name'],
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'],
                ]);

                if ($plainPassword !== '') {
                    $user->password = $plainPassword;
                }

                $user->save();
            } else {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'],
                    'password' => $plainPassword,
                    'role' => 'customer',
                    'last_seen_at' => null,
                ]);
            }

            $orders = $resolved['orders'];
            $sessionIds = $this->checkoutSessionIds($orders);

            $orders->each(function (Order $order) use ($user, $validated): void {
                $details = $order->delivery_details ?? [];
                $details['phone'] = $validated['phone'];

                $order->update([
                    'user_id' => $user->id,
                    'parent_name' => $validated['name'],
                    'delivery_details' => $details,
                ]);
            });

            if ($sessionIds->isNotEmpty()) {
                CustomerStoryView::whereIn('session_id', $sessionIds)
                    ->whereNull('user_id')
                    ->update(['user_id' => $user->id]);
            }

            return $user;
        });

        $redirect = redirect()
            ->route('admin.customers.show', 'user-' . $user->id)
            ->with('success', $isRegistered ? 'تم تحديث بيانات العميل بنجاح.' : 'تم تحويل العميل إلى حساب مسجل بنجاح.');

        if ($plainPassword !== '') {
            $login = $user->phone ?: $user->email;
            $message = implode("\n", [
                'مرحباً ' . $user->name . '،',
                'تم إنشاء حسابك على HeroKid لمتابعة طلبك.',
                'رابط الدخول: ' . route('login'),
                'بيانات الدخول:',
                'الهاتف/البريد: ' . $login,
                'كلمة المرور: ' . $plainPassword,
            ]);

            $redirect->with('customer_account_message', $message);

            if ($user->phone) {
                $redirect->with('customer_account_whatsapp_url', 'https://wa.me/' . preg_replace('/[^0-9]/', '', $user->phone) . '?text=' . urlencode($message));
            }
        }

        return $redirect;
    }

    private function resolveCustomer(string $customerKey): ?array
    {
        if (str_starts_with($customerKey, 'user-')) {
            $user = User::where('role', '!=', 'admin')->find((int) Str::after($customerKey, 'user-'));

            if (! $user) {
                return null;
            }

            $phone = Phone::normalize($user->phone);
            $orders = $this->ordersForRegisteredUser($user, $phone);
            $sessionIds = $this->checkoutSessionIds($orders);
            $storyViews = CustomerStoryView::with('story')
                ->where(function ($query) use ($user, $sessionIds): void {
                    $query->where('user_id', $user->id);

                    if ($sessionIds->isNotEmpty()) {
                        $query->orWhereIn('session_id', $sessionIds);
                    }
                })
                ->latest('viewed_at')
                ->limit(100)
                ->get();

            return [
                'customer' => $this->registeredCustomerRow($user, $orders, $storyViews),
                'orders' => $orders,
                'storyViews' => $storyViews,
            ];
        }

        $guest = $this->guestCustomerRows()->firstWhere('key', $customerKey);

        if (! $guest) {
            return null;
        }

        $orders = Order::with('story')
            ->whereIn('id', $guest['order_ids'])
            ->latest()
            ->get();
        $sessionIds = $this->checkoutSessionIds($orders);
        $storyViews = CustomerStoryView::with('story')
            ->whereIn('session_id', $sessionIds)
            ->latest('viewed_at')
            ->limit(100)
            ->get();

        return [
            'customer' => array_merge($guest, [
                'last_activity_at' => $this->latestDate([$guest['last_activity_at'], $storyViews->max('viewed_at')]),
            ]),
            'orders' => $orders,
            'storyViews' => $storyViews,
        ];
    }

    private function customerRows(): Collection
    {
        return collect($this->registeredCustomerRows()->all())->merge($this->guestCustomerRows());
    }

    private function registeredCustomerRows(): Collection
    {
        return User::where('role', '!=', 'admin')
            ->withMax('storyViews as last_story_viewed_at', 'viewed_at')
            ->latest()
            ->get()
            ->map(function (User $user): array {
                $orders = $this->ordersForRegisteredUser($user, Phone::normalize($user->phone));

                return $this->registeredCustomerRow($user, $orders);
            });
    }

    private function guestCustomerRows(): Collection
    {
        $registeredPhones = User::whereNotNull('phone')
            ->where('role', '!=', 'admin')
            ->pluck('phone')
            ->map(fn ($phone) => Phone::normalize($phone))
            ->filter()
            ->values();

        return Order::with('story')
            ->whereNull('user_id')
            ->latest()
            ->get()
            ->reject(function (Order $order) use ($registeredPhones): bool {
                $phone = Phone::normalize(data_get($order->delivery_details, 'phone'));

                return $phone && $registeredPhones->contains($phone);
            })
            ->groupBy(function (Order $order): string {
                $phone = Phone::normalize(data_get($order->delivery_details, 'phone'));

                return $phone ?: 'order-' . $order->id;
            })
            ->map(function (Collection $orders, string $groupKey): array {
                $latestOrder = $orders->sortByDesc('created_at')->first();
                $sessionIds = $this->checkoutSessionIds($orders);
                $lastStoryView = $sessionIds->isEmpty()
                    ? null
                    : CustomerStoryView::whereIn('session_id', $sessionIds)->max('viewed_at');
                $phone = Phone::normalize(data_get($latestOrder->delivery_details, 'phone'));

                return [
                    'key' => 'guest-' . sha1($groupKey),
                    'type' => 'guest',
                    'type_label' => 'طلب بدون حساب',
                    'name' => $latestOrder->parent_name ?: 'Not available',
                    'email' => 'Not available',
                    'phone' => $phone ?: 'Not available',
                    'address' => $this->addressFromOrder($latestOrder),
                    'orders_count' => $orders->count(),
                    'stories_viewed_count' => $sessionIds->isEmpty()
                        ? 0
                        : CustomerStoryView::whereIn('session_id', $sessionIds)->distinct('story_id')->count('story_id'),
                    'last_visit_at' => $lastStoryView,
                    'last_order_at' => $latestOrder?->created_at,
                    'last_activity_at' => $this->latestDate([$lastStoryView, $latestOrder?->created_at]),
                    'order_ids' => $orders->pluck('id')->values()->all(),
                ];
            })
            ->values();
    }

    private function registeredCustomerRow(User $user, Collection $orders, ?Collection $storyViews = null): array
    {
        $latestOrder = $orders->first();
        $lastStoryView = $storyViews?->max('viewed_at') ?? $user->last_story_viewed_at;

        return [
            'key' => 'user-' . $user->id,
            'type' => 'registered',
            'type_label' => 'حساب مسجل',
            'name' => $user->name ?: 'Not available',
            'email' => $user->email ?: 'Not available',
            'phone' => $user->phone ?: 'Not available',
            'address' => $latestOrder ? $this->addressFromOrder($latestOrder) : 'Not available',
            'orders_count' => $orders->count(),
            'stories_viewed_count' => $storyViews?->unique('story_id')->count() ?? $user->storyViews()->distinct('story_id')->count('story_id'),
            'last_visit_at' => $user->last_seen_at ?: $lastStoryView,
            'last_order_at' => $latestOrder?->created_at,
            'last_activity_at' => $this->latestDate([$user->last_seen_at, $lastStoryView, $latestOrder?->created_at]),
            'order_ids' => $orders->pluck('id')->values()->all(),
        ];
    }

    private function ordersForRegisteredUser(User $user, ?string $phone): Collection
    {
        return Order::with('story')
            ->where(function ($query) use ($user, $phone): void {
                $query->where('user_id', $user->id);

                if ($phone) {
                    $query->orWhere('delivery_details->phone', $phone);
                }
            })
            ->latest()
            ->get();
    }

    private function checkoutSessionIds(Collection $orders): Collection
    {
        return $orders
            ->pluck('delivery_details')
            ->map(fn ($details) => data_get($details, 'checkout_session_id'))
            ->filter()
            ->unique()
            ->values();
    }

    private function addressFromOrder(?Order $order): string
    {
        if (! $order) {
            return 'Not available';
        }

        $details = $order->delivery_details ?? [];
        $parts = array_filter([
            data_get($details, 'country'),
            data_get($details, 'governorate'),
            data_get($details, 'city'),
            data_get($details, 'street'),
            data_get($details, 'address_details') ?: data_get($details, 'address'),
        ]);

        return $parts === [] ? 'Not available' : implode(' - ', $parts);
    }

    private function latestDate(array $dates): mixed
    {
        return collect($dates)
            ->filter()
            ->map(fn ($date) => $date instanceof \Carbon\CarbonInterface ? $date : \Carbon\Carbon::parse($date))
            ->sortByDesc(fn ($date) => $date->timestamp)
            ->first();
    }
}
