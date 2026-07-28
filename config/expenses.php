<?php

return [
    'default_currency' => env('EXPENSES_DEFAULT_CURRENCY', 'EGP'),
    'currency_label' => env('EXPENSES_CURRENCY_LABEL', 'ج.م'),
    'attachment_max_mb' => (int) env('EXPENSES_ATTACHMENT_MAX_MB', 5),
    'large_expense_warning_amount' => (float) env('EXPENSES_LARGE_WARNING_AMOUNT', 10000),

    'payment_methods' => [
        'cash' => 'نقدي',
        'vodafone_cash' => 'فودافون كاش',
        'instapay' => 'انستاباي',
        'bank_transfer' => 'تحويل بنكي',
        'card' => 'كارت',
        'other' => 'أخرى',
    ],
];
