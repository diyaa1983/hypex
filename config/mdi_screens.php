<?php
declare(strict_types=1);

/**
 * إعدادات MDI — استثناءات فقط (باقي الشاشات مسموحة تلقائياً).
 */
return [
    'exclude' => [
        'dashboard',
        'menu_hub',
        'favorites_empty',
    ],
];
