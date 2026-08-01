<?php
declare(strict_types=1);

function item_picker_assets_urls(): array
{
    $cssPath = app_path('assets/css/item-picker-modal.css');
    $jsPath = app_path('assets/js/item-picker-modal.js');

    return [
        'css' => app_url('assets/css/item-picker-modal.css')
            . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : ''),
        'js' => app_url('assets/js/item-picker-modal.js')
            . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : ''),
    ];
}

function item_picker_enqueue_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once app_path('includes/customer_picker.php');
    customer_picker_enqueue_assets(true);
    $u = item_picker_assets_urls();
    echo '<link rel="stylesheet" href="' . esc($u['css']) . '">' . "\n";
    echo '<script src="' . esc($u['js']) . '" defer></script>' . "\n";
    $itemDisplayJs = app_path('assets/js/inv-item-display.js');
    if (is_file($itemDisplayJs)) {
        echo '<script src="' . esc(app_url('assets/js/inv-item-display.js'))
            . '?v=' . esc((string) filemtime($itemDisplayJs)) . '" defer></script>' . "\n";
    }
    item_picker_modal_once();
    picker_oracle_forms_enqueue_assets();
}

/** مستودع افتراضي لفواتير البيع — نفس قائمة المواد في التقارير. */
function item_picker_default_warehouse_id(?PDO $pdo = null): int
{
    try {
        require_once app_path('includes/inv_warehouse_items.php');
        $pdo = $pdo ?? db();

        return (int) (inv_default_warehouse_id($pdo) ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function item_picker_modal_once(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<div id="app-item-picker-modal" class="sales-inv-pick-dropdown item-picker-modal hr-ora-pick-modal no-print" hidden role="dialog"
     aria-label="اختيار مواد" aria-modal="true">
    <div class="sales-inv-pick-head">
        <span class="sales-inv-pick-title">اختيار مواد</span>
        <button type="button" class="sales-inv-pick-close" id="app-item-picker-close" aria-label="إغلاق">×</button>
    </div>
    <input type="search" class="input" id="app-item-picker-search"
           placeholder="بحث: اسم المادة، SKU، Barcode" autocomplete="off">
    <div class="sales-inv-pick-list-head item-picker-list-head" aria-hidden="true">
        <span>اسم المادة</span>
        <span>التعبئة</span>
        <span>SKU / Barcode</span>
    </div>
    <div class="sales-inv-pick-results" id="app-item-picker-results"></div>
    <div class="sales-inv-pick-foot item-picker-foot">
        <span class="sales-inv-pick-hint" id="app-item-picker-hint">حدّد مادة أو أكثر ثم اضغط إضافة</span>
        <button type="button" class="btn btn-primary btn-sm sales-inv-pick-add is-inactive" id="app-item-picker-add" disabled>
            إضافة المحدد
        </button>
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
 *   display_text?: string,
 *   wrapper_class?: string,
 *   allow_all?: bool,
 *   all_label?: string,
 *   api_items?: string,
 *   wrapper_style?: string,
 *   warehouse_id?: int|null,
 * } $opts
 */
function item_picker_single_field(array $opts): string
{
    $hiddenId = (string) ($opts['id'] ?? 'item_id');
    $openId = (string) ($opts['open_id'] ?? $hiddenId . '_open');
    $displayId = (string) ($opts['display_id'] ?? $hiddenId . '_display');
    $allBtnId = (string) ($opts['all_btn_id'] ?? $hiddenId . '_all');
    $name = array_key_exists('name', $opts) ? $opts['name'] : 'item_id';
    $rawVal = $opts['value'] ?? -1;
    if ($rawVal === '' || $rawVal === null) {
        $value = -1;
    } else {
        $value = (int) $rawVal;
    }
    $label = (string) ($opts['label'] ?? 'المادة');
    $labelFor = (string) ($opts['label_for'] ?? $openId);
    $placeholder = (string) ($opts['placeholder'] ?? 'اضغط لاختيار المادة');
    $displayText = trim((string) ($opts['display_text'] ?? ''));
    $wrapperClass = trim('item-picker-slot ' . (string) ($opts['wrapper_class'] ?? ''));
    $allowAll = !empty($opts['allow_all']);
    $allLabel = (string) ($opts['all_label'] ?? 'جميع المواد');
    $apiItems = (string) ($opts['api_items'] ?? app_url('api/items_search.php'));
    $wrapStyle = (string) ($opts['wrapper_style'] ?? '');
    $warehouseId = array_key_exists('warehouse_id', $opts)
        ? max(0, (int) $opts['warehouse_id'])
        : item_picker_default_warehouse_id();

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

    $isPlaceholder = $displayText === '';
    $labelText = $isPlaceholder ? $placeholder : $displayText;

    $wrap = $wrapperClass !== 'item-picker-slot' ? $wrapperClass : 'field';
    $html = '<div class="' . esc(trim($wrap)) . '" data-report-item-single-picker';
    $html .= ' data-hidden-id="' . esc($hiddenId) . '"';
    $html .= ' data-open-id="' . esc($openId) . '"';
    $html .= ' data-display-id="' . esc($displayId) . '"';
    $html .= ' data-all-btn-id="' . esc($allBtnId) . '"';
    $html .= ' data-placeholder="' . esc($placeholder) . '"';
    $html .= ' data-initial="' . ($value >= 0 ? (int) $value : '') . '"';
    $html .= ' data-display-text="' . esc($displayText) . '"';
    $html .= ' data-api-items="' . esc($apiItems) . '"';
    $html .= ' data-warehouse-id="' . (int) $warehouseId . '"';
    if ($allowAll) {
        $html .= ' data-allow-all="1" data-all-label="' . esc($allLabel) . '"';
    }
    if ($wrapStyle !== '') {
        $html .= ' style="' . esc($wrapStyle) . '"';
    }
    $html .= '>';
    if ($label !== '') {
        $html .= '<label for="' . esc($labelFor) . '">' . esc($label) . '</label>';
    }
    $html .= '<input type="hidden" ' . $attrStr . '>';
    $html .= '<div class="report-item-pick-row">';
    $itemHotkey = array_key_exists('hotkey', $opts) ? trim((string) $opts['hotkey']) : 'F3';
    $itemTitle = 'اختيار المادة' . ($itemHotkey !== '' ? ' (' . $itemHotkey . ')' : '');
    $html .= '<button type="button" class="sales-inv-cust-open input" id="' . esc($openId) . '" data-item-picker-open title="' . esc($itemTitle) . '">';
    $html .= '<span id="' . esc($displayId) . '" class="sales-inv-cust-open-label' . ($isPlaceholder ? ' is-placeholder' : '') . '">';
    $html .= esc($labelText);
    $html .= '</span>';
    if ($itemHotkey !== '') {
        $html .= '<kbd class="sales-inv-field-hotkey" aria-hidden="true">' . esc($itemHotkey) . '</kbd>';
    }
    $html .= '<span class="sales-inv-cust-open-ico" aria-hidden="true">▾</span></button>';
    if ($allowAll) {
        $html .= '<button type="button" class="btn btn-secondary btn-sm report-item-all-btn" id="' . esc($allBtnId) . '" data-action="select-all-items">';
        $html .= esc($allLabel);
        $html .= '</button>';
    }
    $html .= '</div></div>';

    return $html;
}
