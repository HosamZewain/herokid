<?php

namespace App\Services\RoboDesk;

class TemplateDelivery
{
    public static function normalize(mixed $delivery): array
    {
        $status = is_array($delivery) ? ($delivery['status'] ?? null) : null;

        // Do not infer delivery from the legacy message status or expose provider
        // reason text, which can contain customer/provider data.
        return ['status' => in_array($status, ['sent', 'delivered', 'failed'], true) ? $status : 'unknown',
            'failureCategory' => $status === 'failed' && ($delivery['failureCategory'] ?? null) === 'Unreachable' ? 'Unreachable' : null];
    }

    public static function label(?string $status): string
    {
        return ['sent' => 'تم الإرسال — لم يثبت الوصول', 'delivered' => 'وصلت للعميل',
            'failed' => 'فشل التسليم — تواصل بقناة أخرى'][$status ?? ''] ?? 'التسليم غير معروف';
    }
}
