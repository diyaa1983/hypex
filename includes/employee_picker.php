<?php
declare(strict_types=1);

function hr_employee_picker_row_label(array $pe): string
{
    $pname = (string) ($pe['name_ar'] ?? '');
    $label = $pname !== '' ? $pname : '—';
    if ((int) ($pe['is_resigned_posted'] ?? 0) === 1) {
        $label .= ' (مستقيل مرحّل)';
    } elseif (trim((string) ($pe['resignation_date'] ?? '')) !== '') {
        $label .= ' (مستقيل)';
    } elseif ((int) ($pe['is_active'] ?? 1) !== 1) {
        $label .= ' (غير نشِط)';
    }

    return $label;
}

/** @param list<array<string, mixed>> $employees */
function hr_employees_picker_json(array $employees): string
{
    $rows = array_map(static function (array $pe): array {
        $pname = (string) ($pe['name_ar'] ?? '');
        $code = trim((string) ($pe['emp_code'] ?? ''));

        return [
            'id' => (int) ($pe['id'] ?? 0),
            'code' => $code,
            'name_ar' => $pname !== '' ? $pname : '—',
            'label' => hr_employee_picker_row_label($pe),
            'search' => function_exists('mb_strtolower')
                ? mb_strtolower($pname . ' ' . $code, 'UTF-8')
                : strtolower($pname . ' ' . $code),
        ];
    }, $employees);

    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    return json_encode($rows, $jsonFlags) ?: '[]';
}

function employee_picker_assets_urls(): array
{
    require_once app_path('includes/customer_picker.php');
    $shared = customer_picker_assets_urls();
    $jsPath = app_path('assets/js/employee-picker-modal.js');

    return [
        'css' => $shared['css'],
        'ora_css' => $shared['ora_css'],
        'js' => app_url('assets/js/employee-picker-modal.js')
            . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : ''),
    ];
}

function employee_picker_enqueue_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $u = employee_picker_assets_urls();
    echo '<link rel="stylesheet" href="' . esc($u['css']) . '">' . "\n";
    echo '<script src="' . esc($u['js']) . '" defer></script>' . "\n";
    employee_picker_modal_once();
    picker_oracle_forms_enqueue_assets();
}

function employee_picker_modal_once(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<div id="app-employee-picker-modal" class="sales-inv-pick-dropdown hr-ora-pick-modal no-print" hidden role="dialog"
     aria-label="اختيار موظف" aria-modal="true">
    <div class="sales-inv-pick-head">
        <span class="sales-inv-pick-title">اختيار موظف</span>
        <button type="button" class="sales-inv-pick-close" id="app-employee-picker-close" aria-label="إغلاق">×</button>
    </div>
    <input type="search" class="input" id="app-employee-picker-search"
           placeholder="بحث: اسم الموظف أو الرقم" autocomplete="off">
    <div class="sales-inv-pick-list-head" aria-hidden="true">
        <span>اسم الموظف</span>
        <span>الرقم</span>
    </div>
    <div class="sales-inv-pick-results" id="app-employee-picker-results"></div>
    <div class="sales-inv-pick-foot sales-inv-cust-pick-foot">
        <span class="sales-inv-pick-hint">انقر على اسم الموظف للاختيار — أو ابحث ثم اختر</span>
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
 *   compact?: bool,
 *   wrapper_class?: string,
 *   allow_new?: bool,
 *   new_label?: string,
 *   json_id?: string,
 *   manual_bind?: bool,
 * } $opts
 */
function employee_picker_field(array $opts): string
{
    $hiddenId = (string) ($opts['id'] ?? 'employee_id');
    $openId = (string) ($opts['open_id'] ?? $hiddenId . '_open');
    $displayId = (string) ($opts['display_id'] ?? $hiddenId . '_display');
    $label = (string) ($opts['label'] ?? 'الموظف');
    $labelFor = (string) ($opts['label_for'] ?? $openId);
    $placeholder = (string) ($opts['placeholder'] ?? 'اضغط لاختيار الموظف');
    $compact = !empty($opts['compact']);
    $wrapperClass = trim('employee-picker-slot ' . (string) ($opts['wrapper_class'] ?? ''));
    $allowNew = !empty($opts['allow_new']);
    $newLabel = (string) ($opts['new_label'] ?? '— موظف جديد —');
    $jsonId = (string) ($opts['json_id'] ?? 'hr-employees-picker-json');
    $manualBind = !empty($opts['manual_bind']);
    $value = (int) ($opts['value'] ?? 0);

    $btnClass = 'sales-inv-cust-open input' . ($compact ? ' input-compact' : '');

    $dataAttrs = '';
    if (!$manualBind) {
        $dataAttrs =
            ' data-employee-picker'
            . ' data-hidden-id="' . esc($hiddenId) . '"'
            . ' data-open-id="' . esc($openId) . '"'
            . ' data-display-id="' . esc($displayId) . '"'
            . ' data-placeholder="' . esc($placeholder) . '"'
            . ' data-initial="' . ($value > 0 ? (string) $value : '') . '"'
            . ($allowNew ? ' data-allow-new="1"' : '')
            . ' data-new-label="' . esc($newLabel) . '"'
            . ' data-json-id="' . esc($jsonId) . '"';
    }

    $html = '<div class="' . esc(trim($wrapperClass)) . '"' . $dataAttrs . '>';
    if ($label !== '') {
        $html .= '<label for="' . esc($labelFor) . '">' . esc($label) . '</label>';
    }
    $html .= '<input type="hidden" id="' . esc($hiddenId) . '" value="' . esc($value > 0 ? (string) $value : '') . '">';
    $html .= '<button type="button" class="' . esc($btnClass) . '" id="' . esc($openId) . '" title="اختيار الموظف">';
    $html .= '<span id="' . esc($displayId) . '" class="sales-inv-cust-open-label is-placeholder">' . esc($placeholder) . '</span>';
    $html .= '<span class="sales-inv-cust-open-ico" aria-hidden="true">▾</span>';
    $html .= '</button></div>';

    return $html;
}

/** @param list<array<string, mixed>> $employees */
function employee_picker_json_script(array $employees, string $elementId = 'hr-employees-picker-json'): void
{
    echo '<script type="application/json" id="' . esc($elementId) . '">'
        . hr_employees_picker_json($employees)
        . '</script>' . "\n";
}
