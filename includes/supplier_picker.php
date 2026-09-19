<?php
declare(strict_types=1);

/** @return list<array<string, mixed>> */
function crm_suppliers_for_picker(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, code, name_ar FROM crm_supplier WHERE is_active = 1 ORDER BY name_ar'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @param list<array<string, mixed>> $suppliers */
function crm_suppliers_picker_json(array $suppliers): string
{
    $rows = array_map(static function (array $s): array {
        return [
            'id' => (int) ($s['id'] ?? 0),
            'code' => (string) ($s['code'] ?? ''),
            'name_ar' => (string) ($s['name_ar'] ?? ''),
        ];
    }, $suppliers);

    return json_encode($rows, JSON_UNESCAPED_UNICODE) ?: '[]';
}

function supplier_picker_assets_urls(): array
{
    $cssPath = app_path('assets/css/customer-picker-modal.css');
    $jsPath = app_path('assets/js/supplier-picker-modal.js');

    return [
        'css' => app_url('assets/css/customer-picker-modal.css')
            . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : ''),
        'js' => app_url('assets/js/supplier-picker-modal.js')
            . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : ''),
    ];
}

function supplier_picker_enqueue_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $u = supplier_picker_assets_urls();
    echo '<link rel="stylesheet" href="' . esc($u['css']) . '">' . "\n";
    echo '<script src="' . esc($u['js']) . '" defer></script>' . "\n";
    supplier_picker_modal_once();
}

function supplier_picker_modal_once(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<div id="app-supplier-picker-modal" class="sales-inv-pick-dropdown no-print" hidden role="dialog"
     aria-label="اختيار مورد" aria-modal="true">
    <div class="sales-inv-pick-head">
        <span class="sales-inv-pick-title">اختيار مورد</span>
        <button type="button" class="sales-inv-pick-close" id="app-supplier-picker-close" aria-label="إغلاق">×</button>
    </div>
    <input type="search" class="input" id="app-supplier-picker-search"
           placeholder="بحث: اسم المورد أو الرمز" autocomplete="off">
    <div class="sales-inv-pick-results" id="app-supplier-picker-results"></div>
    <div class="sales-inv-pick-foot sales-inv-cust-pick-foot">
        <span class="sales-inv-pick-hint">انقر على اسم المورد للاختيار — أو ابحث ثم اختر</span>
    </div>
</div>
    <?php
}

/**
 * @param array{
 *   name?: string|null,
 *   id: string,
 *   value?: int|string,
 *   open_id?: string,
 *   display_id?: string,
 *   label?: string,
 *   label_for?: string,
 *   placeholder?: string,
 *   wrapper_class?: string,
 *   allow_all?: bool,
 *   json_id?: string,
 *   manual_bind?: bool,
 *   wrapper_style?: string,
 * } $opts
 */
function supplier_picker_field(array $opts): string
{
    $hiddenId = (string) ($opts['id'] ?? 'supplier_id');
    $openId = (string) ($opts['open_id'] ?? $hiddenId . '_open');
    $displayId = (string) ($opts['display_id'] ?? $hiddenId . '_display');
    $name = array_key_exists('name', $opts) ? $opts['name'] : 'supplier_id';
    $rawVal = $opts['value'] ?? 0;
    if ($rawVal === '' || $rawVal === null) {
        $value = -1;
    } else {
        $value = (int) $rawVal;
    }
    $label = (string) ($opts['label'] ?? 'المورد');
    $labelFor = (string) ($opts['label_for'] ?? $openId);
    $placeholder = (string) ($opts['placeholder'] ?? 'اضغط لاختيار المورد');
    $wrapperClass = trim('supplier-picker-slot ' . (string) ($opts['wrapper_class'] ?? ''));
    $allowAll = !empty($opts['allow_all']);
    $jsonId = (string) ($opts['json_id'] ?? 'app-suppliers-json');
    $manualBind = !empty($opts['manual_bind']);
    $wrapStyle = (string) ($opts['wrapper_style'] ?? '');

    $hiddenVal = '';
    if ($allowAll && $value === 0) {
        $hiddenVal = '0';
    } elseif ($value > 0) {
        $hiddenVal = (string) (int) $value;
    }
    $attrStr = 'id="' . esc($hiddenId) . '" value="' . esc($hiddenVal) . '" data-supp-id';
    if ($name !== null && $name !== '') {
        $attrStr = 'name="' . esc((string) $name) . '" ' . $attrStr;
    }

    $dataAttrs = '';
    if (!$manualBind) {
        $dataAttrs =
            ' data-supplier-picker'
            . ' data-hidden-id="' . esc($hiddenId) . '"'
            . ' data-open-id="' . esc($openId) . '"'
            . ' data-display-id="' . esc($displayId) . '"'
            . ' data-placeholder="' . esc($placeholder) . '"'
            . ' data-initial="' . ($value >= 0 ? (int) $value : '') . '"'
            . ($allowAll ? ' data-allow-all="1"' : '')
            . ' data-json-id="' . esc($jsonId) . '"';
    }

    $wrap = $wrapperClass !== 'supplier-picker-slot' ? $wrapperClass : 'field';
    $html = '<div class="' . esc(trim($wrap)) . '"' . $dataAttrs;
    if ($wrapStyle !== '') {
        $html .= ' style="' . esc($wrapStyle) . '"';
    }
    $html .= '>';
    if ($label !== '') {
        $html .= '<label for="' . esc($labelFor) . '">' . esc($label) . '</label>';
    }
    $html .= '<input type="hidden" ' . $attrStr . '>';
    $html .= '<button type="button" class="sales-inv-cust-open input" id="' . esc($openId) . '" title="اختيار المورد">';
    $html .= '<span id="' . esc($displayId) . '" class="sales-inv-cust-open-label is-placeholder">' . esc($placeholder) . '</span>';
    $html .= '<span class="sales-inv-cust-open-ico" aria-hidden="true">▾</span>';
    $html .= '</button></div>';

    return $html;
}

/** @param list<array<string, mixed>> $suppliers */
function supplier_picker_json_script(array $suppliers, string $elementId = 'app-suppliers-json'): void
{
    echo '<script type="application/json" id="' . esc($elementId) . '">'
        . crm_suppliers_picker_json($suppliers)
        . '</script>' . "\n";
}
