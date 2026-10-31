<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">
    <title>معاينة هوية طفلك — HeroKid</title>
    <style>body{margin:0;background:#f8fafc;color:#1e293b;font-family:system-ui,sans-serif;line-height:1.8}main{max-width:960px;margin:auto;padding:24px}article{background:white;border:1px solid #e2e8f0;border-radius:20px;padding:20px;margin:20px 0}img{display:block;width:100%;height:auto;border-radius:12px}h1{color:#4338ca}p{color:#475569}</style>
</head>
<body><main><h1>معاينة هوية طفلك</h1><p>راجع كل الهويات المعروضة في هذه الصفحة؛ زر «تأكيد الهوية» يعتمدها جميعًا. ثم ارجع لرسالة واتساب واضغط «تأكيد الهوية» أو «طلب تعديل الهوية». تأكيد الهوية منفصل عن مراجعة الدفع.</p>
@foreach($images as $image)<article><h2>{{ $image['name'] }}</h2><img src="{{ $image['url'] }}" alt="معاينة هوية {{ $image['name'] }}" referrerpolicy="no-referrer"></article>@endforeach
</main></body></html>
