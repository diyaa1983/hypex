<?php
declare(strict_types=1);

/**
 * أيقونات SVG مشتركة (سطح المكتب والموبايل).
 */
function app_icon_svg(string $key, int $size = 24): string
{
    $w = max(12, min(48, $size));
    $h = $w;
    $stroke = 'stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"';
    $icons = [
        'trash' => '<svg viewBox="0 0 24 24" width="' . $w . '" height="' . $h . '" aria-hidden="true">'
            . '<path ' . $stroke . ' d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>'
            . '<path ' . $stroke . ' d="M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12"/>'
            . '<path ' . $stroke . ' d="M10 11v5M14 11v5"/>'
            . '</svg>',
        'bell' => '<svg viewBox="0 0 24 24" width="' . $w . '" height="' . $h . '" aria-hidden="true">'
            . '<path ' . $stroke . ' d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>'
            . '<path ' . $stroke . ' d="M13.73 21a2 2 0 0 1-3.46 0"/>'
            . '</svg>',
    ];

    return $icons[$key] ?? '';
}
