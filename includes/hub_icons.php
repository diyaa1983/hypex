<?php
declare(strict_types=1);

/**
 * أيقونات SVG للقائمة الجانبية وبطاقات المجلدات/الشاشات.
 */

/** @return array{key:string,tone:string} */
function hub_icon_resolve(string $idOrRoute, string $title = '', bool $isFolder = false): array
{
    $id = strtolower(trim($idOrRoute));
    $title = mb_strtolower(trim($title), 'UTF-8');
    $hay = $id . ' ' . $title;

    $exact = [
        'main' => ['grid', 'blue'],
        'sales' => ['sales', 'blue'],
        'sales_reps' => ['badge', 'indigo'],
        'customers' => ['users', 'blue'],
        'purchases' => ['cart', 'violet'],
        'inventory' => ['warehouse', 'amber'],
        'accounting' => ['ledger', 'emerald'],
        'hr' => ['users', 'rose'],
        'system' => ['settings', 'slate'],
        'mobile' => ['phone', 'cyan'],
        'favorites' => ['star', 'amber'],
        'system_backup' => ['backup', 'slate'],
        'sales_delivery' => ['truck', 'indigo'],
        'sales_returns' => ['undo', 'orange'],
        'purchase_orders' => ['cart', 'violet'],
        'finance' => ['wallet', 'emerald'],
        'general' => ['grid', 'blue'],
        'dashboard_widgets' => ['chart', 'blue'],
        'stocktaking' => ['boxes', 'amber'],
        'price_adjust' => ['percent', 'orange'],
    ];
    if (isset($exact[$id])) {
        return ['key' => $exact[$id][0], 'tone' => $exact[$id][1]];
    }

    $arMap = [
        'تسليم' => ['truck', 'indigo'],
        'مرتجع' => ['undo', 'orange'],
        'تقارير' => ['chart', 'teal'],
        'مبيعات' => ['sales', 'blue'],
        'مشتريات' => ['cart', 'violet'],
        'عملاء' => ['users', 'blue'],
        'مندوب' => ['badge', 'indigo'],
        'مستودع' => ['warehouse', 'amber'],
        'محاسبة' => ['ledger', 'emerald'],
        'مالية' => ['wallet', 'emerald'],
        'موظف' => ['badge', 'rose'],
        'حضور' => ['clock', 'rose'],
        'بصم' => ['clock', 'rose'],
        'نظام' => ['settings', 'slate'],
        'شيك' => ['cheque', 'teal'],
        'صندوق' => ['wallet', 'emerald'],
        'قيد' => ['ledger', 'indigo'],
        'ضريب' => ['percent', 'orange'],
        'فاتورة' => ['invoice', 'blue'],
        'جرد' => ['boxes', 'amber'],
        'مفضلة' => ['star', 'amber'],
        'نسخة' => ['backup', 'slate'],
        'احتياط' => ['backup', 'slate'],
    ];
    foreach ($arMap as $needle => [$key, $tone]) {
        if (str_contains($title, $needle) || str_contains($hay, $needle)) {
            return ['key' => $key, 'tone' => $tone];
        }
    }

    $map = [
        'operations' => ['sales', 'blue'],
        'delivery' => ['truck', 'indigo'],
        'return' => ['undo', 'orange'],
        'report' => ['chart', 'teal'],
        'purchase' => ['cart', 'violet'],
        'customer' => ['users', 'blue'],
        'warehouse' => ['warehouse', 'amber'],
        'inventory' => ['boxes', 'amber'],
        'account' => ['ledger', 'emerald'],
        'finance' => ['wallet', 'emerald'],
        'employee' => ['badge', 'rose'],
        'attendance' => ['clock', 'rose'],
        'setting' => ['settings', 'slate'],
        'user' => ['user', 'slate'],
        'mobile' => ['phone', 'cyan'],
        'favorite' => ['star', 'amber'],
        'dashboard' => ['grid', 'blue'],
        'backup' => ['backup', 'slate'],
        'check' => ['cheque', 'teal'],
        'invoice' => ['invoice', 'blue'],
        'journal' => ['ledger', 'indigo'],
        'tax' => ['percent', 'orange'],
        'stock' => ['boxes', 'amber'],
    ];
    foreach ($map as $needle => [$key, $tone]) {
        if ($id === $needle || str_contains($id, $needle)) {
            return ['key' => $key, 'tone' => $tone];
        }
    }

    return ['key' => $isFolder ? 'folder' : 'file', 'tone' => $isFolder ? 'blue' : 'slate'];
}

function hub_icon_svg(string $key, int $size = 28): string
{
    $s = max(16, min(48, $size));
    $attrs = 'viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"';
    $paths = [
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'file' => '<path d="M8 3h6l4 4v14H8z"/><path d="M14 3v4h4"/><path d="M10 12h6M10 16h4"/>',
        'sales' => '<path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21V3z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'invoice' => '<path d="M7 3h10v18l-1.7-1.2L14 21l-1.7-1.2L10.7 21 9 19.8 7 21V3z"/><path d="M10 8h4M10 12h4M10 16h3"/>',
        'chart' => '<path d="M4 19h16"/><path d="M7 16V10"/><path d="M12 16V6"/><path d="M17 16v-4"/>',
        'truck' => '<path d="M3 7h11v10H3z"/><path d="M14 10h4l3 3v4h-7z"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="17.5" cy="17.5" r="1.5"/>',
        'undo' => '<path d="M9 14L4 9l5-5"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>',
        'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="17" cy="20" r="1.5"/><path d="M3 4h2l2.4 11h10.2l2-7H7"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 19c0-3 2.7-5 6-5s6 2 6 5"/><path d="M15.5 19c.3-2 1.8-3.5 4-3.8"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c1.5-3.5 4-5 7-5s5.5 1.5 7 5"/>',
        'building' => '<path d="M4 20V6l8-3 8 3v14"/><path d="M9 20v-6h6v6"/><path d="M9 10h.01M15 10h.01"/>',
        'warehouse' => '<path d="M3 10l9-6 9 6v10H3z"/><path d="M9 20v-6h6v6"/>',
        'boxes' => '<path d="M4 8l8-4 8 4-8 4-8-4z"/><path d="M4 12l8 4 8-4"/><path d="M4 16l8 4 8-4"/>',
        'ledger' => '<path d="M5 4h14v16H5z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'wallet' => '<path d="M3 8h18v11H3z"/><path d="M3 8l2-4h14l2 4"/><circle cx="16.5" cy="13.5" r="1.2"/>',
        'badge' => '<path d="M12 3l2.2 4.4L19 8.2l-3.4 3.3.8 4.7L12 14.5 7.6 16.2l.8-4.7L5 8.2l4.8-.8z"/>',
        'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2M12 19v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M3 12h2M19 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'phone' => '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>',
        'star' => '<path d="M12 3l2.5 6.2L21 10l-4.5 4.1L18 21l-6-3.4L6 21l1.5-6.9L3 10l6.5-.8z"/>',
        'grid' => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
        'cheque' => '<path d="M3 7h18v10H3z"/><path d="M7 12h6M15 12h2"/><path d="M7 15h4"/>',
        'percent' => '<circle cx="8" cy="8" r="2"/><circle cx="16" cy="16" r="2"/><path d="M7 17L17 7"/>',
        'backup' => '<path d="M12 3v10"/><path d="M8 9l4 4 4-4"/><path d="M5 17h14v3H5z"/>',
    ];

    return '<svg ' . $attrs . '>' . ($paths[$key] ?? $paths['file']) . '</svg>';
}

function hub_icon_html(string $idOrRoute, string $title = '', bool $isFolder = false, int $size = 0): string
{
    $meta = hub_icon_resolve($idOrRoute, $title, $isFolder);
    $iconSize = $size > 0 ? $size : ($isFolder ? 28 : 24);
    $svg = hub_icon_svg($meta['key'], $iconSize);

    return '<span class="hub-ico hub-ico--' . esc($meta['tone']) . ($isFolder ? ' hub-ico--folder' : '') . '" aria-hidden="true">'
        . $svg
        . '</span>';
}
