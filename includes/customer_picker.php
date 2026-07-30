<?php
declare(strict_types=1);

/** @return list<array<string, mixed>> */
function crm_customers_for_picker(PDO $pdo, bool $withSalesRep = true): array
{
    if ($withSalesRep) {
        require_once app_path('includes/crm_sales_rep_schema.php');
        crm_sales_rep_ensure_customer_invoice_links($pdo);
        $sql = 'SELECT c.id, c.code, c.name_ar, c.sales_rep_id, r.name_ar AS sales_rep_name
                FROM crm_customer c
                LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id AND r.is_active = 1
                WHERE c.is_active = 1
                ORDER BY c.name_ar';
        $customers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return crm_customers_attach_sales_reps($pdo, $customers);
    }

    $sql = 'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @param list<array<string, mixed>> $customers */
function crm_customers_picker_json(array $customers): string
{
    $rows = array_map(static function (array $c): array {
        $reps = [];
        foreach ($c['sales_reps'] ?? [] as $rep) {
            if (!is_array($rep)) {
                continue;
            }
            $reps[] = [
                'id' => (int) ($rep['id'] ?? 0),
                'name_ar' => (string) ($rep['name_ar'] ?? ''),
            ];
        }

        return [
            'id' => (int) ($c['id'] ?? 0),
            'code' => (string) ($c['code'] ?? ''),
            'name_ar' => (string) ($c['name_ar'] ?? ''),
            'sales_rep_id' => (int) ($c['sales_rep_id'] ?? 0),
            'sales_rep_name' => (string) ($c['sales_rep_name'] ?? ''),
            'sales_reps' => $reps,
        ];
    }, $customers);

    return json_encode($rows, JSON_UNESCAPED_UNICODE) ?: '[]';
}

function customer_picker_assets_urls(): array
{
    $cssPath = app_path('assets/css/customer-picker-modal.css');
    $jsPath = app_path('assets/js/customer-picker-modal.js');
    $oraCssPath = app_path('assets/css/picker-oracle-forms.css');

    return [
        'css' => app_url('assets/css/customer-picker-modal.css')
            . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : ''),
        'ora_css' => app_url('assets/css/picker-oracle-forms.css')
            . (is_file($oraCssPath) ? '?v=' . (string) filemtime($oraCssPath) : ''),
        'js' => app_url('assets/js/customer-picker-modal.js')
            . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : ''),
    ];
}

function picker_oracle_forms_enqueue_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $u = customer_picker_assets_urls();
    echo '<link rel="stylesheet" href="' . esc($u['ora_css']) . '">' . "\n";
}

function customer_picker_enqueue_assets(bool $deferOracle = false): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $u = customer_picker_assets_urls();
    echo '<link rel="stylesheet" href="' . esc($u['css']) . '">' . "\n";
    echo '<script src="' . esc($u['js']) . '" defer></script>' . "\n";
    customer_picker_modal_once();
    if (!$deferOracle) {
        picker_oracle_forms_enqueue_assets();
    }
}

function customer_picker_modal_once(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<div id="app-customer-picker-modal" class="sales-inv-pick-dropdown hr-ora-pick-modal no-print" hidden role="dialog"
     aria-label="اختيار عميل" aria-modal="true">
    <div class="sales-inv-pick-head">
        <span class="sales-inv-pick-title">اختيار عميل</span>
        <button type="button" class="sales-inv-pick-close" id="app-customer-picker-close" aria-label="إغلاق">×</button>
    </div>
    <input type="search" class="input" id="app-customer-picker-search"
           placeholder="بحث: اسم العميل أو الرمز" autocomplete="off">
    <div class="sales-inv-pick-list-head" aria-hidden="true">
        <span>اسم العميل</span>
        <span>الرمز</span>
    </div>
    <div class="sales-inv-pick-results" id="app-customer-picker-results"></div>
    <div class="sales-inv-pick-foot sales-inv-cust-pick-foot">
        <span class="sales-inv-pick-hint">انقر على اسم العميل للاختيار — أو ابحث ثم اختر</span>
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
 *   required?: bool,
 *   placeholder?: string,
 *   compact?: bool,
 *   wrapper_class?: string,
 *   hidden_attrs?: array<string, string>,
 *   allow_all?: bool,
 *   json_id?: string,
 *   manual_bind?: bool,
 *   wrapper_style?: string,
 * } $opts
 */
function customer_picker_field(array $opts): string
{
    $hiddenId = (string) ($opts['id'] ?? 'customer_id');
    $openId = (string) ($opts['open_id'] ?? $hiddenId . '_open');
    $displayId = (string) ($opts['display_id'] ?? $hiddenId . '_display');
    $name = array_key_exists('name', $opts) ? $opts['name'] : 'customer_id';
    $rawVal = $opts['value'] ?? 0;
    if ($rawVal === '' || $rawVal === null) {
        $value = -1;
    } else {
        $value = (int) $rawVal;
    }
    $label = (string) ($opts['label'] ?? 'العميل');
    $labelFor = (string) ($opts['label_for'] ?? $openId);
    $placeholder = (string) ($opts['placeholder'] ?? 'اضغط لاختيار العميل');
    $compact = !empty($opts['compact']);
    $wrapperClass = trim('customer-picker-slot ' . (string) ($opts['wrapper_class'] ?? ''));
    $required = !empty($opts['required']);
    $allowAll = !empty($opts['allow_all']);
    $jsonId = (string) ($opts['json_id'] ?? 'app-customers-json');
    $manualBind = !empty($opts['manual_bind']);
    $wrapStyle = (string) ($opts['wrapper_style'] ?? '');

    $hiddenAttrs = $opts['hidden_attrs'] ?? [];
    if (!is_array($hiddenAttrs)) {
        $hiddenAttrs = [];
    }

    $hiddenVal = '';
    if ($allowAll && $value === 0) {
        $hiddenVal = '0';
    } elseif ($value > 0) {
        $hiddenVal = (string) (int) $value;
    }
    $attrStr = 'id="' . esc($hiddenId) . '" value="' . esc($hiddenVal) . '"';
    if ($name !== null && $name !== '') {
        $attrStr = 'name="' . esc((string) $name) . '" ' . $attrStr;
    }
    foreach ($hiddenAttrs as $k => $v) {
        $attrStr .= ' ' . esc((string) $k) . '="' . esc((string) $v) . '"';
    }
    if (!isset($hiddenAttrs['data-cust-id'])) {
        $attrStr .= ' data-cust-id';
    }

    $btnClass = 'sales-inv-cust-open input' . ($compact ? ' input-compact' : '');
    $metaClass = $compact ? 'sales-inv-meta-item sales-inv-meta-customer' : 'field';

    $dataAttrs = '';
    if (!$manualBind) {
        $dataAttrs =
            ' data-customer-picker'
            . ' data-hidden-id="' . esc($hiddenId) . '"'
            . ' data-open-id="' . esc($openId) . '"'
            . ' data-display-id="' . esc($displayId) . '"'
            . ' data-placeholder="' . esc($placeholder) . '"'
            . ' data-initial="' . ($value >= 0 ? (int) $value : '') . '"'
            . ($allowAll ? ' data-allow-all="1"' : '')
            . ' data-json-id="' . esc($jsonId) . '"';
    }

    $wrap = $wrapperClass !== 'customer-picker-slot' ? $wrapperClass : ($compact ? $metaClass : 'field');
    $html = '<div class="' . esc(trim($wrap)) . '"' . $dataAttrs;
    if ($wrapStyle !== '') {
        $html .= ' style="' . esc($wrapStyle) . '"';
    }
    $html .= '>';
    if ($label !== '') {
        $html .= '<label for="' . esc($labelFor) . '">' . esc($label) . '</label>';
    }
    $html .= '<input type="hidden" ' . $attrStr . '>';
    $btnTitle = 'اختيار العميل';
    $hotkey = trim((string) ($opts['hotkey'] ?? ''));
    if ($hotkey !== '') {
        $btnTitle .= ' (' . $hotkey . ')';
    }
    $html .= '<button type="button" class="' . esc($btnClass) . '" id="' . esc($openId) . '" title="' . esc($btnTitle) . '">';
    $html .= '<span id="' . esc($displayId) . '" class="sales-inv-cust-open-label is-placeholder">' . esc($placeholder) . '</span>';
    if ($hotkey !== '') {
        $html .= '<kbd class="sales-inv-field-hotkey" aria-hidden="true">' . esc($hotkey) . '</kbd>';
    }
    $html .= '<span class="sales-inv-cust-open-ico" aria-hidden="true">▾</span>';
    $html .= '</button></div>';

    return $html;
}

/** @param list<array<string, mixed>> $customers */
function customer_picker_json_script(array $customers, string $elementId = 'app-customers-json'): void
{
    echo '<script type="application/json" id="' . esc($elementId) . '">'
        . crm_customers_picker_json($customers)
        . '</script>' . "\n";
}
