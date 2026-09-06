<?php

namespace App\Services\RoboDesk\Actions;

class ConfirmItemAction extends RoboDeskAction
{
    public const KEY = 'item.confirm';

    public function key(): string
    {
        return self::KEY;
    }

    public function labelAr(): string
    {
        return 'اعتماد المنتج بعد إنتاجه وطلب الدفع';
    }

    public function labelEn(): string
    {
        return 'Confirm produced item and request payment';
    }

    public function descriptionAr(): string
    {
        return 'يُرسل معاينة المنتج للعميل لاعتمادها، ثم يطلب الدفع حسب التوقيت المختار.';
    }

    public function variables(): array
    {
        return [
            'customer_name' => 'اسم ولي الأمر',
            'customer_phone' => 'رقم الواتساب',
            'checkout_reference' => 'رقم عملية الشراء',
            'short_reference' => 'الرقم المختصر',
            'order_number' => 'رقم الطلب',
            'item_title' => 'اسم المنتج أو القصة',
            'item_type' => 'نوع العنصر',
            'preview_url' => 'رابط المعاينة',
            'preview_version' => 'إصدار المعاينة',
            'total' => 'الإجمالي',
            'remaining_amount' => 'المبلغ المتبقي',
            'currency' => 'العملة',
            'instapay_url' => 'رابط انستاباي',
            'whatsapp_number' => 'رقم واتساب الشركة',
        ];
    }

    public function specificParams(): array
    {
        return [
            [
                'key' => 'payment_request_mode',
                'label_ar' => 'توقيت طلب الدفع',
                'type' => 'select',
                'options' => [
                    'after_item_approval' => 'بعد اعتماد العميل لكل العناصر',
                    'with_item' => 'مع إرسال المعاينة مباشرة',
                    'never' => 'لا تطلب الدفع من هنا',
                ],
                'default' => 'after_item_approval',
                'help_ar' => null,
            ],
            [
                'key' => 'payment_template_name',
                'label_ar' => 'اسم قالب طلب الدفع',
                'type' => 'text',
                'default' => '',
                'help_ar' => 'قالب منفصل لرسالة طلب الدفع. اتركه فارغًا لاستخدام نفس القالب.',
            ],
            [
                'key' => 'include_product_previews',
                'label_ar' => 'إرسال معاينات المنتجات أيضًا',
                'type' => 'boolean',
                'default' => '1',
                'help_ar' => 'بالإضافة إلى معاينات كتيّبات القصص.',
            ],
        ];
    }
}
