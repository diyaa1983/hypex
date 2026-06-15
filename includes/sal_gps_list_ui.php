<?php
declare(strict_types=1);

require_once app_path('includes/date_defaults.php');

/** تاريخ ISO → عرض يوم-شهر-سنة (مثل 01-01-2026). */
function sal_gps_list_format_dmy(string $isoDate): string
{
    return format_date_dmY(trim($isoDate));
}

function sal_gps_list_default_from_dmy(): string
{
    return sal_gps_list_format_dmy(app_default_date_from());
}

function sal_gps_list_default_to_dmy(): string
{
    return sal_gps_list_format_dmy(app_default_date_to());
}

function sal_gps_list_is_submitted(): bool
{
    return isset($_GET['show']) && (string) $_GET['show'] === '1';
}

/**
 * @return array{from_dmy:string,to_dmy:string}
 */
function sal_gps_list_initial_form_dates(): array
{
    return [
        'from_dmy' => sal_gps_list_default_from_dmy(),
        'to_dmy' => sal_gps_list_default_to_dmy(),
    ];
}

/**
 * @return array{from:string,to:string,from_dmy:string,to_dmy:string,error:string}
 */
function sal_gps_list_parse_dates(?string $fromRaw = null, ?string $toRaw = null): array
{
    $fromRaw = trim((string) ($fromRaw ?? ''));
    $toRaw = trim((string) ($toRaw ?? ''));

    if ($fromRaw === '') {
        $fromIso = app_default_date_from();
    } else {
        $fromIso = parse_date_to_iso($fromRaw);
        if ($fromIso === null) {
            return [
                'from' => app_default_date_from(),
                'to' => app_default_date_to(),
                'from_dmy' => sal_gps_list_default_from_dmy(),
                'to_dmy' => sal_gps_list_default_to_dmy(),
                'error' => 'تاريخ البداية غير صالح (يوم-شهر-سنة).',
            ];
        }
    }

    if ($toRaw === '') {
        $toIso = app_default_date_to();
    } else {
        $toIso = parse_date_to_iso($toRaw);
        if ($toIso === null) {
            return [
                'from' => $fromIso,
                'to' => app_default_date_to(),
                'from_dmy' => sal_gps_list_format_dmy($fromIso),
                'to_dmy' => sal_gps_list_default_to_dmy(),
                'error' => 'تاريخ النهاية غير صالح (يوم-شهر-سنة).',
            ];
        }
    }

    if ($fromIso > $toIso) {
        return [
            'from' => $fromIso,
            'to' => $toIso,
            'from_dmy' => sal_gps_list_format_dmy($fromIso),
            'to_dmy' => sal_gps_list_format_dmy($toIso),
            'error' => 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.',
        ];
    }

    return [
        'from' => $fromIso,
        'to' => $toIso,
        'from_dmy' => sal_gps_list_format_dmy($fromIso),
        'to_dmy' => sal_gps_list_format_dmy($toIso),
        'error' => '',
    ];
}

function sal_gps_list_build_query_url(string $route, string $fromDmy, string $toDmy, string $search = ''): string
{
    $q = [
        'r' => $route,
        'show' => '1',
        'date_from' => $fromDmy,
        'date_to' => $toDmy,
    ];
    if (trim($search) !== '') {
        $q['q'] = trim($search);
    }

    return app_url('index.php?' . http_build_query($q));
}

function sal_gps_list_render_filter_form(
    string $route,
    string $search,
    string $fromDmy,
    string $toDmy,
    string $searchPlaceholder = ''
): void {
    ?>
    <form method="get" action="<?= esc(app_url('index.php')) ?>" id="sal-gps-list-filter"
          class="sales-ora-search-form form-row sal-gps-list-filter no-print">
        <input type="hidden" name="r" value="<?= esc($route) ?>">
        <input type="hidden" name="show" value="1">
        <label class="field" style="flex:0 0 9rem;min-width:8rem;">
            <span class="field-label">من تاريخ</span>
            <input class="input js-date-dmy" type="text" name="date_from" value="<?= esc($fromDmy) ?>"
                   placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
        </label>
        <label class="field" style="flex:0 0 9rem;min-width:8rem;">
            <span class="field-label">إلى تاريخ</span>
            <input class="input js-date-dmy" type="text" name="date_to" value="<?= esc($toDmy) ?>"
                   placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
        </label>
        <label class="field" style="flex:1;min-width:200px;">
            <span class="field-label">بحث</span>
            <input class="input" type="search" name="q" value="<?= esc($search) ?>"
                   placeholder="<?= esc($searchPlaceholder) ?>" autocomplete="off">
        </label>
        <button type="submit" class="btn btn-primary btn-sm">عرض</button>
    </form>
    <?php
}

function sal_gps_list_enqueue_print_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $path = app_path('assets/css/report-sales.css');
    $url = app_url('assets/css/report-sales.css');
    if (is_file($path)) {
        $url .= '?v=' . (string) filemtime($path);
    }
    echo '<link rel="stylesheet" href="' . esc($url) . '">' . "\n";
}

function sal_gps_list_render_pending_message(): void
{
    echo '<p class="muted sal-gps-pending-msg" style="padding:0.65rem 0.55rem;text-align:center;font-size:0.78rem;">';
    echo 'حدّد الفترة واضغط «عرض» لعرض البيانات.';
    echo '</p>';
}

function sal_gps_list_print_header_html(string $title, ?PDO $pdo, string $fromDmy, string $toDmy): string
{
    require_once app_path('includes/document_header.php');
    $brand = document_header_brand($pdo);
    $company = esc($brand['company_name_ar']);
    $titleEsc = esc($title);
    $fromEsc = esc($fromDmy);
    $toEsc = esc($toDmy);

    $logoInner = '';
    if ($brand['logo_url'] !== null) {
        $h = DOCUMENT_HEADER_LOGO_MAX_HEIGHT;
        $w = DOCUMENT_HEADER_LOGO_MAX_WIDTH;
        $logoInner = '<img src="' . esc($brand['logo_url']) . '" alt="" style="max-height:' . $h
            . 'px;max-width:' . $w . 'px;width:auto;height:auto;object-fit:contain;display:block;">';
    }

    return '<header class="doc-print-header sal-gps-print-header" role="banner">'
        . '<div class="doc-print-header-top">'
        . '<div class="doc-print-header-brand">'
        . '<div class="doc-print-header-co">' . $company . '</div>'
        . '<div class="doc-print-header-logo">' . $logoInner . '</div>'
        . '</div>'
        . '</div>'
        . '<div class="sal-gps-print-dates" dir="rtl">'
        . '<div class="sal-gps-print-dates__row">'
        . '<span class="sal-gps-print-dates__chip">'
        . '<span class="sal-gps-print-dates__label">من تاريخ</span>'
        . '<span class="sal-gps-print-dates__value" dir="ltr">' . $fromEsc . '</span>'
        . '</span>'
        . '<span class="sal-gps-print-dates__sep" aria-hidden="true">|</span>'
        . '<span class="sal-gps-print-dates__chip">'
        . '<span class="sal-gps-print-dates__label">إلى تاريخ</span>'
        . '<span class="sal-gps-print-dates__value" dir="ltr">' . $toEsc . '</span>'
        . '</span>'
        . '</div>'
        . '</div>'
        . '<div class="doc-print-header-title">' . $titleEsc . '</div>'
        . '</header>';
}

function sal_gps_list_render_print_meta(int $rowCount, string $search = ''): void
{
    $search = trim($search);
    echo '<div class="doc-print-meta sal-gps-print-meta"><table>';
    echo '<tr><td><strong>عدد السجلات:</strong> ' . (int) $rowCount . '</td></tr>';
    if ($search !== '') {
        echo '<tr><td><strong>بحث:</strong> ' . esc($search) . '</td></tr>';
    }
    echo '</table></div>';
}
