<?php

namespace App\Services\RoboDesk\Actions;

class ConfirmOrderAction extends RoboDeskAction
{
    public const KEY = 'order.confirm';

    public function key(): string
    {
        return self::KEY;
    }

    public function labelAr(): string
    {
        return 'تأكيد الطلب بعد إتمام السلة';
    }

    public function labelEn(): string
    {
        return 'Confirm order after checkout';
    }

    public function descriptionAr(): string
    {
        return 'يُرسل تفاصيل الطلب وعنوان الشحن والشروط إلى العميل على واتساب لتأكيده أو رفضه.';
    }

    public function variables(): array
    {
        return [
            'customer_name' => 'اسم ولي الأمر',
            'customer_phone' => 'رقم الواتساب',
            'checkout_reference' => 'رقم عملية الشراء',
            'short_reference' => 'الرقم المختصر',
            'order_numbers' => 'أرقام الطلبات',
            'items_summary' => 'ملخص العناصر',
            'children' => 'أسماء الأطفال',
            'items_total' => 'إجمالي العناصر',
            'delivery_fee' => 'مصاريف التوصيل',
            'discount' => 'الخصم',
            'total' => 'الإجمالي',
            'currency' => 'العملة',
            'delivery_address' => 'عنوان الشحن كاملًا',
            'delivery_country' => 'الدولة',
            'delivery_governorate' => 'المحافظة',
            'delivery_city' => 'المدينة',
            'customer_notes' => 'ملاحظات العميل',
            'terms' => 'نص الشروط والأحكام',
        ];
    }

    public function specificParams(): array
    {
        return [
            [
                'key' => 'terms_text',
                'label_ar' => 'نص الشروط والأحكام',
                'type' => 'textarea',
                'default' => '',
                'help_ar' => 'يظهر للعميل مع تفاصيل الطلب ويُتاح كمتغير {{ terms }}.',
            ],
            [
                'key' => 'gate_production',
                'label_ar' => 'إيقاف الإنتاج حتى يؤكد العميل',
                'type' => 'boolean',
                'default' => '0',
                'help_ar' => 'عند التفعيل تبدأ الطلبات بحالة "بانتظار التأكيد" ولا يلتقطها الـ Agent إلا بعد تأكيد العميل.',
            ],
        ];
    }
}
