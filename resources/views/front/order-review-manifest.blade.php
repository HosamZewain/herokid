<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>معاينة طلبك — HeroKid</title>@vite('resources/css/app.css')</head>
<body class="bg-slate-50 text-slate-900"><main class="mx-auto max-w-4xl p-5"><h1 class="text-2xl font-bold">معاينة طلبك</h1><p class="my-4">راجع كل الصور التالية، ثم ارجع لرسالة واتساب واضغط «تأكيد المعاينة» أو «طلب تعديل المعاينة». تأكيد المعاينة لا يعني تسجيل الدفع.</p>
@foreach($images as $image)<section class="mb-6 rounded-2xl bg-white p-4"><h2 class="mb-3 text-lg font-bold">{{ $image['label'] }}</h2><img src="{{ $image['url'] }}" alt="{{ $image['label'] }}" class="mx-auto h-auto max-w-full" loading="lazy"></section>@endforeach
</main></body></html>
