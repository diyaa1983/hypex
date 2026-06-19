<?php
declare(strict_types=1);

/** ارتفاع الشعار الأقصى في الترويسة (بكسل) — يُطبَّق في CSS أيضاً */
const DOCUMENT_HEADER_LOGO_MAX_HEIGHT = 130;
const DOCUMENT_HEADER_LOGO_MAX_WIDTH = 130;
const DOCUMENT_HEADER_COMPANY_FONT_SIZE = '1.1rem';
/** شفافية علامة مائية الشعار (0–1) — أقل = أخف */
const DOCUMENT_WATERMARK_OPACITY = 0.04;

/**
 * ترويسة موحدة: اسم الشركة بجانب الشعار على سطر واحد، ثم خط، ثم عنوان التقرير.
 *
 * @return array{company_name_ar: string, logo_url: ?string}
 */
function document_header_brand(?PDO $pdo = null): array
{
    $settings = company_settings($pdo);
    $name = trim((string) ($settings['company_name_ar'] ?? ''));
    if ($name === '') {
        $name = 'الشركة';
    }

    $logoUrl = null;
    $logoPath = trim((string) ($settings['logo_path'] ?? ''));
    if ($logoPath !== '' && is_file(app_path($logoPath))) {
        $logoUrl = app_url($logoPath);
    }

    return [
        'company_name_ar' => $name,
        'logo_url' => $logoUrl,
    ];
}

/** HTML ترويسة الطباعة. */
function document_print_header_html(string $title, ?PDO $pdo = null, ?string $subtitle = null): string
{
    $brand = document_header_brand($pdo);
    $company = esc($brand['company_name_ar']);
    $titleEsc = esc($title);
    $subtitleHtml = '';
    $sub = $subtitle !== null ? trim($subtitle) : '';
    if ($sub !== '') {
        $subtitleHtml = '<div class="doc-print-header-subtitle">' . esc($sub) . '</div>';
    }

    $logoInner = '';
    if ($brand['logo_url'] !== null) {
        $h = DOCUMENT_HEADER_LOGO_MAX_HEIGHT;
        $w = DOCUMENT_HEADER_LOGO_MAX_WIDTH;
        $logoInner = '<img src="' . esc($brand['logo_url']) . '" alt="" style="max-height:' . $h
            . 'px;max-width:' . $w . 'px;width:auto;height:auto;object-fit:contain;display:block;">';
    }

    return '<header class="doc-print-header" role="banner">'
        . '<div class="doc-print-header-top">'
        . '<div class="doc-print-header-brand">'
        . '<div class="doc-print-header-co">' . $company . '</div>'
        . '<div class="doc-print-header-logo">' . $logoInner . '</div>'
        . '</div>'
        . '</div>'
        . '<div class="doc-print-header-title">' . $titleEsc . '</div>'
        . $subtitleHtml
        . '</header>';
}

/** رابط شعار الشركة للعلامة المائية (null إن لم يُرفع شعار). */
function document_print_watermark_logo_url(?PDO $pdo = null): ?string
{
    return document_header_brand($pdo)['logo_url'];
}

/** متغير CSS لشعار الخلفية عند الطباعة — يُضاف في ترويسة الصفحة. */
function document_print_watermark_root_css(?PDO $pdo = null): string
{
    $url = document_print_watermark_logo_url($pdo);
    if ($url === null) {
        return '';
    }
    $safe = str_replace(['\\', '"'], ['\\\\', '\\"'], $url);

    $opacity = (string) DOCUMENT_WATERMARK_OPACITY;

    return ':root{--doc-watermark-logo:url("' . $safe . '");--doc-watermark-opacity:' . $opacity . ';}';
}

/** أنماط الترويسة للنوافذ المنبثقة وإطارات الطباعة (JS). */
function document_print_header_css(): string
{
    $h = DOCUMENT_HEADER_LOGO_MAX_HEIGHT;
    $w = DOCUMENT_HEADER_LOGO_MAX_WIDTH;
    $coSize = DOCUMENT_HEADER_COMPANY_FONT_SIZE;

    return '.doc-print-header{margin-top:0;margin-bottom:0.65rem;padding-top:0;}'
        . '.doc-print-header-top{padding-top:0;padding-bottom:0.5rem;border-bottom:1px solid #cbd5e1;}'
        . '.doc-print-header-brand{display:flex;flex-direction:row;align-items:center;justify-content:space-between;'
        . 'width:100%;gap:0.75rem;flex-wrap:wrap;direction:rtl;}'
        . '.doc-print-header-co{flex:1 1 auto;min-width:0;font-family:Arial,Helvetica,sans-serif;font-weight:800;font-size:'
        . $coSize . ';color:#0f172a;text-align:start;line-height:1.3;}'
        . '.doc-print-header-logo{display:flex;align-items:center;justify-content:flex-end;flex-shrink:0;overflow:visible;padding:2px 0;}'
        . '.doc-print-header-logo img{max-height:' . $h . 'px;max-width:' . $w . 'px;width:auto;height:auto;object-fit:contain;display:block;}'
        . '.doc-print-header-title{text-align:center;font-family:Arial,Helvetica,sans-serif;font-weight:700;font-size:1.1rem;color:#1e293b;'
        . 'padding-top:0.45rem;margin:0;}'
        . '.doc-print-header-subtitle{text-align:center;font-family:Arial,Helvetica,sans-serif;font-weight:700;font-size:1rem;'
        . 'color:#334155;padding-top:0.2rem;margin:0 0 0.15rem;}'
        . '.doc-print-meta{margin:0.35rem 0 0.65rem;font-size:12px;font-weight:700;color:#334155;line-height:1.55;text-align:start;direction:rtl;}'
        . '.doc-print-meta table{width:100%;border-collapse:collapse;}'
        . '.doc-print-meta td{padding:0.2rem 0;border:none!important;text-align:start!important;font-weight:700;}'
        . '.doc-print-meta-value--party{font-weight:800;font-size:1.12em;color:#0f172a;}'
        . document_print_user_footer_css()
        . document_print_signature_css();
}

/** أنماط علامة مائية الشعار (مضمّنة أيضاً في document-header.css). */
function document_print_watermark_css(): string
{
    return '.has-doc-watermark,.doc-print-watermark-scope,.doc-print-watermark-root{position:relative;}'
        . 'body.has-doc-watermark .main-bg-logo{visibility:hidden;}'
        . 'body.has-doc-watermark .card:has(.report-sales-print-area),body.has-doc-watermark .report-sales-result{overflow:visible;}'
        . 'body.has-doc-watermark::after{content:"";position:fixed;inset:0;width:100%;height:100%;margin:0;'
        . 'background-image:var(--doc-watermark-logo);background-repeat:no-repeat;background-position:center center;'
        . 'background-size:min(72vw,460px) auto;opacity:var(--doc-watermark-opacity,' . DOCUMENT_WATERMARK_OPACITY . ');z-index:50;pointer-events:none;}'
        . 'body.doc-print-standalone::after{display:none;}'
        . '.doc-print-watermark--overlay{position:absolute;inset:0;z-index:1000;display:flex;align-items:center;'
        . 'justify-content:center;pointer-events:none;overflow:visible;min-height:100%;}'
        . '.doc-print-watermark--overlay img{width:min(72%,460px);max-width:460px;height:auto;'
        . 'max-height:min(62vh,440px);object-fit:contain;opacity:var(--doc-watermark-opacity,' . DOCUMENT_WATERMARK_OPACITY . ');filter:grayscale(0.12) contrast(0.92);}'
        . '@media print{'
        . 'body.has-doc-watermark::after{display:block!important;position:fixed!important;inset:0!important;'
        . 'width:100%!important;height:100%!important;transform:none!important;z-index:9999;'
        . '-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . 'body.doc-print-standalone::after{display:block!important;}'
        . '.doc-print-watermark--overlay{display:none!important;}'
        . '.doc-print-watermark:not(.doc-print-watermark--overlay){display:none!important;}'
        . '}';
}

/** عنصر علامة مائية داخل منطقة طباعة (معاينة/تصدير عند الحاجة). */
function document_print_watermark_html(?PDO $pdo = null): string
{
    $url = document_print_watermark_logo_url($pdo);
    if ($url === null) {
        return '';
    }

    return '<div class="doc-print-watermark" aria-hidden="true">'
        . '<img src="' . esc($url) . '" alt="">'
        . '</div>';
}

/** أنماط توقيع المستلم (إطار الطباعة / JS). */
function document_print_signature_css(): string
{
    return '.doc-print-signature-block{margin-top:2.25rem;padding-top:0.5rem;page-break-inside:avoid;max-width:280px;margin-inline-start:auto;margin-inline-end:0;}'
        . '.doc-print-signature-label{display:block;font-weight:700;font-size:13px;margin:0 0 0.25rem;color:#0f172a;}'
        . '.doc-print-signature-line{display:block;border-bottom:1.5px solid #334155;height:0;margin:2.5rem 0 0;}';
}

/** HTML مكان توقيع المستلم في الطباعة. */
function document_print_recipient_signature_html(): string
{
    return '<div class="doc-print-signature-block" role="group" aria-label="توقيع المستلم">'
        . '<span class="doc-print-signature-label">توقيع المستلم</span>'
        . '<span class="doc-print-signature-line" aria-hidden="true"></span>'
        . '</div>';
}

/** اسم المستخدم الحالي للطباعة (الاسم الكامل أو اسم الدخول). */
function document_print_user_label(): string
{
    if (!function_exists('current_user')) {
        return '';
    }
    $user = current_user();
    if (!$user) {
        return '';
    }
    $name = trim((string) ($user['full_name_ar'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($user['username'] ?? ''));
    }

    return $name;
}

/** أنماط تذييل اسم المستخدم في الطباعة. */
function document_print_user_footer_css(): string
{
    return '.doc-print-user-footer{display:none;}'
        . '@media print{'
        . '.doc-print-user-footer{display:block!important;position:fixed;bottom:5mm;left:0;right:0;'
        . 'margin:0;padding:1px 8px 2px;font-family:Arial,Helvetica,sans-serif;font-size:7pt;'
        . 'font-weight:400;line-height:1.2;color:#94a3b8;text-align:left;direction:rtl;'
        . 'z-index:10001;pointer-events:none;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . '}';
}

/** HTML تذييل الطباعة — اسم المستخدم الذي قام بالطباعة. */
function document_print_user_footer_html(?string $label = null): string
{
    if ($label === null) {
        $label = document_print_user_label();
    }
    $label = trim($label);
    if ($label === '') {
        return '';
    }

    return '<footer class="doc-print-user-footer doc-print-only" aria-hidden="true">'
        . 'طبع بواسطة: ' . esc($label)
        . '</footer>';
}

/** رابط ملف CSS للطباعة مع إصدار التعديل. */
function document_print_stylesheet_url(string $relativePath): string
{
    $path = app_path($relativePath);
    $url = app_url($relativePath);
    if (is_file($path)) {
        $url .= '?v=' . (string) filemtime($path);
    }

    return $url;
}

/**
 * صفحة طباعة مستقلة (نافذة جديدة / PDF) بنفس تنسيق تقارير المبيعات.
 */
function document_print_emit_standalone_page(
    string $pageTitle,
    string $contentHtml,
    ?PDO $pdo = null,
    bool $autoPrint = true
): never {
    $docCss = document_print_stylesheet_url('assets/css/document-header.css');
    $reportCss = document_print_stylesheet_url('assets/css/report-sales.css');
    $wmCss = document_print_watermark_root_css($pdo);
    $hasWatermark = document_print_watermark_logo_url($pdo) !== null;
    $bodyClass = 'doc-print-standalone' . ($hasWatermark ? ' has-doc-watermark' : '');

    $wrapped =
        '<div class="doc-print-watermark-root doc-print-watermark-scope report-sales-print-area report-sales-result">'
        . document_print_watermark_html($pdo)
        . $contentHtml
        . '</div>'
        . document_print_user_footer_html();

    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8">';
    echo '<title>' . esc($pageTitle) . '</title>';
    echo '<link rel="stylesheet" href="' . esc($docCss) . '">';
    echo '<link rel="stylesheet" href="' . esc($reportCss) . '">';
    if ($wmCss !== '') {
        echo '<style>' . $wmCss . '</style>';
    }
    $printUser = document_print_user_label();
    if ($printUser !== '') {
        echo '<script>window.__PRINT_USER__=' . json_encode($printUser, JSON_UNESCAPED_UNICODE) . ';</script>';
    }
    echo '<style>body{margin:0;padding:1rem 1.25rem 1.5rem;background:#fff;font-family:Arial,Helvetica,sans-serif;}'
        . '@media print{body{margin:0;padding:0.35rem 0.5rem 0;}}</style>';
    echo '</head><body class="' . esc($bodyClass) . '">';
    echo $wrapped;
    if ($autoPrint) {
        echo '<script>window.onload=function(){window.print();};</script>';
    }
    echo '</body></html>';
    exit;
}
