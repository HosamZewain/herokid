<x-admin-layout>
    <x-slot name="header"><div><h2 class="text-xl font-black text-slate-900">تعديل كود {{ $discountCode->code }}</h2><p class="mt-1 text-sm text-slate-500">التعديل يطبق فورًا على المحاولات الجديدة، ولا يغير الطلبات المكتملة.</p></div></x-slot>
    <section class="rounded-3xl border border-indigo-100 bg-white p-6 shadow-sm lg:p-8">
        <form method="POST" action="{{ route('admin.discount-codes.update', $discountCode) }}">
            @csrf @method('PUT')
            @include('admin.discount-codes._form', ['discountCode' => $discountCode])
        </form>
    </section>
</x-admin-layout>
