<?php

namespace App\Services\RoboDesk\Actions;

class ReceivePaymentAction extends RoboDeskAction
{
    public const KEY = 'payment.receive';

    public function key(): string
    {
        return self::KEY;
    }

    public function labelAr(): string
    {
        return 'استلام إثبات الدفع من واتساب';
    }

    public function labelEn(): string
    {
        return 'Receive payment proof from WhatsApp';
    }

    public function descriptionAr(): string
    {
        return 'يستقبل إثبات الدفع الذي يعيد RoboDesk توجيهه، ويرسل إشعارًا للعميل بالاستلام أو الرفض.';
    }

    public function variables(): array
    {
        return [
            'customer_name' => 'اسم ولي الأمر',
            'customer_phone' => 'رقم الواتساب',
            'checkout_reference' => 'رقم عملية الشراء',
            'short_reference' => 'الرقم المختصر',
            'proof_id' => 'معرّف الإثبات',
            'proof_status' => 'حالة الإثبات',
            'rejection_reason' => 'سبب الرفض',
            'remaining_amount' => 'المبلغ المتبقي',
            'currency' => 'العملة',
        ];
    }

    public function specificParams(): array
    {
        return [
            [
                'key' => 'acknowledge_receipt',
                'label_ar' => 'إرسال إشعار باستلام الإثبات',
                'type' => 'boolean',
                'default' => '1',
                'help_ar' => 'رسالة فورية للعميل بأن الإثبات وصل وقيد المراجعة.',
            ],
            [
                'key' => 'rejected_template_name',
                'label_ar' => 'اسم قالب رفض الإثبات',
                'type' => 'text',
                'default' => '',
                'help_ar' => null,
            ],
            [
                'key' => 'auto_mark_paid',
                'label_ar' => 'اعتماد الدفع تلقائيًا عند وصول الإثبات',
                'type' => 'boolean',
                'default' => '0',
                'help_ar' => 'غير مُوصى به. الافتراضي أن يراجع المشرف الإثبات يدويًا قبل تعديل حالة الدفع.',
            ],
            [
                'key' => 'max_file_mb',
                'label_ar' => 'أقصى حجم لملف الإثبات (ميجابايت)',
                'type' => 'number',
                'default' => '10',
                'help_ar' => null,
            ],
        ];
    }
}
