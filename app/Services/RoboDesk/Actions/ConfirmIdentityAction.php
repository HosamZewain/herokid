<?php

namespace App\Services\RoboDesk\Actions;

class ConfirmIdentityAction extends RoboDeskAction
{
    public const KEY = 'identity.confirm';

    public function key(): string
    {
        return self::KEY;
    }

    public function labelAr(): string
    {
        return 'اعتماد هوية الطفل بعد إنشائها';
    }

    public function labelEn(): string
    {
        return 'Confirm child identity after creation';
    }

    public function descriptionAr(): string
    {
        return 'يُرسل هوية الطفل المُولّدة للعميل على واتساب. الموافقة تُكمل المسار، وطلب التعديل يُحقن كملاحظات في برومبت المحاولة التالية.';
    }

    public function variables(): array
    {
        return [
            'customer_name' => 'اسم ولي الأمر',
            'customer_phone' => 'رقم الواتساب',
            'identity_uuid' => 'معرّف طلب الهوية',
            'child_name' => 'اسم الطفل',
            'attempt_id' => 'رقم المحاولة',
            'attempt_number' => 'ترتيب المحاولة',
            'identity_url' => 'رابط صورة الهوية',
            'checkout_reference' => 'رقم عملية الشراء إن وُجد',
            'attempts_remaining' => 'عدد المحاولات المتبقية',
        ];
    }

    public function specificParams(): array
    {
        return [
            [
                'key' => 'gate_auto_approval',
                'label_ar' => 'إيقاف الاعتماد التلقائي للهوية',
                'type' => 'boolean',
                'default' => '0',
                'help_ar' => 'عند التفعيل تبقى الهوية بانتظار موافقة العميل بدل اعتمادها تلقائيًا من أول محاولة ناجحة.',
            ],
            [
                'key' => 'max_revisions',
                'label_ar' => 'أقصى عدد تعديلات مسموح بها',
                'type' => 'number',
                'default' => '3',
                'help_ar' => 'عدد مرات إعادة التوليد بناءً على ملاحظات العميل قبل تحويل الطلب لمراجعة بشرية.',
            ],
            [
                'key' => 'media_link_ttl_hours',
                'label_ar' => 'صلاحية رابط صورة الهوية (بالساعات)',
                'type' => 'number',
                'default' => '168',
                'help_ar' => 'رابط موقّع مؤقت يُرسل للعميل. الافتراضي 7 أيام.',
            ],
            [
                'key' => 'comment_prompt_prefix',
                'label_ar' => 'تمهيد ملاحظات العميل في البرومبت',
                'type' => 'textarea',
                'default' => 'Apply the following parent feedback while keeping the child recognizable:',
                'help_ar' => 'يُضاف قبل نص ملاحظات العميل عند حقنها في برومبت التوليد.',
            ],
        ];
    }
}
