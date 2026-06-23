<?php
declare(strict_types=1);

/** @param list<array<string, mixed>> $accounts */
function acc_accounts_picker_json(array $accounts): string
{
    $rows = array_map(static function (array $a): array {
        return [
            'id' => (int) ($a['id'] ?? 0),
            'code' => (string) ($a['code'] ?? ''),
            'name_ar' => (string) ($a['name_ar'] ?? ''),
        ];
    }, $accounts);

    return json_encode($rows, JSON_UNESCAPED_UNICODE) ?: '[]';
}

function account_picker_assets_urls(): array
{
    $cssPath = app_path('assets/css/customer-picker-modal.css');
    $jsPath = app_path('assets/js/account-picker-modal.js');

    return [
        'css' => app_url('assets/css/customer-picker-modal.css')
            . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : ''),
        'js' => app_url('assets/js/account-picker-modal.js')
            . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : ''),
    ];
}

function account_picker_enqueue_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once app_path('includes/customer_picker.php');
    $u = account_picker_assets_urls();
    echo '<link rel="stylesheet" href="' . esc($u['css']) . '">' . "\n";
    echo '<script src="' . esc($u['js']) . '" defer></script>' . "\n";
    account_picker_modal_once();
    picker_oracle_forms_enqueue_assets();
}

function account_picker_modal_once(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $searchApiUrl = app_url('api/accounts_search.php');
    ?>
<div id="app-account-picker-modal" class="sales-inv-pick-dropdown hr-ora-pick-modal no-print" hidden role="dialog"
     aria-label="اختيار حساب" aria-modal="true" data-search-api="<?= esc($searchApiUrl) ?>">
    <div class="sales-inv-pick-head">
        <span class="sales-inv-pick-title">اختيار حساب من الشجرة</span>
        <button type="button" class="sales-inv-pick-close" id="app-account-picker-close" aria-label="إغلاق">×</button>
    </div>
    <input type="search" class="input" id="app-account-picker-search"
           placeholder="بحث: رقم الحساب أو اسم الحساب" autocomplete="off">
    <div class="sales-inv-pick-results" id="app-account-picker-results"></div>
    <div class="sales-inv-pick-foot sales-inv-cust-pick-foot">
        <span class="sales-inv-pick-hint">اكتب رقم الحساب أو الاسم ثم اختر — أو «غير مربوط» لإلغاء الربط</span>
    </div>
</div>
    <?php
}

/**
 * @param array{
 *   id: string,
 *   name?: string|null,
 *   value?: int|string,
 *   open_id?: string,
 *   display_id?: string,
 *   placeholder?: string,
 *   allow_clear?: bool,
 *   json_id?: string,
 *   manual_bind?: bool,
 *   search_with_movements?: bool,
 *   search_for_mapping?: bool,
 *   max_results?: int,
 * } $opts
 */
function account_picker_field(array $opts): string
{
    $hiddenId = (string) ($opts['id'] ?? 'account_id');
    $openId = (string) ($opts['open_id'] ?? $hiddenId . '_open');
    $displayId = (string) ($opts['display_id'] ?? $hiddenId . '_display');
    $name = array_key_exists('name', $opts) ? $opts['name'] : null;
    $value = (int) ($opts['value'] ?? 0);
    $placeholder = (string) ($opts['placeholder'] ?? 'اضغط لاختيار حساب');
    $allowClear = !empty($opts['allow_clear']);
    $jsonId = (string) ($opts['json_id'] ?? 'app-accounts-json');
    $manualBind = !empty($opts['manual_bind']);
    $searchWithMovements = !empty($opts['search_with_movements']);
    $searchForMapping = !empty($opts['search_for_mapping']);
    $maxResults = max(0, (int) ($opts['max_results'] ?? 0));

    $attrStr = 'id="' . esc($hiddenId) . '" value="' . ($value > 0 ? (string) $value : '') . '"';
    if ($name !== null && $name !== '') {
        $attrStr = 'name="' . esc((string) $name) . '" ' . $attrStr;
    }

    $dataAttrs = '';
    if (!$manualBind) {
        $dataAttrs =
            ' data-account-picker'
            . ' data-hidden-id="' . esc($hiddenId) . '"'
            . ' data-open-id="' . esc($openId) . '"'
            . ' data-display-id="' . esc($displayId) . '"'
            . ' data-placeholder="' . esc($placeholder) . '"'
            . ' data-initial="' . ($value > 0 ? $value : '') . '"'
            . ($allowClear ? ' data-allow-clear="1"' : '')
            . ' data-json-id="' . esc($jsonId) . '"'
            . ($searchWithMovements ? ' data-search-with-movements="1"' : '')
            . ($searchForMapping ? ' data-search-for-mapping="1"' : '')
            . ($maxResults > 0 ? ' data-max-results="' . $maxResults . '"' : '');
    }

    $html = '<div class="account-picker-slot acc-map-picker-slot"' . $dataAttrs . '>';
    $html .= '<input type="hidden" ' . $attrStr . '>';
    $html .= '<button type="button" class="sales-inv-cust-open input acc-map-picker-btn" id="' . esc($openId) . '" title="اختيار حساب">';
    $html .= '<span id="' . esc($displayId) . '" class="sales-inv-cust-open-label is-placeholder">' . esc($placeholder) . '</span>';
    $html .= '<span class="sales-inv-cust-open-ico" aria-hidden="true">▾</span>';
    $html .= '</button></div>';

    return $html;
}

/** @param list<array<string, mixed>> $accounts */
function account_picker_json_script(array $accounts, string $elementId = 'app-accounts-json'): void
{
    echo '<script type="application/json" id="' . esc($elementId) . '">'
        . acc_accounts_picker_json($accounts)
        . '</script>' . "\n";
}
