<?php

return [
    'roles' => [
        'owner' => [
            'name_ar' => 'مالك النظام',
            'name_en' => 'Owner',
            'description_ar' => 'وصول كامل لكل أجزاء النظام وإدارة المستخدمين والصلاحيات والإعدادات الحساسة.',
            'permission_patterns' => ['*'],
            'sort_order' => 10,
        ],
        'administrator' => [
            'name_ar' => 'مدير',
            'name_en' => 'Administrator',
            'description_ar' => 'إدارة التشغيل والمحتوى والطلبات بدون صلاحيات المالك شديدة الحساسية.',
            'permission_patterns' => ['*'],
            'exclude_patterns' => [
                'admin_users.permissions.manage',
                'admin_users.delete',
                'agent_api.tokens.manage',
                'settings.ai_providers.manage_credentials',
                'settings.notifications.manage_credentials',
                'child_identities.force_delete',
            ],
            'sort_order' => 20,
        ],
        'production' => [
            'name_ar' => 'موظف إنتاج',
            'name_en' => 'Production',
            'description_ar' => 'استلام الطلبات وتنفيذ القصص والمنتجات والهويات ورفع ملفات الإنتاج والمعاينات.',
            'permission_patterns' => [
                'orders.view', 'orders.update', 'orders.assign', 'orders.preview.upload',
                'orders.photos.view', 'orders.production_prompt.manage',
                'booklet_previews.*', 'production_studio.*',
                'child_identities.view', 'child_identities.view_media',
                'child_identities.generate', 'child_identities.approve',
            ],
            'exclude_patterns' => [
                'booklet_previews.delete',
                'production_studio.delete_or_cancel',
                'production_studio.settings',
                'production_studio.ai_manage_providers',
                'production_studio.ai_view_costs',
            ],
            'sort_order' => 30,
        ],
        'customer_service' => [
            'name_ar' => 'خدمة عملاء',
            'name_en' => 'Customer Service',
            'description_ar' => 'عرض وإنشاء وتحديث الطلبات والعملاء والرسائل بدون البيانات المالية الحساسة.',
            'permission_patterns' => [
                'orders.view', 'orders.create', 'orders.update', 'orders.assign',
                'customers.view', 'customers.update',
                'content.messages.view', 'content.messages.delete',
            ],
            'sort_order' => 40,
        ],
        'printing' => [
            'name_ar' => 'موظف طباعة',
            'name_en' => 'Printing',
            'description_ar' => 'عرض الطلبات وملفات الطباعة وتحديث حالات الطباعة.',
            'permission_patterns' => [
                'orders.view', 'orders.update', 'orders.assign',
                'booklet_previews.view', 'booklet_previews.download_source',
                'production_studio.view', 'production_studio.layout_download',
            ],
            'sort_order' => 50,
        ],
        'shipping' => [
            'name_ar' => 'موظف شحن',
            'name_en' => 'Shipping',
            'description_ar' => 'عرض الطلبات وتحديث الشحن وإنشاء شحنات وطلبات استلام Bosta وطباعة البوليصات.',
            'permission_patterns' => [
                'orders.view', 'orders.update', 'bosta.*',
            ],
            'sort_order' => 60,
        ],
        'finance' => [
            'name_ar' => 'موظف مالية',
            'name_en' => 'Finance',
            'description_ar' => 'عرض التقارير والإحصاءات وإدارة المصروفات والمدفوعات والخصومات.',
            'permission_patterns' => [
                'dashboard.view', 'dashboard.statistics.view', 'sales_reports.view',
                'order_reports.view', 'orders.view', 'orders.statistics.view',
                'orders.discount.manage', 'expenses.*', 'robodesk.view',
                'robodesk.review_payments', 'robodesk.view_media',
            ],
            'sort_order' => 70,
        ],
        'catalog_content' => [
            'name_ar' => 'إدارة الكتالوج والمحتوى',
            'name_en' => 'Catalog and Content',
            'description_ar' => 'إدارة القصص والمنتجات والتصنيفات ومحتوى الموقع.',
            'permission_patterns' => [
                'stories.*', 'story_categories.*', 'story_attachments.*',
                'store.*', 'content.faqs.*', 'content.testimonials.*',
            ],
            'sort_order' => 80,
        ],
        'read_only' => [
            'name_ar' => 'مشاهدة فقط',
            'name_en' => 'Read Only',
            'description_ar' => 'مشاهدة البيانات التشغيلية الأساسية بدون تعديل أو حذف.',
            'permission_patterns' => [
                'dashboard.view', 'orders.view', 'customers.view', 'stories.view',
                'story_categories.view', 'story_attachments.view',
                'store.categories.view', 'store.products.view',
                'store.homepage_sections.view', 'store.upsell_rules.view',
                'content.faqs.view', 'content.testimonials.view',
            ],
            'sort_order' => 90,
        ],
    ],
];
