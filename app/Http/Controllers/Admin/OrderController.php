<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCountry;
use App\Models\Order;
use App\Models\OrderPreview;
use App\Models\Product;
use App\Models\Story;
use App\Services\Orders\AdminOrderCreationService;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\AdminPackageOrderService;
use App\Services\Orders\OrderActivityTimelineService;
use App\Services\Orders\OrderAdminNoteService;
use App\Services\Orders\OrderChildIdentityPromptService;
use App\Services\Orders\OrderDeletionService;
use App\Services\Orders\OrderDetailsUpdateService;
use App\Services\Orders\OrderPaymentLedgerService;
use App\Services\Orders\OrderSceneTextService;
use App\Services\Orders\OrderStatusService;
use App\Services\Orders\OrderWhatsAppMessageService;
use App\Services\Pricing\StoryPricingService;
use App\Services\Uploads\OrderPhotoUploadService;
use App\Support\AdminActivityLogger;
use App\Support\OrderDateTime;
use App\Support\OrderPaymentStatus;
use App\Support\OrderSource;
use App\Support\Phone;
use App\Support\ProductPersonalizationSchema;
use App\Support\ProductProductionPrompt;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function index(Request $request, AdminOrderGroupService $groups, OrderWhatsAppMessageService $whatsappMessages)
    {
        $result = $groups->paginate(
            $request,
            $request->user()->hasPermission('orders.statistics.view'),
        );
        $result['groups']->setCollection(
            $result['groups']->getCollection()->map(function (array $group) use ($whatsappMessages): array {
                $group['whatsapp_messages'] = $whatsappMessages->messagesForGroup($group);

                return $group;
            })
        );

        return view('admin.orders.index', $result);
    }

    public function export(Request $request, AdminOrderGroupService $groups): StreamedResponse
    {
        $rows = $groups->export($request);

        AdminActivityLogger::log(
            action: 'orders.exported',
            description: 'تم تصدير جدول الطلبات المطابق للفلاتر الحالية.',
            properties: [
                'row_count' => $rows->count(),
                'view' => $request->query('view', 'current'),
                'catalog_type' => $request->query('catalog_type', 'stories'),
                'lifecycle' => $request->query('lifecycle', 'active'),
                'status' => $request->query('status'),
                'payment_status' => $request->query('payment_status'),
                'printing_status' => $request->query('printing_status'),
                'shipping_status' => $request->query('shipping_status'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'assignment' => $request->query('assignment'),
                'has_search' => $request->filled('q'),
            ],
            request: $request,
        );

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'تاريخ الطلب', 'وقت الطلب', 'المرجع المختصر', 'عملية الشراء', 'أرقام الطلبات', 'المصدر',
                'اسم العميل', 'الهاتف', 'أسماء الأطفال', 'القصص', 'المنتجات', 'الإضافات',
                'عدد القصص', 'عدد المنتجات', 'عدد الإضافات', 'حالة الطلب', 'حالة الدفع',
                'حالة الطباعة', 'حالة الشحن', 'قيمة العناصر', 'التوصيل', 'الخصم', 'الإجمالي',
                'المدفوع', 'المتبقي', 'طريقة الدفع', 'الدولة', 'المحافظة', 'المدينة',
                'الشارع', 'تفاصيل العنوان', 'مسؤول الطلب', 'ملاحظات المصدر',
            ]);

            foreach ($rows as $row) {
                $delivery = $row['delivery'];
                $orderDate = OrderDateTime::display($row['latest_at']);
                fputcsv($output, array_map($this->csvCell(...), [
                    $orderDate?->format('Y-m-d'),
                    $orderDate?->format('H:i:s'),
                    $row['short_reference'],
                    $row['key'],
                    implode('، ', $row['order_numbers']),
                    OrderSource::label($row['order_source']),
                    $row['customer_name'],
                    $row['phone'],
                    implode('، ', $row['child_names']),
                    implode('، ', $row['story_titles']),
                    implode('، ', $row['product_titles']),
                    implode('، ', $row['add_on_titles']),
                    $row['story_count'],
                    $row['product_quantity'],
                    $row['add_on_quantity'],
                    $row['status_label'],
                    $row['payment_status_label'],
                    $row['printing_status_label'],
                    $row['shipping_status_label'],
                    number_format($row['items_cents'] / 100, 2, '.', ''),
                    number_format($row['delivery_cents'] / 100, 2, '.', ''),
                    number_format($row['discount_cents'] / 100, 2, '.', ''),
                    number_format($row['total_cents'] / 100, 2, '.', ''),
                    number_format($row['paid_amount_cents'] / 100, 2, '.', ''),
                    number_format($row['remaining_amount_cents'] / 100, 2, '.', ''),
                    $row['payment_method'],
                    data_get($delivery, 'country'),
                    data_get($delivery, 'governorate'),
                    data_get($delivery, 'city'),
                    data_get($delivery, 'street'),
                    data_get($delivery, 'address_details', data_get($delivery, 'address')),
                    $row['assigned_admin']?->name,
                    $row['source_notes'],
                ]));
            }

            fclose($output);
        }, 'herokid-orders-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function create(StoryPricingService $storyPricing, AdminPackageOrderService $packages)
    {
        $stories = Story::query()->where('active', true)->orderBy('title')->get();

        return view('admin.orders.create', [
            'stories' => $stories,
            'storyPrices' => $stories->mapWithKeys(fn (Story $story): array => [
                $story->id => $storyPricing->snapshot($story),
            ]),
            'products' => Product::query()
                ->with(['activeVariants'])
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name_ar')
                ->get(),
            'countries' => DeliveryCountry::query()
                ->with('activeGovernorates')
                ->where('active', true)
                ->orderBy('name')
                ->get(),
            'sourceOptions' => OrderSource::manualOptions(),
            'paymentStatuses' => OrderPaymentStatus::labels(),
            'paymentMethods' => OrderPaymentStatus::paymentMethods(),
            'pricingPackages' => $packages->availablePackages(),
        ]);
    }

    private function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    public function store(
        Request $request,
        AdminOrderCreationService $creator,
        AdminPackageOrderService $packages,
    ) {
        $pricingPackage = $packages->prepareRequest($request);

        $request->merge([
            'phone' => Phone::normalize($request->input('phone')),
            'payment_status' => $request->input('payment_status', OrderPaymentStatus::UNPAID),
            'stories' => $request->input('stories', []),
        ]);

        $validated = $request->validate([
            'parent_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'order_source' => ['required', Rule::in(array_keys(OrderSource::manualOptions()))],
            'source_notes' => ['nullable', 'string', 'max:500'],
            'delivery_country_id' => [
                'required',
                Rule::exists('delivery_countries', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'delivery_governorate_id' => [
                'required',
                Rule::exists('delivery_governorates', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'city' => ['required', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'address_details' => ['required', 'string', 'max:1000'],
            'stories' => ['array', 'max:10'],
            'stories.*.story_id' => [
                'required',
                Rule::exists('stories', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'stories.*.child_name' => ['required', 'string', 'max:100'],
            'stories.*.child_age' => ['required', 'integer', 'min:3', 'max:12'],
            'stories.*.child_gender' => ['required', Rule::in(['boy', 'girl'])],
            'stories.*.interests' => ['nullable', 'string', 'max:1000'],
            'stories.*.gift_note' => ['nullable', 'string', 'max:1000'],
            'stories.*.parent_notes' => ['nullable', 'string', 'max:2000'],
            'stories.*.photos' => ['required', 'array', 'min:2', 'max:3'],
            'stories.*.photos.*' => ['required', 'file', 'max:'.((int) config('photo_uploads.max_size_mb', 15) * 1024)],
            'products' => ['nullable', 'array'],
            'products.*.quantity' => ['nullable', 'integer', 'min:0', 'max:99'],
            'products.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'products.*.linked_story_index' => ['nullable', 'integer', 'min:0', 'max:9'],
            'products.*.personalization' => ['nullable', 'array'],
            'products.*.units' => ['nullable', 'array', 'max:10'],
            'products.*.units.*.reuse_first' => ['nullable', 'boolean'],
            'products.*.units.*.personalization' => ['nullable', 'array'],
            'pricing_package_id' => ['nullable', 'integer', 'exists:pricing_packages,id'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'discount_reason' => ['nullable', 'string', 'max:500'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
            'payment_status' => ['required', Rule::in(OrderPaymentStatus::statuses())],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'payment_method' => ['nullable', Rule::in(OrderPaymentStatus::paymentMethods())],
        ], [
            'parent_name.required' => 'اكتب اسم ولي الأمر.',
            'phone.required' => 'اكتب رقم الهاتف أو واتساب.',
            'order_source.required' => 'اختر مصدر الطلب.',
            'delivery_country_id.required' => 'اختر الدولة.',
            'delivery_governorate_id.required' => 'اختر المحافظة.',
            'city.required' => 'اكتب المدينة أو المنطقة.',
            'street.required' => 'اكتب اسم الشارع.',
            'address_details.required' => 'اكتب تفاصيل عنوان التوصيل.',
            'stories.*.story_id.required' => 'اختر القصة.',
            'stories.*.child_name.required' => 'اكتب اسم الطفل لكل قصة.',
            'stories.*.child_age.required' => 'اختر عمر الطفل لكل قصة.',
            'stories.*.child_gender.required' => 'اختر جنس الطفل لكل قصة.',
            'stories.*.photos.required' => 'ارفع صورتين أو 3 صور للطفل لكل قصة.',
            'stories.*.photos.min' => 'يجب رفع صورتين على الأقل للطفل لكل قصة.',
            'stories.*.photos.max' => 'الحد الأقصى 3 صور للطفل لكل قصة.',
            'pricing_package_id.exists' => 'الباقة المختارة غير موجودة.',
        ]);

        $selectedProducts = collect($validated['products'] ?? [])
            ->filter(fn (array $product): bool => (int) ($product['quantity'] ?? 0) > 0);

        if ($validated['stories'] === [] && $selectedProducts->isEmpty()) {
            throw ValidationException::withMessages([
                'stories' => 'أضف قصة أو منتجًا واحدًا على الأقل إلى الطلب.',
            ]);
        }

        $products = Product::query()
            ->where('is_active', true)
            ->whereIn('id', $selectedProducts->keys()->map(fn (string|int $id): int => (int) $id))
            ->get()
            ->keyBy('id');

        foreach ($selectedProducts as $productId => $productInput) {
            $product = $products->get((int) $productId);

            if (! $product || $product->personalization_mode !== 'collect_child_details') {
                continue;
            }

            $schema = ProductPersonalizationSchema::forProduct($product);
            $quantity = (int) ($productInput['quantity'] ?? 1);
            if ($quantity > 10) {
                throw ValidationException::withMessages(["products.$productId.quantity" => 'الحد الأقصى للمنتج المخصص هو ١٠ أطفال في الطلب.']);
            }
            $hasUnits = is_array($productInput['units'] ?? null);
            $rawUnits = (array) $request->input("products.$productId.units", []);
            if ($hasUnits) {
                $submittedUnits = $productInput['units'];
                ksort($submittedUnits, SORT_NUMERIC);
                $submittedUnits = array_values($submittedUnits);
            } else {
                $submittedUnits = [['personalization' => (array) ($productInput['personalization'] ?? [])]];
            }
            $validatedUnits = [];

            for ($unitIndex = 0; $unitIndex < $quantity; $unitIndex++) {
                $unit = (array) ($submittedUnits[$unitIndex] ?? []);
                $rawUnit = (array) ($rawUnits[$unitIndex] ?? []);
                $reuseFirst = filter_var($unit['reuse_first'] ?? false, FILTER_VALIDATE_BOOL)
                    || filter_var($rawUnit['reuse_first'] ?? false, FILTER_VALIDATE_BOOL);
                if ($unitIndex > 0 && $reuseFirst) {
                    $validatedUnits[] = ['personalization' => $validatedUnits[0]['personalization'], 'reuse_first' => true];

                    continue;
                }

                $personalizationInput = (array) ($unit['personalization'] ?? []);
                $personalizationInput['photos'] = $request->file(
                    $hasUnits ? "products.$productId.units.$unitIndex.personalization.photos" : "products.$productId.personalization.photos",
                    [],
                );

                try {
                    $personalization = Validator::make(
                        $personalizationInput,
                        ProductPersonalizationSchema::adminOrderValidationRules($schema),
                        ProductPersonalizationSchema::adminOrderValidationMessages($schema),
                    )->validate();
                } catch (ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $prefix = $hasUnits
                            ? "products.$productId.units.$unitIndex.personalization"
                            : "products.$productId.personalization";
                        $errors[$prefix.'.'.$field] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }

                $validatedUnits[] = ['personalization' => $personalization, 'reuse_first' => false];
            }

            $validated['products'][$productId]['units'] = $validatedUnits;
            $validated['products'][$productId]['personalization_schema'] = $schema;
        }

        $validated = $packages->applyPackage($validated, $pricingPackage);

        if ((float) ($validated['discount_amount'] ?? 0) > 0 && blank($validated['discount_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'discount_reason' => 'اكتب سبب الخصم لحفظه مع الطلب.',
            ]);
        }

        $result = $creator->create($validated, $request->user(), $request);

        return redirect()
            ->route('admin.orders.groups.show', $result['representative']->id)
            ->with('success', 'تم إنشاء الطلب بنجاح وإضافته إلى الطلبات الجديدة.');
    }

    public function show(
        Order $order,
        AdminOrderGroupService $groups,
        OrderSceneTextService $sceneTexts,
        OrderChildIdentityPromptService $identityPrompts,
        OrderWhatsAppMessageService $whatsapp,
        OrderAdminNoteService $adminNotes,
        OrderActivityTimelineService $activityTimeline,
        OrderPaymentLedgerService $paymentLedger,
    ) {
        $order->load([
            'user',
            'story.sceneTemplates',
            'sceneTextSnapshots',
            'statusLogs',
            'previews',
            'attachments.uploader:id,name',
            'bookletPreview.currentVersion',
            'bookletPreview.versions.uploader',
            'items.product',
            'items.variant',
            'items.linkedAddOns.product',
            'productionPromptOverride.editor',
            'productionPromptSnapshots.creator',
            'childIdentityPromptOverride.editor',
            'childIdentityPromptSnapshots.creator',
            'productionProject.assignedTo',
            'productionProject.scenes',
            'childIdentityRequest.photos',
            'childIdentityRequest.attempts',
            'childIdentityApprovedAttempt',
        ]);
        $storyProductionPrompt = null;
        $globalStoryProductionPrompt = null;
        $productionPromptTemplateSetting = null;
        $childIdentityPrompt = null;
        $productProductionPrompts = collect();

        if (auth()->user()->hasPermission('orders.production_prompt.manage')) {
            $storyProductionPrompt = StoryProductionPrompt::forOrder($order);
            $globalStoryProductionPrompt = StoryProductionPrompt::forOrder($order, useOverride: false);
            $productionPromptTemplateSetting = StoryProductionPrompt::templateSetting();
            $childIdentityPrompt = $identityPrompts->forOrder($order);
            $productProductionPrompts = ProductProductionPrompt::forOrder($order);
        }

        AdminActivityLogger::log(
            action: 'order.viewed',
            description: 'عرض تفاصيل الطلب: '.$order->order_number,
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'story_title' => $order->story?->title,
            ],
            request: request(),
        );

        $checkoutGroup = $groups->findByRepresentative($order->id);
        $whatsappMessages = $whatsapp->messagesForGroup($checkoutGroup);
        $orderAdminNotes = $adminNotes->notesFor($order);
        $sceneTextHandoff = $order->story ? $sceneTexts->present($order) : null;
        $orderActivity = $activityTimeline->forGroup($checkoutGroup);
        $paymentEvents = $paymentLedger->forCheckout($checkoutGroup['key']);

        return view('admin.orders.show', compact(
            'order',
            'checkoutGroup',
            'storyProductionPrompt',
            'globalStoryProductionPrompt',
            'productionPromptTemplateSetting',
            'childIdentityPrompt',
            'productProductionPrompts',
            'sceneTextHandoff',
            'whatsappMessages',
            'orderAdminNotes',
            'orderActivity',
            'paymentEvents',
        ));
    }

    public function update(Request $request, Order $order, OrderStatusService $statuses)
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(OrderStatusService::statuses(false))],
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $statuses->update($order, $validated['status'], $validated['admin_notes'] ?? null, $request);

        return redirect()->route('admin.orders.show', $order)->with('success', 'تم تحديث الطلب بنجاح!');
    }

    public function updateDetails(Request $request, Order $order, OrderDetailsUpdateService $details)
    {
        $storyRules = $order->story_id
            ? ['child_name' => 'required|string|max:100', 'child_age' => 'required|integer|min:1|max:18', 'child_gender' => 'required|in:boy,girl']
            : ['child_name' => 'nullable|string|max:100', 'child_age' => 'nullable|integer|min:1|max:18', 'child_gender' => 'nullable|in:boy,girl'];

        $validated = $request->validate([
            'parent_name' => 'required|string|max:150',
            'phone' => 'required|string|max:30',
            ...$storyRules,
            'language' => 'nullable|in:ar,en',
            'lesson' => 'nullable|string|max:500',
            'interests' => 'nullable|string|max:1000',
            'gift_note' => 'nullable|string|max:1000',
            'parent_notes' => 'nullable|string|max:2000',
            'change_reason' => 'required|string|min:5|max:500',
        ], [
            'parent_name.required' => 'اكتب اسم ولي الأمر.',
            'phone.required' => 'اكتب رقم الهاتف أو واتساب.',
            'child_name.required' => 'اكتب اسم الطفل.',
            'child_age.required' => 'اكتب عمر الطفل.',
            'child_age.integer' => 'عمر الطفل يجب أن يكون رقمًا صحيحًا.',
            'child_age.min' => 'عمر الطفل يجب ألا يقل عن سنة.',
            'child_age.max' => 'عمر الطفل يجب ألا يزيد عن 18 سنة.',
            'child_gender.required' => 'اختر جنس الطفل.',
            'change_reason.required' => 'اكتب سبب تعديل بيانات الطلب لحفظه في سجل النشاط.',
            'change_reason.min' => 'سبب التعديل يجب ألا يقل عن 5 أحرف.',
        ]);

        $result = $details->update($order, $validated, $request->user(), $request);
        $message = 'تم تحديث بيانات الطلب ومزامنتها مع نصوص المشاهد وبرومبت الإنتاج.';

        if ($result['production_requires_review']) {
            $message .= ' مشروع Production Studio معلّم الآن للمراجعة حتى لا تُفقد التعديلات اليدوية أو تُستخدم أصول قديمة بالخطأ.';
        }

        return redirect()->route('admin.orders.show', $order)->with('success', $message);
    }

    public function destroy(Request $request, Order $order, OrderDeletionService $deletions)
    {
        $validated = $request->validate([
            'deletion_reason' => 'required|string|min:5|max:1000',
            'confirmation' => 'required|string',
        ]);

        if (! hash_equals($order->order_number, trim($validated['confirmation']))) {
            throw ValidationException::withMessages(['confirmation' => 'اكتب رقم الطلب كما هو لتأكيد الحذف.']);
        }

        $deletions->deleteOrder($order, $validated['deletion_reason'], $request->user(), $request);

        return redirect()->route('admin.orders.groups.show', $order->id)->with('success', 'تم نقل القصة/الطلب إلى سلة المحذوفات مع الاحتفاظ بكل البيانات.');
    }

    public function restore(Request $request, int $order, OrderDeletionService $deletions)
    {
        $trashed = Order::onlyTrashed()->findOrFail($order);
        $deletions->restoreOrder($trashed, $request->user(), $request);

        return redirect()->route('admin.orders.groups.show', $trashed->id)->with('success', 'تمت استعادة القصة/الطلب بنجاح.');
    }

    /**
     * Upload a preview file for the order and notify customer.
     */
    public function uploadPreview(Request $request, Order $order)
    {
        $request->validate([
            'preview_file' => 'required|file|mimes:jpeg,png,jpg,pdf|max:10240',
            'preview_note' => 'nullable|string|max:1000',
        ]);

        $oldStatus = $order->status;
        $path = $request->file('preview_file')->store('orders/previews/'.$order->id, 'local');

        $preview = OrderPreview::create([
            'order_id' => $order->id,
            'file_path' => $path,
            'note' => $request->preview_note,
            'uploaded_by' => auth()->id(),
        ]);

        // Update order status to preview_uploaded
        $order->update(['status' => 'preview_uploaded']);
        $order->statusLogs()->create([
            'status' => 'preview_uploaded',
            'notes' => 'تم رفع التصميم الأولي وإرساله للعميل للموافقة.',
        ]);

        AdminActivityLogger::log(
            action: 'order.preview_uploaded',
            description: 'رفع تصميم معاينة للطلب: '.$order->order_number,
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'preview_id' => $preview->id,
                'preview_file' => [
                    'path' => $path,
                    'original_name' => $request->file('preview_file')?->getClientOriginalName(),
                    'mime_type' => $request->file('preview_file')?->getClientMimeType(),
                    'size' => $request->file('preview_file')?->getSize(),
                ],
                'status' => [
                    'old' => $oldStatus,
                    'new' => 'preview_uploaded',
                ],
            ],
            request: $request,
        );

        return redirect()->route('admin.orders.show', $order)->with('success', 'تم رفع التصميم وتحديث حالة الطلب إلى "في انتظار موافقة العميل".');
    }

    /**
     * Append supplemental child photos supplied after the order was placed.
     */
    public function uploadPhotos(Request $request, Order $order, OrderPhotoUploadService $photoUploads)
    {
        $maximum = (int) config('photo_uploads.admin_max_files', 10);
        $validated = $request->validate([
            'photos' => 'required|array|min:1|max:'.$maximum,
            'photos.*' => 'required|file|max:'.((int) config('photo_uploads.max_size_mb', 15) * 1024),
        ], [
            'photos.required' => 'اختر صورة واحدة واضحة على الأقل لإضافتها إلى الطلب.',
            'photos.array' => 'تعذر قراءة الصور المرفوعة.',
            'photos.min' => 'اختر صورة واحدة واضحة على الأقل لإضافتها إلى الطلب.',
            'photos.max' => 'يمكن رفع '.$maximum.' صور كحد أقصى في المرة الواحدة.',
            'photos.*.file' => 'تعذر قراءة إحدى الصور المرفوعة.',
            'photos.*.max' => 'حجم كل صورة يجب ألا يزيد عن '.config('photo_uploads.max_size_mb', 15).' ميجا.',
        ]);

        $result = $photoUploads->append($order, $validated['photos']);

        AdminActivityLogger::log(
            action: 'order.child_photos_added',
            description: 'إضافة صور جديدة للطفل إلى الطلب: '.$order->order_number,
            subject: $order,
            properties: [
                'order_number' => $order->order_number,
                'added_count' => $result['added_count'],
                'total_count' => $result['total_count'],
                'files' => $result['files'],
                'production_project_id' => $order->productionProject?->id,
            ],
            request: $request,
        );

        return redirect()
            ->route('admin.orders.show', $order)
            ->with('success', 'تمت إضافة '.$result['added_count'].' صورة جديدة. برومبت الإنتاج يعرض الآن جميع صور الطفل وعددها '.$result['total_count'].'.');
    }

    /**
     * Serve a private child photo from local storage (admin only).
     */
    public function servePhoto(Order $order, int $index)
    {
        return $this->photoResponse($order, $index);
    }

    /**
     * Serve a signed child photo URL for production prompts.
     */
    public function serveProductionPhoto(Order $order, int $index)
    {
        return $this->photoResponse($order, $index);
    }

    public function serveApprovedChildIdentity(Order $order)
    {
        $attempt = $order->childIdentityApprovedAttempt;

        abort_unless(
            $attempt
            && $attempt->status === 'succeeded'
            && filled($attempt->output_storage_path)
            && ! str_contains($attempt->output_storage_path, '..'),
            404,
        );
        $disk = Storage::disk($attempt->output_disk ?: 'local');
        abort_unless($disk->exists($attempt->output_storage_path), 404);

        return response()->file($disk->path($attempt->output_storage_path), [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function photoResponse(Order $order, int $index)
    {
        $photos = $order->uploaded_photos ?? [];

        if (! isset($photos[$index])) {
            abort(404);
        }

        $photoPath = $photos[$index];

        if (! is_string($photoPath) || str_contains($photoPath, '..')) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if ($disk->exists($photoPath)) {
            return response()->file($disk->path($photoPath), [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        }

        $publicDisk = Storage::disk('public');
        if ($publicDisk->exists($photoPath)) {
            return response()->file($publicDisk->path($photoPath), [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        }

        // Backward compatibility for files saved before Laravel's local disk moved to storage/app/private.
        $legacyPath = storage_path('app/'.ltrim($photoPath, '/'));
        if (file_exists($legacyPath) && is_file($legacyPath)) {
            return response()->file($legacyPath, [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        }

        abort(404);
    }

    // Stub for resource controller compliance
    public function edit(string $id) {}
}
