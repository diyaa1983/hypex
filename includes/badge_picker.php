<?php
declare(strict_types=1);

require_once app_path('includes/customer_picker.php');

/** @param list<array<string,mixed>> $zkUsers */
function hr_attendance_badges_picker_json(array $zkUsers): string
{
    $rows = array_map(static function (array $zk): array {
        $id = (int) ($zk['zk_user_id'] ?? 0);
        $badge = trim((string) ($zk['badge_number'] ?? ''));
        $name = trim((string) ($zk['zk_name'] ?? ''));
        $label = $badge !== '' ? $badge : ('ZK #' . $id);
        $search = $badge . ' ' . $name . ' ' . $id . ' ' . $label;

        return [
            'id' => $id,
            'badge' => $badge,
            'name' => $name !== '' ? $name : '—',
            'label' => $label,
            'search' => function_exists('mb_strtolower')
                ? mb_strtolower($search, 'UTF-8')
                : strtolower($search),
        ];
    }, $zkUsers);

    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    return json_encode($rows, $jsonFlags) ?: '[]';
}

function badge_picker_assets_urls(): array
{
    $shared = customer_picker_assets_urls();
    $jsPath = app_path('assets/js/badge-picker-modal.js');

    return [
        'css' => $shared['css'],
        'ora_css' => $shared['ora_css'],
        'js' => app_url('assets/js/badge-picker-modal.js')
            . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : ''),
    ];
}

function badge_picker_enqueue_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $u = badge_picker_assets_urls();
    echo '<script src="' . esc($u['js']) . '" defer></script>' . "\n";
    badge_picker_modal_once();
}

function badge_picker_modal_once(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<div id="app-badge-picker-modal" class="sales-inv-pick-dropdown hr-ora-pick-modal no-print" hidden role="dialog"
     aria-label="اختيار رقم البصمة" aria-modal="true">
    <div class="sales-inv-pick-head">
        <span class="sales-inv-pick-title">اختيار رقم البصمة</span>
        <button type="button" class="sales-inv-pick-close" id="app-badge-picker-close" aria-label="إغلاق">×</button>
    </div>
    <input type="search" class="input" id="app-badge-picker-search"
           placeholder="بحث: رقم البصمة أو الاسم" autocomplete="off">
    <div class="sales-inv-pick-list-head" aria-hidden="true">
        <span>الاسم في البصمة</span>
        <span>الرقم</span>
    </div>
    <div class="sales-inv-pick-results" id="app-badge-picker-results"></div>
    <div class="sales-inv-pick-foot sales-inv-cust-pick-foot">
        <span class="sales-inv-pick-hint">ابحث ثم اختر — أو «بلا بصمة» لإلغاء الربط</span>
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
 *   label?: string,
 *   label_for?: string,
 *   placeholder?: string,
 *   compact?: bool,
 *   wrapper_class?: string,
 *   json_id?: string,
 *   manual_bind?: bool,
 *   form_id?: string,
 *   required?: bool,
 *   allow_none?: bool,
 *   none_label?: string,
 *   disabled?: bool,
 * } $opts
 */
function badge_picker_field(array $opts): string
{
    $hiddenId = (string) ($opts['id'] ?? 'att_zk_user_id');
    $hiddenName = trim((string) ($opts['name'] ?? ''));
    $required = !empty($opts['required']);
    $disabled = !empty($opts['disabled']);
    $openId = (string) ($opts['open_id'] ?? $hiddenId . '_open');
    $displayId = (string) ($opts['display_id'] ?? $hiddenId . '_display');
    $label = (string) ($opts['label'] ?? 'رقم البصمة');
    $labelFor = (string) ($opts['label_for'] ?? $openId);
    $placeholder = (string) ($opts['placeholder'] ?? '— بلا بصمة —');
    $compact = !empty($opts['compact']);
    $wrapperClass = trim('badge-picker-slot ' . (string) ($opts['wrapper_class'] ?? ''));
    $allowNone = !array_key_exists('allow_none', $opts) || !empty($opts['allow_none']);
    $noneLabel = (string) ($opts['none_label'] ?? '— بلا بصمة —');
    $jsonId = (string) ($opts['json_id'] ?? 'hr-badges-picker-json');
    $manualBind = !empty($opts['manual_bind']);
    $formId = trim((string) ($opts['form_id'] ?? ''));
    $value = (int) ($opts['value'] ?? 0);

    $hiddenVal = $value > 0 ? (string) $value : '';
    $btnClass = 'sales-inv-cust-open input' . ($compact ? ' input-compact' : '');

    $html = '<div class="' . esc(trim($wrapperClass)) . '">';
    if ($label !== '') {
        $html .= '<label for="' . esc($labelFor) . '">' . esc($label) . '</label>';
    }
    $html .= '<input type="hidden" id="' . esc($hiddenId) . '"'
        . ($hiddenName !== '' ? ' name="' . esc($hiddenName) . '"' : '')
        . ($formId !== '' ? ' form="' . esc($formId) . '"' : '')
        . ($required ? ' required' : '')
        . ' value="' . esc($hiddenVal) . '">';
    $html .= '<button type="button" class="' . esc($btnClass) . '" id="' . esc($openId) . '"'
        . ' title="اختيار رقم البصمة"'
        . ($disabled ? ' disabled' : '')
        . ' data-badge-json-id="' . esc($jsonId) . '"'
        . ($allowNone ? ' data-allow-none="1"' : '')
        . ' data-none-label="' . esc($noneLabel) . '"'
        . ' data-placeholder="' . esc($placeholder) . '"'
        . ' data-initial="' . esc($value > 0 ? (string) $value : ($allowNone ? '0' : '')) . '"'
        . '>';
    $html .= '<span id="' . esc($displayId) . '" class="sales-inv-cust-open-label is-placeholder">' . esc($placeholder) . '</span>';
    $html .= '<span class="sales-inv-cust-open-ico" aria-hidden="true">▾</span>';
    $html .= '</button></div>';

    return $html;
}

/** @param list<array<string,mixed>> $zkUsers */
function badge_picker_json_script(array $zkUsers, string $elementId = 'hr-badges-picker-json'): void
{
    echo '<script type="application/json" id="' . esc($elementId) . '">'
        . hr_attendance_badges_picker_json($zkUsers)
        . '</script>' . "\n";
}
