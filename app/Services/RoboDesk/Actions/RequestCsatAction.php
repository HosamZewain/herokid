<?php

namespace App\Services\RoboDesk\Actions;

class RequestCsatAction extends RoboDeskAction
{
    public const KEY = 'csat.request';

    public function key(): string
    {
        return self::KEY;
    }

    public function labelAr(): string
    {
        return 'قياس رضا العميل بعد التسليم';
    }

    public function labelEn(): string
    {
        return 'Request CSAT after delivery';
    }

    public function descriptionAr(): string
    {
        return 'يُرسل استطلاع رضا للعميل بعد تسليم الشحنة بمدة يحددها المشرف.';
    }

    public function variables(): array
    {
        return [
            'customer_name' => 'اسم ولي الأمر',
            'customer_phone' => 'رقم الواتساب',
            'checkout_reference' => 'رقم عملية الشراء',
            'short_reference' => 'الرقم المختصر',
            'order_number' => 'رقم الطلب',
            'children' => 'أسماء الأطفال',
            'items_summary' => 'ملخص العناصر',
            'delivered_at' => 'تاريخ التسليم',
        ];
    }

    public function specificParams(): array
    {
        return [
            [
                'key' => 'delay_minutes',
                'label_ar' => 'التأخير بعد التسليم (بالدقائق)',
                'type' => 'number',
                'default' => '1440',
                'help_ar' => 'الافتراضي 1440 دقيقة أي بعد يوم من التسليم.',
            ],
            [
                'key' => 'min_score',
                'label_ar' => 'أقل درجة في المقياس',
                'type' => 'number',
                'default' => '1',
                'help_ar' => null,
            ],
            [
                'key' => 'max_score',
                'label_ar' => 'أعلى درجة في المقياس',
                'type' => 'number',
                'default' => '5',
                'help_ar' => null,
            ],
            [
                'key' => 'alert_below_score',
                'label_ar' => 'تنبيه المشرفين عند درجة أقل من',
                'type' => 'number',
                'default' => '3',
                'help_ar' => 'يُسجَّل التقييم المنخفض في سجل الأحداث لمتابعته.',
            ],
        ];
    }
}
