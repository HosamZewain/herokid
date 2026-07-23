<x-front-layout>

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">سياسة الخصوصية</x-slot>
<x-slot name="pageDescription">تعرف على كيفية حماية HeroKid لبياناتك الشخصية وصور أطفالك، وأسس الاحتفاظ الآمن بالصور وطلبات الهوية وحقوق الحذف.</x-slot>
<x-slot name="robots">noindex, nofollow</x-slot>

    <div class="bg-white py-16">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="mb-12 text-center">
                <h1 class="text-4xl font-extrabold text-slate-900 mb-4">سياسة الخصوصية</h1>
                <p class="text-slate-500">آخر تحديث: {{ date('d/m/Y') }}</p>
            </div>

            <div class="prose prose-slate max-w-none text-right leading-relaxed space-y-8">

                <div class="bg-indigo-50 border-r-4 border-indigo-500 p-6 rounded-lg">
                    <p class="text-indigo-800 font-semibold">
                        نحن في HeroKid نلتزم بحماية خصوصية أطفالكم وبياناتكم الشخصية بأعلى المعايير. صور أطفالكم وبياناتهم لن تُستخدم إلا لغرض واحد: تحويلهم إلى أبطال قصصهم.
                    </p>
                </div>

                <section>
                    <h2 class="text-2xl font-bold text-slate-900 mb-4">١. ما البيانات التي نجمعها؟</h2>
                    <p class="text-slate-600 mb-3">عند استخدامك لخدماتنا، نجمع البيانات التالية:</p>
                    <ul class="list-disc list-inside space-y-2 text-slate-600 mr-4">
                        <li>اسمك الكامل وبيانات التواصل (بريد إلكتروني، هاتف)</li>
                        <li>اسم طفلك وعمره وجنسه واهتماماته</li>
                        <li>صور طفلك التي ترفعها لأغراض تخصيص القصة</li>
                        <li>بيانات التوصيل (العنوان، المدينة، المحافظة)</li>
                        <li>بيانات استخدام الموقع (عنوان IP، الصفحات المزارة)</li>
                    </ul>
                </section>

                <section>
                    <h2 class="text-2xl font-bold text-slate-900 mb-4">٢. كيف نستخدم بياناتك؟</h2>
                    <p class="text-slate-600 mb-3">نستخدم بياناتك حصرياً للأغراض التالية:</p>
                    <ul class="list-disc list-inside space-y-2 text-slate-600 mr-4">
                        <li>معالجة طلبك وتخصيص قصة طفلك</li>
                        <li>استخدام صور طفلك لتوليد رسومات القصة بالذكاء الاصطناعي</li>
                        <li>التواصل معك بشأن تفاصيل الطلب والشحن</li>
                        <li>إرسال نسخة Preview للموافقة قبل الطباعة</li>
                        <li>تحسين خدماتنا وتجربة المستخدم</li>
                    </ul>
                    <p class="text-slate-600 mt-3 font-semibold">لن نبيع أو نتاجر ببيانات أطفالكم، ولا نشارك منها إلا الحد الأدنى اللازم مع مزودي المعالجة المصرح لهم لتنفيذ الخدمة.</p>
                </section>

                <section>
                    <h2 class="text-2xl font-bold text-slate-900 mb-4">٣. صور الأطفال — سياسة خاصة</h2>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-5 space-y-3 text-slate-700">
                        <p>نحن ندرك الحساسية البالغة لصور الأطفال. لذلك:</p>
                        <ul class="list-disc list-inside space-y-2 mr-4">
                            <li>صور طفلك تُستخدم <strong>فقط</strong> لإنشاء رسومات القصة المطبوعة لطلبك المحدد.</li>
                            <li>لا يحق لأي موظف أو طرف ثالث استخدام الصور لأي غرض آخر.</li>
                            <li>يتم حفظ الصور على خوادم مؤمّنة بتشفير كامل.</li>
                            <li>قد تنتهي صلاحية ملفات الرفع المؤقتة غير المرتبطة بطلب أو خدمة مكتملة، ويتم حذفها وفق ضوابط التشغيل الآمنة.</li>
                            <li>أما صور هويات الأطفال الأصلية، والمخرجات، ومحاولات التوليد، فتُحفظ بشكل آمن لدعم طلب الهوية والطلب المرتبط وسجل التدقيق، ولا تُحذف تلقائياً.</li>
                            <li>لا يحدث الحذف المادي لهذه الملفات إلا بإجراء إداري نهائي مصرح به أو استجابة لطلب خصوصية مدعوم، مع الاحتفاظ ببيان تدقيق يوضح أثر الحذف.</li>
                            <li>يمكنك طلب حذف صور طفلك أو ممارسة حقوق الخصوصية في أي وقت عبر التواصل معنا.</li>
                        </ul>
                    </div>
                </section>

                <section>
                    <h2 class="text-2xl font-bold text-slate-900 mb-4">٤. الموافقات</h2>
                    <p class="text-slate-600">موافقة معالجة بيانات وصور الطفل مطلوبة لتنفيذ خدمة التخصيص أو هوية الطفل. أما الموافقة التسويقية فهي اختيارية ومنفصلة، ولا يؤثر رفضها على تنفيذ الخدمة أو الطلب.</p>
                </section>

                <section>
                    <h2 class="text-2xl font-bold text-slate-900 mb-4">٥. مشاركة البيانات مع أطراف ثالثة</h2>
                    <p class="text-slate-600 mb-3">نشارك الحد الأدنى من البيانات الضرورية مع:</p>
                    <ul class="list-disc list-inside space-y-2 text-slate-600 mr-4">
                        <li>شركات الشحن (بيانات التوصيل فقط)</li>
                        <li>مزودو خدمات الدفع الإلكتروني (بيانات مالية مشفرة)</li>
                        <li>خدمات الاستضافة السحابية (لتخزين الملفات بشكل آمن)</li>
                        <li>مزودو الذكاء الاصطناعي المعتمدون (الصور والبرومبت اللازمان لإنشاء الهوية أو رسومات القصة فقط)</li>
                    </ul>
                    <p class="text-slate-600 mt-3">جميع الأطراف الثالثة ملزمون بعقود سرية صارمة.</p>
                </section>

                <section>
                    <h2 class="text-2xl font-bold text-slate-900 mb-4">٦. حقوقك</h2>
                    <p class="text-slate-600 mb-3">لك الحق في:</p>
                    <ul class="list-disc list-inside space-y-2 text-slate-600 mr-4">
                        <li>الاطلاع على بياناتك الشخصية التي نحتفظ بها</li>
                        <li>طلب تصحيح أي بيانات غير دقيقة</li>
                        <li>طلب حذف بياناتك وبيانات طفلك بشكل كامل</li>
                        <li>الاعتراض على معالجة بياناتك</li>
                        <li>سحب موافقتك في أي وقت</li>
                    </ul>
                    <p class="text-slate-600 mt-3">لممارسة أي من هذه الحقوق، تواصل معنا على: <a href="mailto:{{ $settings['privacy_email'] ?? $settings['site_email'] ?? '' }}" class="text-indigo-600 font-semibold">{{ $settings['privacy_email'] ?? $settings['site_email'] ?? '' }}</a></p>
                </section>

                <section>
                    <h2 class="text-2xl font-bold text-slate-900 mb-4">٧. أمان البيانات</h2>
                    <p class="text-slate-600">نستخدم بروتوكول HTTPS المشفر لجميع الاتصالات، ونحتفظ بالصور والملفات الحساسة في تخزين خاص غير قابل للوصول العام. نراجع ممارساتنا الأمنية بانتظام للحفاظ على سلامة بياناتكم.</p>
                </section>

                <section>
                    <h2 class="text-2xl font-bold text-slate-900 mb-4">٨. تواصل معنا</h2>
                    <p class="text-slate-600">لأي استفسار حول هذه السياسة أو بياناتك الشخصية:</p>
                    <div class="bg-slate-50 rounded-lg p-4 mt-3 space-y-1 text-slate-700">
                        <p>📧 البريد الإلكتروني: <a href="mailto:privacy@herokid.sa" class="text-indigo-600">privacy@herokid.sa</a></p>
                        <p>📱 واتساب:
                            @if(!empty($settings['whatsapp_url']))
                                <a href="{{ $settings['whatsapp_url'] }}" class="text-indigo-600">{{ $settings['whatsapp_number'] ?? '' }}+</a>
                            @else
                                <span>{{ $settings['whatsapp_number'] ?? '' }}+</span>
                            @endif
                        </p>
                    </div>
                </section>

            </div>

        </div>
    </div>
</x-front-layout>
