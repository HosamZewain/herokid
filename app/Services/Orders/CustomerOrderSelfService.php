<?php

namespace App\Services\Orders;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\OrderGroupMergeAlias;
use App\Support\OrderStatusRegistry;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerOrderSelfService
{
    private const ACCESS_SESSION_KEY = 'customer_order_access';

    private const ACCESS_TTL_MINUTES = 120;

    public function __construct(
        private readonly AdminOrderGroupService $groups,
        private readonly OrderStatusService $statuses,
        private readonly OrderGroupMergeService $merges,
    ) {}

    /** @return array<string, mixed>|null */
    public function findByCredentials(string $reference, string $phone): ?array
    {
        $groupKey = $this->resolveGroupKey($reference);

        if (! $groupKey) {
            return null;
        }

        $orders = $this->ordersForGroup($groupKey);
        $storedPhone = Phone::forWhatsApp((string) data_get($orders->first()?->delivery_details, 'phone'));

        if (! $storedPhone || ! hash_equals($storedPhone, (string) Phone::forWhatsApp($phone))) {
            return null;
        }

        return $this->present($orders);
    }

    /** @return array<string, mixed> */
    public function authorize(Request $request, array $group): array
    {
        $key = (string) $group['key'];
        $access = (array) $request->session()->get(self::ACCESS_SESSION_KEY, []);
        $access[hash('sha256', $key)] = now()->addMinutes(self::ACCESS_TTL_MINUTES)->timestamp;
        $request->session()->put(self::ACCESS_SESSION_KEY, $access);

        return $group;
    }

    /** @return array<string, mixed> */
    public function authorizedGroup(Request $request, string $reference): array
    {
        $groupKey = $this->resolveGroupKey($reference);
        abort_unless($groupKey && $this->hasAccess($request, $groupKey), 404);

        $orders = $this->ordersForGroup($groupKey);
        abort_if($orders->isEmpty(), 404);

        return $this->present($orders);
    }

    /** @return array<int, array<string, mixed>> */
    public function activeForPhone(string $phone): array
    {
        $values = Phone::equivalentValues($phone);

        if ($values === []) {
            return [];
        }

        $keys = Order::query()
            ->whereIn('delivery_details->phone', $values)
            ->whereNotNull('checkout_group_key')
            ->latest('id')
            ->limit(100)
            ->pluck('checkout_group_key')
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            return [];
        }

        return Order::query()
            ->with($this->relations())
            ->whereIn('checkout_group_key', $keys)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Order $order): string => $order->checkoutGroupKey())
            ->map(fn (Collection $orders): array => $this->present($orders))
            ->filter(fn (array $group): bool => $this->isActive($group['active_orders']))
            ->sortByDesc('created_at')
            ->values()
            ->map(fn (array $group): array => $this->publicSummary($group))
            ->all();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(Request $request, array $group, array $data): array
    {
        $key = (string) $group['key'];

        DB::transaction(function () use ($key, $data): void {
            $orders = Order::query()
                ->with('items')
                ->where('checkout_group_key', $key)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();
            $fullEdit = $this->canEdit($orders);

            if (! $fullEdit && ! $this->canUpdateParentNotes($orders)) {
                throw ValidationException::withMessages([
                    'order' => 'انتهى هذا الطلب، لذلك لا يمكن تحديث ملاحظاته من الموقع.',
                ]);
            }

            if (! $fullEdit) {
                $submittedChildren = collect($data['children'] ?? []);

                foreach ($orders as $order) {
                    $child = $submittedChildren->get((string) $order->id, $submittedChildren->get($order->id));

                    if (! is_array($child) || ! array_key_exists('parent_notes', $child)) {
                        continue;
                    }

                    $before = $order->parent_notes;
                    $notes = $child['parent_notes'] ?: null;

                    if ($before === $notes) {
                        continue;
                    }

                    $order->forceFill(['parent_notes' => $notes])->save();

                    foreach ($order->items as $item) {
                        $snapshot = $item->personalization_snapshot ?? [];
                        $snapshot['parent_notes'] = $notes;
                        $item->forceFill(['personalization_snapshot' => $snapshot])->save();
                    }

                    $order->statusLogs()->create([
                        'status' => $order->status,
                        'notes' => 'قام العميل بتحديث ملاحظات ولي الأمر من صفحة المتابعة بعد بدء التنفيذ.',
                    ]);
                }

                return;
            }

            $country = DeliveryCountry::query()->where('active', true)->findOrFail($data['delivery_country_id']);
            $governorate = DeliveryGovernorate::query()
                ->where('active', true)
                ->where('delivery_country_id', $country->id)
                ->findOrFail($data['delivery_governorate_id']);
            $submittedChildren = collect($data['children'] ?? []);

            foreach ($orders as $order) {
                $delivery = $order->delivery_details ?? [];
                $delivery = array_merge($delivery, [
                    'phone' => $data['phone'],
                    'delivery_country_id' => $country->id,
                    'delivery_governorate_id' => $governorate->id,
                    'country' => $country->name,
                    'governorate' => $governorate->name,
                    'city' => $data['city'],
                    'street' => $data['street'],
                    'address_details' => $data['address_details'],
                    'address' => trim($data['street'].' - '.$data['address_details']),
                ]);
                $changes = [
                    'parent_name' => $data['parent_name'],
                    'delivery_details' => $delivery,
                ];
                $child = $submittedChildren->get((string) $order->id, $submittedChildren->get($order->id));

                if (is_array($child) && $this->hasChildDetails($order)) {
                    $changes = array_merge($changes, [
                        'child_name' => $child['child_name'],
                        'child_age' => $child['child_age'] ?? null,
                        'child_gender' => $child['child_gender'] ?? null,
                        'parent_notes' => $child['parent_notes'] ?? null,
                    ]);
                }

                $order->forceFill($changes)->save();

                if (is_array($child)) {
                    foreach ($order->items as $item) {
                        $snapshot = $item->personalization_snapshot ?? [];
                        foreach (['child_name', 'child_age', 'child_gender', 'parent_notes'] as $field) {
                            if (array_key_exists($field, $child)) {
                                $snapshot[$field] = $child[$field];
                            }
                        }
                        $item->forceFill(['personalization_snapshot' => $snapshot])->save();
                    }
                }

                $order->statusLogs()->create([
                    'status' => $order->status,
                    'notes' => 'قام العميل بتحديث بيانات الطلب من صفحة المتابعة.',
                ]);
            }
        });

        $updated = $this->present($this->ordersForGroup($key));
        $this->authorize($request, $updated);

        return $updated;
    }

    /** @return array<string, mixed> */
    public function cancel(Request $request, array $group): array
    {
        $orders = $group['active_orders'];
        $this->assertCancellable($orders);
        $this->statuses->updateGroup(
            $orders,
            $this->cancelledStatus(),
            'طلب العميل إلغاء عملية الشراء من صفحة المتابعة.',
            $request,
        );

        return $this->present($this->ordersForGroup((string) $group['key']));
    }

    public function applyCheckoutDecision(
        Request $request,
        Order $newOrder,
        ?string $action,
        ?string $previousReference,
        string $phone,
    ): ?string {
        if (! in_array($action, ['cancel_previous', 'merge'], true)) {
            return null;
        }

        $previous = $previousReference ? $this->findByCredentials($previousReference, $phone) : null;

        if (! $previous || $previous['key'] === $newOrder->checkoutGroupKey()) {
            throw ValidationException::withMessages([
                'previous_order_action' => 'تعذر التحقق من الطلب السابق. أعد المحاولة أو تابع بالطلبين منفصلين.',
            ]);
        }

        if ($action === 'cancel_previous') {
            $this->cancel($request, $previous);

            return 'تم الاحتفاظ بالطلب الجديد وإلغاء الطلب السابق بناءً على اختيارك.';
        }

        $this->assertMergeable($previous['active_orders']);
        $this->merges->merge(
            $newOrder,
            (string) $previous['short_reference'],
            'دمج اختاره العميل أثناء إنشاء طلب جديد من الموقع.',
            $request->user(),
            $request,
        );

        return 'تم دمج الطلبين واحتساب مصاريف توصيل واحدة.';
    }

    /** @return array<string, mixed> */
    private function present(Collection $orders): array
    {
        $group = $this->groups->present($orders);
        $group['can_edit'] = $this->canEdit($group['active_orders']);
        $group['can_update_parent_notes'] = $this->canUpdateParentNotes($group['active_orders']);
        $group['can_cancel'] = $this->canCancel($group['active_orders']);
        $group['can_merge'] = $this->canMerge($group['active_orders']);

        return $group;
    }

    /** @return array<string, mixed> */
    private function publicSummary(array $group): array
    {
        return [
            'reference' => $group['short_reference'],
            'status' => $group['status'],
            'status_label' => $group['status_label'],
            'created_at' => app_datetime($group['created_at'], 'd/m/Y'),
            'can_cancel' => $group['can_cancel'],
            'can_merge' => $group['can_merge'],
        ];
    }

    private function resolveGroupKey(string $reference): ?string
    {
        $reference = strtoupper(trim($reference));
        $alias = OrderGroupMergeAlias::query()
            ->where('source_short_reference', $reference)
            ->orWhere('source_checkout_group_key', $reference)
            ->value('target_checkout_group_key');

        if ($alias) {
            return (string) $alias;
        }

        $order = Order::query()
            ->where('order_number', $reference)
            ->orWhereHas('checkoutReference', fn ($query) => $query->where('short_reference', $reference))
            ->first();

        return $order?->checkoutGroupKey();
    }

    private function hasAccess(Request $request, string $groupKey): bool
    {
        $access = (array) $request->session()->get(self::ACCESS_SESSION_KEY, []);
        $expiresAt = (int) ($access[hash('sha256', $groupKey)] ?? 0);

        return $expiresAt >= now()->timestamp;
    }

    /** @return Collection<int, Order> */
    private function ordersForGroup(string $key): Collection
    {
        return Order::query()
            ->with($this->relations())
            ->where('checkout_group_key', $key)
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'checkoutReference',
            'story:id,title,price',
            'items.product:id,name_ar,personalization_mode,personalization_fields',
            'items.variant:id,product_id,name_ar',
            'statusLogs',
        ];
    }

    private function isActive(Collection $orders): bool
    {
        return $orders->isNotEmpty()
            && $orders->contains(fn (Order $order): bool => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $order->status) !== 'cancelled'
                && OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $order->status) !== 'delivered'
                && ! in_array(OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_SHIPPING, $order->shipping_status), ['delivered', 'returned', 'cancelled'], true));
    }

    private function canEdit(Collection $orders): bool
    {
        return $orders->isNotEmpty() && $orders->every(fn (Order $order): bool => in_array($order->status, ['new', 'under_review'], true)
            && in_array(OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_PRINTING, $order->printing_status), [null, 'not_started', 'not_required'], true)
            && in_array(OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_SHIPPING, $order->shipping_status), [null, 'not_ready', 'not_required'], true)
            && ! $order->bostaShipments()->exists());
    }

    private function canCancel(Collection $orders): bool
    {
        return $this->canEdit($orders)
            && (int) ($orders->first()?->paid_amount_cents ?? 0) === 0;
    }

    private function canUpdateParentNotes(Collection $orders): bool
    {
        return $this->isActive($orders);
    }

    private function canMerge(Collection $orders): bool
    {
        return $this->canEdit($orders);
    }

    private function assertEditable(Collection $orders): void
    {
        if (! $this->canEdit($orders)) {
            throw ValidationException::withMessages([
                'order' => 'بدأ تنفيذ هذا الطلب بالفعل، لذلك لا يمكن تعديله من الموقع. تواصل مع فريق HeroKid للمساعدة.',
            ]);
        }
    }

    private function assertCancellable(Collection $orders): void
    {
        if (! $this->canCancel($orders)) {
            throw ValidationException::withMessages([
                'order' => 'لا يمكن إلغاء هذا الطلب تلقائيًا بعد بدء التنفيذ أو تسجيل دفعة. تواصل مع فريق HeroKid للمساعدة.',
            ]);
        }
    }

    private function assertMergeable(Collection $orders): void
    {
        if (! $this->canMerge($orders)) {
            throw ValidationException::withMessages([
                'previous_order_action' => 'لا يمكن دمج الطلب السابق بعد بدء تنفيذه أو شحنه. اختر المتابعة بطلبين منفصلين.',
            ]);
        }
    }

    private function cancelledStatus(): string
    {
        $status = collect(OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_ORDER, 'cancelled', true))->first();

        if (! $status) {
            throw ValidationException::withMessages(['order' => 'تعذر إلغاء الطلب حاليًا. تواصل مع فريق HeroKid.']);
        }

        return $status;
    }

    private function hasChildDetails(Order $order): bool
    {
        return filled($order->child_name)
            || $order->story_id !== null
            || $order->items->contains(fn ($item): bool => $item->personalization_mode === 'collect_child_details');
    }
}
