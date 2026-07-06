<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">استوديو الإنتاج #{{ $project->id }}</h2>
    </x-slot>

    @php
        $statusClass = match ($project->status) {
            'completed', 'ready_for_print', 'approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'cancelled' => 'bg-red-50 text-red-700 border-red-200',
            'archived' => 'bg-gray-100 text-gray-700 border-gray-200',
            default => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        };
        $photos = $order->uploaded_photos ?? [];
        $profile = $project->characterProfile;
        $snapshot = $project->source_snapshot_json ?? [];
    @endphp

    <div class="space-y-6" dir="rtl">
        <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-5 text-right">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <p class="text-sm font-black text-indigo-700">Production Studio</p>
                    <h1 class="mt-1 text-2xl font-black text-gray-950">مشروع إنتاج معزول للطلب {{ $order->order_number }}</h1>
                    <p class="mt-2 text-sm leading-7 text-indigo-900">
                        هذا استوديو إنتاج داخلي اختياري. مسار الطلب الأصلي وحالته وبرومبت الإنتاج الحالي لا يتغيرون من هنا.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.orders.show', $order) }}" class="rounded-xl border border-indigo-200 bg-white px-4 py-2 text-sm font-black text-indigo-700 hover:bg-indigo-50">فتح الطلب الأصلي</a>
                    <a href="{{ route('admin.production-studio.index') }}" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white hover:bg-indigo-700">كل مشاريع الاستوديو</a>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 rounded-2xl border border-gray-100 bg-white p-3 text-sm font-black shadow-sm">
            <a href="#overview" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">نظرة عامة</a>
            <a href="#reference" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">بيانات الطلب والطفل</a>
            <a href="#story" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">مساحة القصة</a>
            <a href="#character" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">ملف الشخصية</a>
            <a href="#scenes" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">المشاهد</a>
            <a href="#images" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">إنتاج الصور</a>
            <a href="#layout" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">الإخراج والطباعة</a>
            <a href="#qa" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">مراجعة الجودة</a>
            <a href="#activity" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">سجل النشاط</a>
        </div>

        <section id="overview" class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <div class="xl:col-span-2 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-gray-100 pb-4">
                    <div class="text-right">
                        <h2 class="text-xl font-black text-gray-950">نظرة عامة</h2>
                        <p class="mt-1 text-sm text-gray-500">البيانات هنا تخص مشروع الاستوديو فقط.</p>
                    </div>
                    <span class="inline-flex w-fit rounded-full border px-3 py-1 text-xs font-black {{ $statusClass }}">{{ $project->statusLabel() }}</span>
                </div>

                <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-3 text-right">
                    <div class="rounded-xl bg-gray-50 p-4">
                        <p class="text-xs font-bold text-gray-400">المرحلة الحالية</p>
                        <p class="mt-1 font-black text-gray-900">{{ $project->stageLabel() }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4">
                        <p class="text-xs font-bold text-gray-400">المسؤول</p>
                        <p class="mt-1 font-black text-gray-900">{{ $project->assignedTo?->name ?? 'غير معين' }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4">
                        <p class="text-xs font-bold text-gray-400">تقدم الجودة</p>
                        <p class="mt-1 font-black text-gray-900">{{ $project->qaProgress() }}%</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4">
                        <p class="text-xs font-bold text-gray-400">منشئ المشروع</p>
                        <p class="mt-1 font-black text-gray-900">{{ $project->creator?->name ?? '-' }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4">
                        <p class="text-xs font-bold text-gray-400">أرسل للاستوديو</p>
                        <p class="mt-1 font-black text-gray-900">{{ $project->sent_to_studio_at?->format('Y-m-d H:i') ?? '-' }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4">
                        <p class="text-xs font-bold text-gray-400">آخر تحديث</p>
                        <p class="mt-1 font-black text-gray-900">{{ $project->updated_at?->diffForHumans() }}</p>
                    </div>
                </div>

                @can('production_studio.manage')
                    <form method="POST" action="{{ route('admin.production-studio.update', $project) }}" class="mt-6 space-y-4">
                        @csrf
                        @method('PATCH')
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <label class="block text-right">
                                <span class="text-sm font-black text-gray-700">حالة الاستوديو</span>
                                <select name="status" class="mt-2 w-full rounded-xl border-gray-300 text-right">
                                    @foreach($statuses as $value => $label)
                                        <option value="{{ $value }}" @selected($project->status === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-right">
                                <span class="text-sm font-black text-gray-700">المرحلة</span>
                                <select name="current_stage" class="mt-2 w-full rounded-xl border-gray-300 text-right">
                                    <option value="">بدون مرحلة</option>
                                    @foreach($stages as $value => $label)
                                        <option value="{{ $value }}" @selected($project->current_stage === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-right">
                                <span class="text-sm font-black text-gray-700">المسؤول</span>
                                <select name="assigned_to_user_id" class="mt-2 w-full rounded-xl border-gray-300 text-right">
                                    <option value="">غير معين</option>
                                    @foreach($assignees as $assignee)
                                        <option value="{{ $assignee->id }}" @selected($project->assigned_to_user_id === $assignee->id)>{{ $assignee->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <label class="block text-right">
                            <span class="text-sm font-black text-gray-700">ملاحظات الإنتاج</span>
                            <textarea name="production_notes" rows="4" class="mt-2 w-full rounded-xl border-gray-300 text-right">{{ old('production_notes', $project->production_notes) }}</textarea>
                        </label>
                        <label class="block text-right">
                            <span class="text-sm font-black text-gray-700">سبب تجاوز QA عند النقل إلى جاهز للطباعة</span>
                            <input name="qa_override_reason" value="{{ old('qa_override_reason') }}" class="mt-2 w-full rounded-xl border-gray-300 text-right" placeholder="يُطلب فقط إذا توجد بنود QA غير مكتملة أو فاشلة">
                        </label>
                        <button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">حفظ بيانات المشروع</button>
                    </form>
                @endcan
            </div>

            <div class="space-y-4">
                <div class="rounded-2xl border border-gray-100 bg-white p-6 text-right shadow-sm">
                    <h3 class="text-lg font-black text-gray-950">إجراءات سريعة</h3>
                    <div class="mt-4 space-y-3">
                        @can('production_studio.archive')
                            @if($project->status === 'archived')
                                <form method="POST" action="{{ route('admin.production-studio.reopen', $project) }}">
                                    @csrf
                                    <button class="w-full rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-black text-indigo-700 hover:bg-indigo-100">إعادة فتح المشروع</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.production-studio.archive', $project) }}">
                                    @csrf
                                    <button class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm font-black text-gray-700 hover:bg-gray-100">أرشفة المشروع</button>
                                </form>
                            @endif
                        @endcan
                        @can('production_studio.delete_or_cancel')
                            <form method="POST" action="{{ route('admin.production-studio.cancel', $project) }}" class="space-y-2">
                                @csrf
                                <input name="cancel_reason" class="w-full rounded-xl border-gray-300 text-right text-sm" placeholder="سبب إلغاء مشروع الاستوديو">
                                <button class="w-full rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-black text-red-700 hover:bg-red-100">إلغاء مشروع الاستوديو فقط</button>
                            </form>
                        @endcan
                    </div>
                </div>
                <div class="rounded-2xl border border-amber-100 bg-amber-50 p-5 text-right text-sm leading-7 text-amber-900">
                    <p class="font-black">تنبيه عزل آمن</p>
                    <p class="mt-1">أي تحديث هنا لا يغير حالة الطلب الأصلي ولا يلغي الطلب ولا يبدل برومبت الإنتاج الحالي.</p>
                </div>
            </div>
        </section>

        <section id="reference" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 border-b border-gray-100 pb-4">
                <div class="text-right">
                    <h2 class="text-xl font-black text-gray-950">بيانات الطلب والطفل</h2>
                    <p class="mt-1 text-sm text-gray-500">قراءة مباشرة من الطلب الأصلي. لقطة الإنشاء محفوظة للرجوع التاريخي فقط.</p>
                </div>
                <a href="{{ route('admin.orders.show', $order) }}" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-black text-indigo-700 hover:bg-indigo-100">فتح الطلب الأصلي</a>
            </div>

            <div class="mt-5 grid grid-cols-1 lg:grid-cols-3 gap-4 text-right">
                <div class="rounded-xl bg-gray-50 p-4">
                    <p class="text-xs font-bold text-gray-400">العميل</p>
                    <p class="mt-1 font-black text-gray-900">{{ $order->parent_name ?? $order->user?->name ?? 'Not available' }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ data_get($order->delivery_details, 'phone', data_get($order->delivery_details, 'mobile', '')) }}</p>
                </div>
                <div class="rounded-xl bg-gray-50 p-4">
                    <p class="text-xs font-bold text-gray-400">الطفل</p>
                    <p class="mt-1 font-black text-gray-900">{{ $order->child_name ?? 'Not available' }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ $order->child_age ?? 'Not available' }} سنة - {{ $order->child_gender ?? 'Not available' }}</p>
                </div>
                <div class="rounded-xl bg-gray-50 p-4">
                    <p class="text-xs font-bold text-gray-400">القصة المختارة</p>
                    <p class="mt-1 font-black text-gray-900">{{ $order->story?->title ?? 'Not available' }}</p>
                    <p class="mt-1 text-sm text-gray-500">الفئة العمرية: {{ $order->story?->age_range ?? 'Not available' }}</p>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="rounded-xl border border-gray-100 p-4 text-right">
                    <p class="font-black text-gray-900">الاهتمامات وملاحظات الوالد</p>
                    <p class="mt-2 whitespace-pre-line text-sm leading-7 text-gray-600">{{ $order->interests ?: 'Not available' }}</p>
                    <p class="mt-3 whitespace-pre-line text-sm leading-7 text-gray-600">{{ $order->parent_notes ?: 'Not available' }}</p>
                </div>
                <div class="rounded-xl border border-gray-100 p-4 text-right">
                    <p class="font-black text-gray-900">الإضافات المرتبطة</p>
                    @php($addOns = $order->items->where('item_type', 'product_add_on'))
                    @if($addOns->isEmpty())
                        <p class="mt-2 text-sm text-gray-500">لا توجد إضافات مرتبطة.</p>
                    @else
                        <div class="mt-2 space-y-2">
                            @foreach($addOns as $addOn)
                                <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm">
                                    <span class="font-black text-gray-900">{{ $addOn->title }}</span>
                                    <span class="text-gray-500"> - {{ $addOn->quantity }} × {{ number_format($addOn->unit_price_cents / 100, 0) }} ج.م</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="mt-5">
                <p class="mb-3 text-right font-black text-gray-900">صور الطفل الأصلية</p>
                @if(count($photos))
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                        @foreach($photos as $photo)
                            <a href="{{ route('admin.production-studio.photo', [$project, $loop->index]) }}" target="_blank" class="block rounded-xl border border-gray-100 bg-gray-50 p-2 hover:border-indigo-200">
                                <div class="aspect-square overflow-hidden rounded-lg bg-gray-100">
                                    <img src="{{ route('admin.production-studio.photo', [$project, $loop->index]) }}" alt="صورة الطفل {{ $loop->iteration }}" class="h-full w-full object-cover" loading="lazy">
                                </div>
                                <p class="mt-1 text-center text-xs font-bold text-gray-500">صورة {{ $loop->iteration }}</p>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-right text-sm text-gray-500">لا توجد صور مرفقة بهذا الطلب.</p>
                @endif
            </div>

            @if($existingProductionPrompt)
                <div class="mt-5 rounded-xl border border-gray-100 bg-slate-50 p-4">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <button type="button" data-copy-target="existing-production-prompt" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white hover:bg-indigo-700">نسخ برومبت الطلب الحالي</button>
                        <p class="text-right font-black text-gray-900">برومبت الإنتاج الحالي للطلب الأصلي</p>
                    </div>
                    <textarea id="existing-production-prompt" rows="12" dir="ltr" readonly class="w-full rounded-xl border-gray-300 bg-white font-mono text-sm text-left">{{ $existingProductionPrompt }}</textarea>
                </div>
            @endif

            <details class="mt-5 rounded-xl border border-gray-100 bg-gray-50 p-4 text-right">
                <summary class="cursor-pointer font-black text-gray-800">عرض لقطة بيانات المشروع عند الإنشاء</summary>
                <pre dir="ltr" class="mt-3 overflow-x-auto rounded-lg bg-white p-3 text-left text-xs text-gray-700">{{ json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        </section>

        <section id="story" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 border-b border-gray-100 pb-4">
                <div class="text-right">
                    <h2 class="text-xl font-black text-gray-950">مساحة القصة</h2>
                    <p class="mt-1 text-sm text-gray-500">نسخ عمل خاصة بهذا الطلب فقط. لا يتم تعديل سجل القصة الأصلي.</p>
                </div>
                @can('production_studio.story_edit')
                    <form method="POST" action="{{ route('admin.production-studio.story-versions.from-story', $project) }}">
                        @csrf
                        <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white hover:bg-indigo-700">إنشاء مسودة من القصة الأصلية</button>
                    </form>
                @endcan
            </div>

            <div class="mt-5 rounded-xl bg-gray-50 p-4 text-right">
                <p class="font-black text-gray-900">{{ $order->story?->title ?? 'Not available' }}</p>
                <p class="mt-2 whitespace-pre-line text-sm leading-7 text-gray-600">{{ $order->story?->full_desc ?? $order->story?->short_desc ?? 'Not available' }}</p>
            </div>

            <div class="mt-5 space-y-3">
                @forelse($project->storyVersions as $version)
                    <div class="rounded-xl border border-gray-100 p-4 text-right">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div>
                                <p class="font-black text-gray-950">نسخة {{ $version->version_number }} - {{ $version->title ?? 'بدون عنوان' }}</p>
                                <p class="text-sm text-gray-500">الحالة: {{ $version->status }} - العمر المستهدف: {{ $version->target_age_group ?? 'Not available' }}</p>
                            </div>
                            @can('production_studio.story_edit')
                                <form method="POST" action="{{ route('admin.production-studio.story-versions.review', [$project, $version]) }}" class="flex flex-col md:flex-row gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <select name="status" class="rounded-xl border-gray-300 text-sm">
                                        <option value="under_review" @selected($version->status === 'under_review')>تحت المراجعة</option>
                                        <option value="approved" @selected($version->status === 'approved')>معتمد</option>
                                        <option value="rejected" @selected($version->status === 'rejected')>مرفوض</option>
                                    </select>
                                    <input name="review_notes" value="{{ $version->review_notes }}" class="rounded-xl border-gray-300 text-sm" placeholder="ملاحظات المراجعة">
                                    <button class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-black text-white">حفظ</button>
                                </form>
                            @endcan
                        </div>
                        <p class="mt-3 whitespace-pre-line text-sm leading-7 text-gray-600">{{ $version->full_story_content ?: 'لا يوجد محتوى محفوظ.' }}</p>
                    </div>
                @empty
                    <p class="rounded-xl bg-gray-50 p-4 text-right text-sm text-gray-500">لم يتم إنشاء مسودة داخل الاستوديو بعد.</p>
                @endforelse
            </div>
        </section>

        <section id="character" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="border-b border-gray-100 pb-4 text-right">
                <h2 class="text-xl font-black text-gray-950">ملف الشخصية</h2>
                <p class="mt-1 text-sm text-gray-500">تحضير يدوي للهوية البصرية، بدون أي توليد تلقائي في هذه المرحلة.</p>
            </div>

            <form method="POST" action="{{ route('admin.production-studio.character-profile.update', $project) }}" class="mt-5 space-y-4">
                @csrf
                @method('PATCH')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach([
                        'appearance_summary' => 'ملخص المظهر',
                        'hair_details' => 'تفاصيل الشعر',
                        'skin_tone' => 'لون البشرة',
                        'eye_color_traits' => 'العين والملامح الظاهرة',
                        'typical_expression' => 'التعبير المعتاد',
                        'identity_rules' => 'قواعد الحفاظ على الهوية',
                        'wardrobe_direction' => 'اتجاه الملابس',
                        'approved_visual_style' => 'الأسلوب البصري المعتمد',
                        'negative_instructions' => 'تعليمات سلبية',
                        'reviewer_notes' => 'ملاحظات المراجع',
                    ] as $field => $label)
                        <label class="block text-right">
                            <span class="text-sm font-black text-gray-700">{{ $label }}</span>
                            <textarea name="{{ $field }}" rows="3" @cannot('production_studio.character_profile_edit') readonly @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-right">{{ old($field, $profile?->{$field}) }}</textarea>
                        </label>
                    @endforeach
                </div>

                <div class="rounded-xl border border-gray-100 p-4 text-right">
                    <p class="font-black text-gray-900">الصور المعتمدة كمرجع</p>
                    @if(count($photos))
                        <div class="mt-3 grid grid-cols-2 md:grid-cols-5 gap-3">
                            @foreach($photos as $photo)
                                <label class="rounded-xl border border-gray-100 bg-gray-50 p-2">
                                    <img src="{{ route('admin.production-studio.photo', [$project, $loop->index]) }}" alt="مرجع {{ $loop->iteration }}" class="aspect-square w-full rounded-lg object-cover">
                                    <span class="mt-2 flex items-center justify-center gap-2 text-sm font-bold text-gray-700">
                                        <input type="checkbox" name="approved_reference_photos[]" value="{{ $loop->index }}" @checked(in_array($loop->index, $profile?->approved_reference_photos ?? [], true)) @cannot('production_studio.character_profile_edit') disabled @endcannot>
                                        صورة {{ $loop->iteration }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-2 text-sm text-gray-500">لا توجد صور لاختيارها.</p>
                    @endif
                </div>

                @can('production_studio.character_profile_edit')
                    <button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">حفظ ملف الشخصية</button>
                @endcan
            </form>
        </section>

        <section id="scenes" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="border-b border-gray-100 pb-4 text-right">
                <h2 class="text-xl font-black text-gray-950">المشاهد</h2>
                <p class="mt-1 text-sm text-gray-500">مساحة إعداد مشاهد الإنتاج. العدد القياسي الحالي 13 مشهدًا، مع بقاء النموذج مرنًا للمستقبل.</p>
            </div>

            <div class="mt-5 space-y-4">
                @forelse($project->scenes as $scene)
                    <form method="POST" action="{{ route('admin.production-studio.scenes.update', [$project, $scene]) }}" class="rounded-xl border border-gray-100 p-4 text-right">
                        @csrf
                        @method('PATCH')
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                            <label class="block">
                                <span class="text-xs font-black text-gray-500">رقم المشهد</span>
                                <input name="scene_number" value="{{ $scene->scene_number }}" @cannot('production_studio.scene_edit') readonly @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                            </label>
                            <label class="block md:col-span-2">
                                <span class="text-xs font-black text-gray-500">العنوان</span>
                                <input name="title" value="{{ $scene->title }}" @cannot('production_studio.scene_edit') readonly @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                            </label>
                            <label class="block">
                                <span class="text-xs font-black text-gray-500">الحالة</span>
                                <input name="status" value="{{ $scene->status }}" @cannot('production_studio.scene_edit') readonly @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                            </label>
                        </div>
                        <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                            <textarea name="story_text" rows="4" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="نص المشهد">{{ $scene->story_text }}</textarea>
                            <textarea name="visual_direction" rows="4" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="التوجيه البصري">{{ $scene->visual_direction }}</textarea>
                            <textarea name="educational_value" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="القيمة التعليمية">{{ $scene->educational_value }}</textarea>
                            <textarea name="child_action_pose" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="حركة أو وضع الطفل">{{ $scene->child_action_pose }}</textarea>
                            <textarea name="text_safe_area_notes" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="ملاحظات منطقة النص الآمنة">{{ $scene->text_safe_area_notes }}</textarea>
                            <textarea name="review_notes" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="ملاحظات المراجعة">{{ $scene->review_notes }}</textarea>
                        </div>
                        <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                            <div class="rounded-xl bg-gray-50 p-3">الصورة الأساسية: {{ $scene->base_scene_image_path ?: 'قادم لاحقًا' }}</div>
                            <div class="rounded-xl bg-gray-50 p-3">الصورة المخصصة: {{ $scene->generated_child_image_path ?: 'قادم لاحقًا' }}</div>
                            <div class="rounded-xl bg-gray-50 p-3">الصورة النهائية: {{ $scene->approved_final_image_path ?: 'قادم لاحقًا' }}</div>
                        </div>
                        @can('production_studio.scene_edit')
                            <button class="mt-3 rounded-xl bg-gray-900 px-4 py-2 text-sm font-black text-white">حفظ المشهد</button>
                        @endcan
                    </form>
                @empty
                    <p class="rounded-xl bg-gray-50 p-4 text-right text-sm text-gray-500">لا توجد مشاهد بعد. أنشئ مسودة من القصة الأصلية أو أضف مشهدًا يدويًا.</p>
                @endforelse
            </div>

            @can('production_studio.scene_edit')
                <form method="POST" action="{{ route('admin.production-studio.scenes.store', $project) }}" class="mt-5 rounded-xl border border-dashed border-indigo-200 bg-indigo-50 p-4 text-right">
                    @csrf
                    <p class="font-black text-indigo-900">إضافة مشهد يدوي</p>
                    <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                        <input name="scene_number" class="rounded-xl border-gray-300 text-right" placeholder="رقم المشهد" required>
                        <input name="title" class="rounded-xl border-gray-300 text-right" placeholder="عنوان المشهد">
                        <input name="status" value="draft" class="rounded-xl border-gray-300 text-right" placeholder="الحالة">
                        <textarea name="story_text" rows="3" class="md:col-span-3 rounded-xl border-gray-300 text-right" placeholder="نص المشهد"></textarea>
                    </div>
                    <button class="mt-3 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white hover:bg-indigo-700">إضافة</button>
                </form>
            @endcan
        </section>

        <section id="images" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="text-right">
                <h2 class="text-xl font-black text-gray-950">إنتاج الصور</h2>
                <p class="mt-1 text-sm text-gray-500">تم تجهيز الجداول للمزودين والنماذج والمهام المستقبلية، ولا يوجد أي اتصال خارجي الآن.</p>
            </div>
            <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-5 text-center text-sm font-black text-gray-500">Text to Image - قريبًا</div>
                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-5 text-center text-sm font-black text-gray-500">Image to Image - قريبًا</div>
                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-5 text-center text-sm font-black text-gray-500">Upscale/Edit - قريبًا</div>
            </div>
        </section>

        <section id="layout" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="text-right">
                <h2 class="text-xl font-black text-gray-950">Layout & PDF</h2>
                <p class="mt-1 text-sm text-gray-500">تجهيز مستقبلي للإخراج اليدوي أو الآلي. لا يتم استبدال أي ملفات إنتاج حالية.</p>
            </div>
            <div class="mt-5 grid grid-cols-1 md:grid-cols-4 gap-4 text-right">
                @foreach(['Reader Order PDF - صفحات A4 بترتيب القراءة', 'Print-Ready Booklet PDF - A3 أفقي مزدوج', 'Print Manifest - ترتيب الطباعة', 'Proof Print Checklist - مراجعة بروفة الطباعة'] as $assetLabel)
                    <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-4">
                        <p class="font-black text-gray-800">{{ $assetLabel }}</p>
                        <p class="mt-2 text-sm text-gray-500">مكان مخصص للمرحلة القادمة.</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section id="qa" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 border-b border-gray-100 pb-4">
                <div class="text-right">
                    <h2 class="text-xl font-black text-gray-950">مراجعة الجودة</h2>
                    <p class="mt-1 text-sm text-gray-500">لا يمكن النقل إلى جاهز للطباعة عند وجود بنود إلزامية فاشلة أو غير مكتملة بدون سبب تجاوز.</p>
                </div>
                <div class="rounded-full bg-emerald-50 px-4 py-2 text-sm font-black text-emerald-700">التقدم {{ $project->qaProgress() }}%</div>
            </div>

            <div class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-3">
                @foreach($project->qaChecks->groupBy('category') as $category => $checks)
                    <div class="rounded-xl border border-gray-100 p-4 text-right">
                        <p class="mb-3 font-black text-gray-900">{{ $category }}</p>
                        <div class="space-y-3">
                            @foreach($checks as $check)
                                <form method="POST" action="{{ route('admin.production-studio.qa.update', [$project, $check]) }}" class="rounded-lg bg-gray-50 p-3">
                                    @csrf
                                    @method('PATCH')
                                    <p class="font-bold text-gray-900">{{ $check->label }}</p>
                                    <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2">
                                        <select name="result" @cannot('production_studio.qa_review') disabled @endcannot class="rounded-xl border-gray-300 text-sm">
                                            <option value="not_reviewed" @selected($check->result === 'not_reviewed')>لم يراجع</option>
                                            <option value="pass" @selected($check->result === 'pass')>ناجح</option>
                                            <option value="fail" @selected($check->result === 'fail')>فشل</option>
                                            <option value="not_applicable" @selected($check->result === 'not_applicable')>لا ينطبق</option>
                                        </select>
                                        <input name="note" value="{{ $check->note }}" @cannot('production_studio.qa_review') readonly @endcannot class="rounded-xl border-gray-300 text-sm" placeholder="ملاحظة">
                                        <label class="flex items-center gap-2 text-xs font-bold text-gray-600">
                                            <input type="checkbox" name="override_allowed" value="1" @checked($check->override_allowed) @cannot('production_studio.qa_review') disabled @endcannot>
                                            تجاوز بصلاحية
                                        </label>
                                        <input name="override_reason" value="{{ $check->override_reason }}" @cannot('production_studio.qa_review') readonly @endcannot class="rounded-xl border-gray-300 text-sm" placeholder="سبب التجاوز">
                                    </div>
                                    @can('production_studio.qa_review')
                                        <button class="mt-2 rounded-lg bg-white px-3 py-1.5 text-xs font-black text-indigo-700 ring-1 ring-indigo-200">حفظ البند</button>
                                    @endcan
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section id="activity" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="border-b border-gray-100 pb-4 text-right">
                <h2 class="text-xl font-black text-gray-950">سجل النشاط</h2>
                <p class="mt-1 text-sm text-gray-500">أحداث خاصة بمشروع الاستوديو فقط.</p>
            </div>
            <div class="mt-5 space-y-3">
                @forelse($project->activityLogs as $log)
                    <div class="rounded-xl bg-gray-50 p-4 text-right">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                            <p class="font-black text-gray-900">{{ $log->description }}</p>
                            <p class="text-xs text-gray-500">{{ $log->created_at?->format('Y-m-d H:i') }}</p>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">{{ $log->actor?->name ?? 'System' }} - {{ $log->action }}</p>
                    </div>
                @empty
                    <p class="rounded-xl bg-gray-50 p-4 text-right text-sm text-gray-500">لا يوجد نشاط مسجل بعد.</p>
                @endforelse
            </div>
        </section>
    </div>

    <script>
        document.addEventListener('click', async function (event) {
            const button = event.target.closest('[data-copy-target]');
            if (!button) return;

            const target = document.getElementById(button.dataset.copyTarget);
            if (!target) return;

            try {
                await navigator.clipboard.writeText(target.value || target.textContent || '');
                button.textContent = 'تم النسخ';
                setTimeout(() => button.textContent = 'نسخ برومبت الطلب الحالي', 1600);
            } catch (error) {
                target.select();
                document.execCommand('copy');
            }
        });
    </script>
</x-admin-layout>
