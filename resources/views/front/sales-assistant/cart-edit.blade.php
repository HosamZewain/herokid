<x-front-layout>
    <x-slot name="pageTitle">تعديل بيانات الأطفال والصور — HeroKid</x-slot>
    <div class="mx-auto max-w-3xl space-y-5 p-4" dir="rtl">
        <h1 class="text-2xl font-black">بيانات الأطفال والصور</h1>
        <p class="text-sm text-slate-600">عدّل كل طفل داخل عنصره. الصور المحددة بتفضل مرتبطة بنفس الطفل؛ البيانات مش بتتنقل لطفل تاني تلقائيًا.</p>
        @if(session('success'))<p role="status" class="rounded-xl bg-emerald-50 p-3">{{ session('success') }}</p>@endif
        @if($errors->any())<p role="alert" class="rounded-xl bg-red-50 p-3 text-red-800">{{ $errors->first() }}</p>@endif
        @foreach($items as $key=>$item)
            <section class="rounded-2xl border bg-white p-4">
                <h2 class="mb-3 font-black">{{ $item['story_title'] ?? $item['product_title'] ?? 'منتج' }}</h2>
                @if($item['editable'])
                <form method="POST" enctype="multipart/form-data" action="{{ route('sales.cart-update',['key'=>$key,'_sales_assistant_embed'=>1]) }}" class="space-y-3">
                    @csrf<input name="_sales_assistant_embed" value="1" type="hidden">
                    @foreach($item['edit_fields'] as $name=>$field)
                        @if($field['type']!=='photos')
                            <label class="block text-sm font-bold">{{ $field['label'] }} @if($field['required'])*@endif
                                @if($field['type']==='gender')<select name="{{ $name }}" class="mt-1 block w-full rounded-xl"><option value="boy" @selected(($item['edit_values'][$name]??'')==='boy')>ولد</option><option value="girl" @selected(($item['edit_values'][$name]??'')==='girl')>بنت</option></select>
                                @elseif($field['type']==='age')<select name="{{ $name }}" class="mt-1 block w-full rounded-xl">@foreach(\App\Support\StoryAgeOptions::forPersonalization() as $age)<option value="{{ $age }}" @selected(($item['edit_values'][$name]??'')==$age)>{{ $age }} سنة</option>@endforeach</select>
                                @elseif($field['type']==='textarea')<textarea name="{{ $name }}" class="mt-1 block w-full rounded-xl">{{ $item['edit_values'][$name]??'' }}</textarea>
                                @else<input name="{{ $name }}" value="{{ $item['edit_values'][$name]??'' }}" class="mt-1 block w-full rounded-xl" @required($field['required'])>@endif
                            </label>
                        @endif
                    @endforeach
                    @if(($item['item_type']??'story')==='story')
                        <label class="block text-sm font-bold">اللغة<select name="story_language" class="mt-1 block w-full rounded-xl"><option value="ar" @selected(($item['story_language']??'ar')==='ar')>العربية</option><option value="en" @selected(($item['story_language']??'ar')==='en')>الإنجليزية (حسب التوافر)</option></select></label>
                        <label class="block text-sm font-bold">الإهداء<textarea name="gift_note" class="mt-1 block w-full rounded-xl">{{ $item['gift_note']??'' }}</textarea></label>
                    @endif
                    @if(data_get($item,'edit_schema.fields.photos.enabled'))
                        <p class="text-sm">احتفظ بالصور المحددة، أو أزل التحديد لاستبعاد صورة. أضف الصور البديلة قبل الحفظ.</p>
                        <div class="flex flex-wrap gap-3">@foreach($item['uploaded_photos']??[] as $path)<label class="rounded-xl border p-2"><img src="{{ route('sales.photo',hash('sha256',$path)) }}" alt="صورة {{ $item['child_name']??'الطفل' }}" width="64" height="80" class="h-20 w-16 rounded-lg object-cover"><span class="mt-2 block text-xs"><input name="keep[]" value="{{ hash('sha256',$path) }}" type="checkbox" checked> الاحتفاظ</span></label>@endforeach</div>
                        <label class="block text-sm font-bold">صور إضافية أو بديلة<input type="file" name="photos[]" multiple accept="image/*,.heic,.heif" class="mt-2 block max-w-full"></label>
                    @endif
                    <button class="rounded-xl bg-indigo-600 px-5 py-3 font-black text-white">حفظ بيانات هذا الطفل</button>
                </form>
                @else<p class="text-sm text-slate-600">هذا العنصر بدون تخصيص مستقل أو مرتبط بقصة أو هوية. بياناته بتؤخذ من القصة المرتبطة.</p>@endif
            </section>
        @endforeach
        <a href="{{ route('cart.index',['_sales_assistant_embed'=>1]) }}" class="block rounded-xl border p-3 text-center font-bold text-indigo-700">رجوع للسلة والعنوان</a>
    </div>
</x-front-layout>
