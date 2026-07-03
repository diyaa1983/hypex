<?php
declare(strict_types=1);

/**
 * أيقونات SVG لتطبيق الهاتف — مفاتيح في routes_mobile.php (حقل icon).
 */
function mobile_icon_svg(string $key): string
{
    if ($key === 'trash') {
        require_once app_path('includes/app_icons.php');

        return app_icon_svg('trash', 24);
    }

    $stroke = 'stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"';
    $icons = [
        'home' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path ' . $stroke . ' d="M4 10.5L12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1z"/></svg>',
        'invoice' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path ' . $stroke . ' d="M8 3h8l2 2v16H6V3z"/><path ' . $stroke . ' d="M9 11h6M9 15h6M9 7h4"/></svg>',
        'list' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path ' . $stroke . ' d="M8 6h12M8 12h12M8 18h12"/><path ' . $stroke . ' d="M4 6h.01M4 12h.01M4 18h.01" stroke-width="3"/></svg>',
        'ledger' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path ' . $stroke . ' d="M5 5h14v14H5z"/><path ' . $stroke . ' d="M8 9h8M8 13h5M8 17h6"/><path ' . $stroke . ' d="M16 5v3h3"/></svg>',
        'receipt' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path ' . $stroke . ' d="M7 3h10a2 2 0 0 1 2 2v16l-2-1-2 1-2-1-2 1-2-1-2 1V5a2 2 0 0 1 2-2z"/><path ' . $stroke . ' d="M9 8h6M9 12h6M9 16h4"/></svg>',
        'receipt-list' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path ' . $stroke . ' d="M8 4h12v16H8z"/><path ' . $stroke . ' d="M6 6H4v14h2M10 9h8M10 13h6M10 17h4"/></svg>',
        'add' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="9" ' . $stroke . '/><path ' . $stroke . ' d="M12 8v8M8 12h8"/></svg>',
        'save' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path ' . $stroke . ' d="M5 5h12l2 2v12H5z"/><path ' . $stroke . ' d="M8 5v5h8V5"/><path ' . $stroke . ' d="M8 15h8"/></svg>',
        'open' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path ' . $stroke . ' d="M4 7V5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-2"/><path ' . $stroke . ' d="M9 15H5v-4"/><path ' . $stroke . ' d="M14 10l5-5M19 5h-4M19 5v4"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path ' . $stroke . ' d="M4 20h4l10-10-4-4L4 16z"/><path ' . $stroke . ' d="M14 6l4 4"/></svg>',
        'print' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path ' . $stroke . ' d="M7 9V4h10v5"/><path ' . $stroke . ' d="M6 9h12a2 2 0 0 1 2 2v5H4v-5a2 2 0 0 1 2-2z"/><path ' . $stroke . ' d="M6 16v4h12v-4"/></svg>',
        'pdf' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path ' . $stroke . ' d="M8 3h8l2 2v16H6V3z"/><path ' . $stroke . ' d="M8 11h8M8 15h5"/><path ' . $stroke . ' fill="currentColor" stroke="none" d="M9 17h1.2v2H9z"/></svg>',
        'post' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path ' . $stroke . ' d="M5 12l5 5L20 7"/></svg>',
        'einvoice' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path ' . $stroke . ' d="M7 4h10v16H7z"/><path ' . $stroke . ' d="M10 8h4M10 12h4M10 16h2"/><path ' . $stroke . ' d="M4 8h2M4 12h2M4 16h2"/></svg>',
        'run' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="11" cy="11" r="7" ' . $stroke . '/><path ' . $stroke . ' d="M20 20l-3-3"/></svg>',
        'return' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path ' . $stroke . ' d="M9 14L4 9l5-5"/><path ' . $stroke . ' d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>',
        'return-list' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path ' . $stroke . ' d="M8 4h12v16H8z"/><path ' . $stroke . ' d="M6 6H4v14h2M10 9h8M10 13h6"/><path ' . $stroke . ' d="M9 14L6 11l3-3"/></svg>',
        'map-pin' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path ' . $stroke . ' d="M12 21s7-4.5 7-11a7 7 0 1 0-14 0c0 6.5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5" ' . $stroke . '/></svg>',
        'load' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path ' . $stroke . ' d="M12 4v10"/><path ' . $stroke . ' d="M8 10l4 4 4-4"/><path ' . $stroke . ' d="M4 18h16"/></svg>',
        'stock' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path ' . $stroke . ' d="M4 8l8-4 8 4-8 4-8-4z"/><path ' . $stroke . ' d="M4 12l8 4 8-4"/><path ' . $stroke . ' d="M4 16l8 4 8-4"/></svg>',
        'bag' => '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path ' . $stroke . ' d="M8 7V5a4 4 0 0 1 8 0v2"/><path ' . $stroke . ' d="M5 7h14l-1.2 13H6.2L5 7z"/></svg>',
    ];

    return $icons[$key] ?? $icons['invoice'];
}

/** زر شريط الأدوات السفلي مع أيقونة ونص مختصر */
function mobile_toolbar_button_html(string $id, string $label, string $iconKey, string $btnClass = 'm-btn m-toolbar-btn m-btn--ghost'): string
{
    $ico = mobile_icon_svg($iconKey);

    return '<button type="button" class="' . esc($btnClass) . '" id="m-toolbar-' . esc($id) . '" hidden>'
        . '<span class="m-toolbar-btn__ico" aria-hidden="true">' . $ico . '</span>'
        . '<span class="m-toolbar-btn__lbl">' . esc($label) . '</span>'
        . '</button>';
}

/** @return array{html: string, class: string} */
function mobile_icon_tile(string $key): array
{
    $safe = preg_replace('/[^a-z0-9_-]/', '', strtolower($key)) ?: 'invoice';

    return [
        'html' => mobile_icon_svg($safe),
        'class' => 'm-tile-icon--' . $safe,
    ];
}
