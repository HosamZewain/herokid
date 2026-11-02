<?php

return [
    'states' => ['unobserved' => 'لم يُرصد', 'pending' => 'ينتظر التقييم', 'partial' => 'تغطية جزئية', 'covered' => 'اكتملت تغطية النسخة', 'quota_deferred' => 'مؤجل لحد الاستخدام', 'error' => 'خطأ تقييم', 'observation_error' => 'خطأ قراءة', 'inactive' => 'غير نشط'],
    'decisions' => ['execute' => 'اقتراح إجراء', 'wait' => 'انتظار', 'human_required' => 'تدخل بشري', 'escalate' => 'تصعيد', 'completed' => 'مكتمل'],
    'actions' => ['send_order_confirmation' => 'طلب تأكيد الأوردر', 'generate_identity' => 'تجهيز هوية الطفل', 'generate_product_image' => 'تجهيز صورة المنتج',
        'start_story_scene_generation' => 'تجهيز مشاهد القصة', 'generate_book_cover' => 'تجهيز كفر الكتاب', 'generate_book_pages' => 'تجهيز صفحات الكتاب',
        'send_product_preview' => 'إرسال معاينة المنتج', 'request_customer_approval' => 'طلب موافقة العميل', 'attach_asset' => 'إرفاق الملف', 'create_human_task' => 'اقتراح مهمة لموظف'],
    'reasons' => ['MANUAL_HOLD' => 'الطلب متوقف للعمل اليدوي', 'LIFECYCLE_COMPLETE' => 'الطلب خارج دورة العمل النشطة', 'UNIT_INACTIVE' => 'الوحدة غير نشطة',
        'PAYMENT_STATE_CONFLICT' => 'تعارض في رصيد الطلب يحتاج مراجعة', 'PAYMENT_REVIEW_REQUIRED' => 'مراجعة إثبات الدفع بواسطة الموظف',
        'CUSTOMER_REQUESTED_CHANGES' => 'العميل طلب تعديلًا', 'CONFIRMATION_REQUIRED' => 'انتظار تأكيد الطلب', 'CONFIRMATION_UNCERTAIN' => 'نتيجة إرسال التأكيد غير مؤكدة',
        'EXTERNAL_RESULT_UNCERTAIN' => 'نتيجة التوليد غير مؤكدة؛ تحتاج مراجعة', 'EXISTING_WORK_IN_PROGRESS' => 'هناك عمل جارٍ بالفعل',
        'OUTPUT_REVIEW_AND_STUDIO' => 'مراجعة المخرجات وتجهيزها في Studio', 'PRESERVE_OUTPUT_REVIEW_ATTEMPT' => 'الاحتفاظ بالنتائج ومراجعة المحاولة السابقة',
        'UNSUPPORTED_CAPABILITY' => 'المنتج يحتاج تنفيذًا بشريًا أو قدرة غير متاحة', 'VARIANT_OR_IDENTITY_LINK_REQUIRES_REVIEW' => 'مراجعة المقاس أو ارتباط هوية الطفل',
        'MISSING_REFERENCE_IMAGES' => 'استكمال صور الطفل الأصلية', 'CUSTOMER_IDENTITY_APPROVAL_REQUIRED' => 'انتظار اعتماد العميل لهوية الطفل',
        'IDENTITY_ATTEMPT_REQUIRES_REVIEW' => 'مراجعة محاولة الهوية السابقة', 'IDENTITY_WORKFLOW_REQUIRED' => 'تجهيز الهوية عبر المسؤول الحالي',
        'CUSTOMER_COVER_APPROVAL_REQUIRED' => 'انتظار اعتماد كفر الكتاب', 'RECORDED_PAYMENT_REQUIRED' => 'انتظار دفعة فعلية مسجلة',
        'PRODUCTION_BRIEF_INCOMPLETE' => 'استكمال متطلبات الإنتاج', 'LINKED_STORY_REQUIRED' => 'انتظار اكتمال القصة المرتبطة',
        'EXISTING_AUTOMATION_OWNER' => 'المسار الحالي مسؤول عن الخطوة التالية', 'CODEX_POOL_UNCERTAIN' => 'مراجعة العملية غير المؤكدة في طابور Codex',
        'EXECUTOR_OR_STAGE_UNAVAILABLE' => 'الخدمة أو مرحلة الطلب لا تسمح بالإنتاج', 'READY_FOR_PRODUCTION_PREFLIGHT' => 'جاهز لفحص أهلية الإنتاج بالخدمة الحالية',
        'NO_PRODUCTION_UNITS' => 'مراجعة تعريف وحدات إنتاج الطلب'],
];
