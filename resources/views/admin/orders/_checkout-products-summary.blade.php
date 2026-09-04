@if(($group['direct_products'] ?? collect())->isNotEmpty())
    <section class="rounded-2xl border border-emerald-100 bg-white p-5 shadow-sm" data-checkout-products-summary>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700">
                {{ $group['direct_products']->sum('quantity') }} منتج
            </span>
            <div class="text-right">
                <h3 class="text-lg font-black text-gray-900">المنتجات الموجودة في عملية الشراء</h3>
                <p class="mt-1 text-xs font-bold text-gray-500">هذه المنتجات جزء من نفس الطلب حتى أثناء العمل على إنتاج القصة.</p>
            </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($group['direct_products'] as $product)
                @php
                    $productOrder = $group['active_orders']->firstWhere('id', (int) $product->order_id)
                        ?: $group['orders']->firstWhere('id', (int) $product->order_id);
                    $productPhotos = $productOrder?->uploaded_photos ?? [];
                @endphp
                <article class="flex h-full flex-col rounded-2xl border border-emerald-100 bg-emerald-50/70 p-3">
                    @if(data_get($product->item_snapshot, 'package.name'))
                        <p class="mb-1.5 text-[10px] font-black text-fuchsia-700">ضمن باقة: {{ data_get($product->item_snapshot, 'package.name') }}</p>
                    @endif
                    <h4 class="text-sm font-black leading-6 text-gray-900">{{ $product->title }}</h4>
                    @if($product->sku)
                        <p class="mt-1 text-[10px] text-gray-400" dir="ltr">SKU: {{ $product->sku }}</p>
                    @endif
                    <p class="mt-2 text-xs font-bold text-emerald-800">{{ $product->quantity }} × {{ format_money($product->unit_price_cents / 100) }}</p>

                    @if($product->personalization_mode === 'collect_child_details')
                        <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 border-t border-emerald-100 pt-3">
                            @foreach($product->personalizationDisplayValues() as $value)
                                <div class="min-w-0">
                                    <dt class="text-[10px] font-bold text-emerald-600">{{ $value['label'] }}</dt>
                                    <dd class="break-words text-xs font-black leading-5 text-gray-900">{{ $value['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @can('orders.photos.view')
                        @if($productOrder && count($productPhotos))
                            <div class="mt-3 border-t border-emerald-100 pt-3">
                                <p class="mb-2 text-[10px] font-black text-emerald-700">صور الطفل المرفقة</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach(array_slice($productPhotos, 0, 4) as $photo)
                                        <a href="{{ route('admin.orders.photo', [$productOrder, $loop->index]) }}" target="_blank" rel="noopener" class="block h-12 w-12 overflow-hidden rounded-lg border-2 border-white bg-white shadow-sm">
                                            <img src="{{ route('admin.orders.photo', [$productOrder, $loop->index]) }}" alt="صورة {{ $loop->iteration }} للمنتج {{ $product->title }}" class="h-full w-full object-cover" loading="lazy">
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endcan

                    @can('orders.production_prompt.manage')
                        @if($productOrder && \App\Support\ProductProductionPrompt::templateForItem($product) !== null)
                            <a href="{{ route('admin.orders.products.production', [$productOrder, $product]) }}" class="mt-3 inline-flex min-h-9 w-full items-center justify-center rounded-xl bg-fuchsia-600 px-3 py-2 text-xs font-black text-white transition hover:bg-fuchsia-700">
                                فتح إنتاج المنتج
                            </a>
                        @endif
                    @endcan
                </article>
            @endforeach
        </div>
    </section>
@endif
