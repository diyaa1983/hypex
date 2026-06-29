<?php
declare(strict_types=1);

require_once app_path('includes/fin_checks_manage.php');
require_once app_path('includes/fin_voucher.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/supplier_picker.php');

$pdo = db();
fin_voucher_ensure_schema_full($pdo);
fin_checks_manage_ensure_schema($pdo);

$activeRoute = $activeRoute ?? 'fin_checks';
$flash = flash_get();
$filters = fin_checks_manage_parse_incoming_screen_filters($_GET);
$outgoingChecksUrl = app_url('index.php?r=fin_outgoing_checks');

$cashAccounts = fin_checks_manage_load_deposit_accounts($pdo);
$defaultDepositAccountId = (int) ($cashAccounts[0]['id'] ?? 0);
require_once app_path('includes/acc_gl.php');
$cashBoxId = acc_gl_cash_box_account_id($pdo);
if ($cashBoxId > 0) {
    $defaultDepositAccountId = $cashBoxId;
}
$csrf = csrf_token();
$apiUrl = app_url('api/fin_check_action.php');
$exitUrl = nav_exit_url('fin_checks');
$suppliers = crm_suppliers_for_picker($pdo);

$rows = [];
$err = '';
$sumAmount = 0.0;
$dueFromReceipts = ['count' => 0, 'amount' => 0.0];

if ($filters['date_range_active']) {
    $fromIso = parse_date_to_iso($filters['from']);
    $toIso = parse_date_to_iso($filters['to']);
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $filters['from'] = $fromIso;
        $filters['to'] = $toIso;
    }
}

if ($err === '') {
    $rows = fin_checks_manage_fetch($pdo, $filters);
    foreach ($rows as $r) {
        $sumAmount += (float) ($r['check_amount'] ?? 0);
    }
    $dueFromReceipts = fin_checks_manage_sum_due_from_receipts($pdo);
}

$fromDisplay = $filters['from'] !== '' ? format_date_dmY($filters['from']) : '';
$toDisplay = $filters['to'] !== '' ? format_date_dmY($filters['to']) : '';
$today = date('Y-m-d');
$todayDisplay = format_date_dmY($today);

$finCssPath = app_path('assets/css/fin-checks.css');
$finCssUrl = app_url('assets/css/fin-checks.css') . (is_file($finCssPath) ? '?v=' . (string) filemtime($finCssPath) : '');
$ora12CssPath = app_path('assets/css/fin-checks-oracle12.css');
$ora12CssUrl = app_url('assets/css/fin-checks-oracle12.css') . (is_file($ora12CssPath) ? '?v=' . (string) filemtime($ora12CssPath) : '');
$jsPath = app_path('assets/js/fin-checks.js');
$jsUrl = app_url('assets/js/fin-checks.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');

$listUrl = app_url('index.php?r=fin_checks');
$filterQueryBase = [
    'r' => 'fin_checks',
    'date_field' => $filters['date_field'],
    'sort_field' => $filters['sort_field'] ?? 'voucher',
    'sort_dir' => $filters['sort_dir'] ?? 'asc',
    'from' => $fromDisplay,
    'to' => $toDisplay,
    'check_no' => $filters['check_no'],
];
$finChecksStatusTabUrl = static function (string $status, bool $overdueOnly = false) use ($filterQueryBase, $listUrl): string {
    $q = $filterQueryBase;
    $q['status'] = $status;
    if ($overdueOnly) {
        $q['overdue_only'] = '1';
    }

    return $listUrl . '&' . http_build_query($q);
};

$dateFieldLabels = [
    'voucher' => 'تاريخ السند',
    'due' => 'تاريخ الاستحقاق',
    'cleared' => 'تاريخ الصرف',
    'returned' => 'تاريخ الإرجاع',
    'endorsed' => 'تاريخ التجيير',
];
$dateFieldLabel = $dateFieldLabels[$filters['date_field']] ?? 'تاريخ السند';
$sortFieldLabels = [
    'due' => 'تاريخ الاستحقاق',
    'voucher' => 'تاريخ السند',
    'action' => 'تاريخ الصرف / الإرجاع / التجيير',
];

sales_ora12_enqueue_assets();
sales_inv_oracle12_enqueue_assets();
?>
<link rel="stylesheet" href="<?= esc($finCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($ora12CssUrl) ?>">

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page fin-checks-page fin-checks-ora12"
     id="fin-checks-screen"
     data-api-url="<?= esc($apiUrl) ?>"
     data-csrf="<?= esc($csrf) ?>"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('الشيكات الواردة', '', $activeRoute); ?>
    <?php sales_ora12_workspace_open(); ?>

    <div class="sales-ora-toolbar fin-checks-toolbar">
        <a class="btn btn-secondary btn-sm" href="<?= esc($outgoingChecksUrl) ?>">سجل الشيكات الصادرة</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error sales-ora-flash"><?= esc($err) ?></div>
    <?php endif; ?>

    <p class="sales-ora-info muted fin-checks-intro">
        شيكات <strong>سندات القبض</strong> فقط — صرف، إرجاع، أو تجيير.
        بعد الترحيل يظهر <strong>تم الترحيل</strong>، وبعد الإلغاء من السند يظهر <strong>تم الإلغاء</strong>.
        للشيكات الصادرة استخدم <a href="<?= esc($outgoingChecksUrl) ?>">سجل الشيكات الصادرة</a>.
    </p>

    <div class="sales-ora-panel card fin-checks-filter-panel">
        <div class="fin-checks-filter-panel__head">
            <span>معايير العرض — شيكات واردة</span>
        </div>
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="fin-checks-filter-panel__body">
            <input type="hidden" name="r" value="fin_checks">
            <input type="hidden" name="status" value="<?= esc($filters['status']) ?>">
            <div class="fin-checks-filter-grid sales-ora-search-form">
            <label class="field">
                <span class="field-label">فلتر التاريخ حسب</span>
                <select class="input input-compact" name="date_field">
                    <?php foreach ($dateFieldLabels as $key => $lbl): ?>
                        <option value="<?= esc($key) ?>" <?= $filters['date_field'] === $key ? 'selected' : '' ?>>
                            <?= esc($lbl) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">ترتيب الجدول حسب</span>
                <select class="input input-compact" name="sort_field">
                    <?php foreach ($sortFieldLabels as $key => $lbl): ?>
                        <option value="<?= esc($key) ?>" <?= ($filters['sort_field'] ?? 'voucher') === $key ? 'selected' : '' ?>>
                            <?= esc($lbl) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">اتجاه الترتيب</span>
                <select class="input input-compact" name="sort_dir">
                    <option value="asc" <?= ($filters['sort_dir'] ?? 'asc') === 'asc' ? 'selected' : '' ?>>من الأقدم للأحدث</option>
                    <option value="desc" <?= ($filters['sort_dir'] ?? 'asc') === 'desc' ? 'selected' : '' ?>>من الأحدث للأقدم</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">من (اختياري)</span>
                <input class="input input-compact js-date-dmy" type="text" name="from" value="<?= esc($fromDisplay) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">إلى (اختياري)</span>
                <input class="input input-compact js-date-dmy" type="text" name="to" value="<?= esc($toDisplay) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">رقم الشيك</span>
                <input class="input input-compact" type="search" name="check_no" id="fin-checks-filter-no"
                       value="<?= esc($filters['check_no']) ?>" placeholder="بحث فوري" autocomplete="off">
            </label>
            <label class="field fin-checks-filter-check">
                <span class="field-label">&nbsp;</span>
                <label class="fin-checks-inline-check">
                    <input type="checkbox" name="overdue_only" value="1" <?= $filters['overdue_only'] ? 'checked' : '' ?>>
                    متأخر فقط
                </label>
            </label>
            <div class="field fin-checks-filter-actions">
                <span class="field-label">&nbsp;</span>
                <button type="submit" class="btn btn-primary btn-sm">عرض</button>
            </div>
            </div>
        </form>
    </div>

    <div class="fin-checks-status-tabs sales-ora-tabs">
        <a class="btn btn-sm <?= $filters['status'] === 'all' && !$filters['overdue_only'] ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc($finChecksStatusTabUrl('all')) ?>">الكل</a>
        <a class="btn btn-sm <?= $filters['status'] === 'pending' && !$filters['overdue_only'] ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc($finChecksStatusTabUrl('pending')) ?>">قيد — لم يُرحَّل</a>
        <a class="btn btn-sm <?= $filters['status'] === 'cleared' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc($finChecksStatusTabUrl('cleared')) ?>">تم الصرف</a>
        <a class="btn btn-sm <?= $filters['status'] === 'returned' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc($finChecksStatusTabUrl('returned')) ?>">مُرجَع</a>
        <a class="btn btn-sm <?= $filters['status'] === 'endorsed' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc($finChecksStatusTabUrl('endorsed')) ?>">مُجيَّر</a>
        <a class="btn btn-sm <?= $filters['overdue_only'] ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc($finChecksStatusTabUrl('all', true)) ?>">متأخر</a>
    </div>

    <div class="sales-ora-panel card fin-checks-results">
        <p class="fin-checks-summary-bar">
            <?php if ($filters['date_range_active']): ?>
                <span><?= esc($dateFieldLabel) ?>: <strong><?= esc($fromDisplay) ?></strong> — <strong><?= esc($toDisplay) ?></strong></span>
                <span class="fin-checks-summary-sep">|</span>
            <?php else: ?>
                <span><strong>كل الشيكات الواردة</strong></span>
                <span class="fin-checks-summary-sep">|</span>
            <?php endif; ?>
            <span>العدد: <strong id="fin-checks-count"><?= count($rows) ?></strong></span>
            <span class="fin-checks-summary-sep">|</span>
            <span>الإجمالي: <strong id="fin-checks-total"><?= esc(format_money($sumAmount)) ?></strong></span>
            <span class="fin-checks-summary-sep">|</span>
            <span class="fin-checks-due-summary">
                مجموع المستحقة من السندات:
                <strong id="fin-checks-due-total" class="fin-checks-due-total"><?= esc(format_money((float) $dueFromReceipts['amount'])) ?></strong>
                <span class="fin-checks-due-count">(<?= (int) $dueFromReceipts['count'] ?> شيك)</span>
            </span>
        </p>
        <div class="table-wrap">
            <table class="data-table fin-checks-table fin-checks-grid-table" id="fin-checks-table">
                <thead>
                <tr>
                    <th class="col-voucher">رقم السند</th>
                    <th class="col-vdate">تاريخ السند</th>
                    <th class="col-check-no">رقم الشيك</th>
                    <th class="col-due">تاريخ الاستحقاق</th>
                    <th class="col-money">قيمة الشيك</th>
                    <th class="col-party">العميل</th>
                    <th class="col-post">حالة الترحيل</th>
                    <th class="col-action">الإجراء</th>
                    <th class="col-action-date">تاريخ الإجراء</th>
                    <th class="col-urgency">متأخر</th>
                    <th class="col-exec">تنفيذ</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="11" class="muted fin-checks-empty-cell">لا توجد شيكات واردة مطابقة.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $checkLabel = trim(
                                (($r['check_no'] ?? '') !== '' ? $r['check_no'] : ('#' . ($r['check_id'] ?? 0)))
                                . ' — ' . format_money((float) ($r['check_amount'] ?? 0))
                            );
                            $rowAttrs = fin_checks_manage_row_data_attrs($r);
                            $attrHtml = '';
                            foreach ($rowAttrs as $k => $v) {
                                $attrHtml .= ' ' . $k . '="' . esc($v) . '"';
                            }
                            $attrHtml .= ' data-check-label="' . esc($checkLabel) . '"';
                        $urgencyClass = match ($r['urgency'] ?? '') {
                            'overdue' => 'badge badge-error',
                            'today' => 'badge badge-warning',
                            'soon' => 'badge badge-warn',
                            default => 'badge badge-muted',
                        };
                        $lifecycle = (string) ($r['lifecycle_status'] ?? 'pending');
                        $actionWasUndone = !empty($r['action_was_undone']);
                        $postBadgeClass = $actionWasUndone
                            ? 'fin-chk-badge fin-chk-badge--undo'
                            : (string) ($r['status_badge_class'] ?? 'fin-chk-badge');
                        $postStatusLabel = $actionWasUndone
                            ? (string) ($r['post_status_label'] ?: 'تم الإلغاء')
                            : (string) ($r['post_status_label'] ?? '');
                        $actionTypeLabel = $actionWasUndone
                            ? (string) ($r['action_type_label'] ?: '—')
                            : (string) ($r['action_type_label'] ?? '—');
                        $executeStatusLabel = $actionWasUndone
                            ? (string) ($r['status_display'] ?: 'تم الإلغاء')
                            : (string) ($r['status_display'] ?? '');
                        $actionTagClass = $actionWasUndone
                            ? 'fin-chk-action-tag fin-chk-action-tag--undo'
                            : match ($lifecycle) {
                            'cleared' => 'fin-chk-action-tag fin-chk-action-tag--clear',
                            'returned' => 'fin-chk-action-tag fin-chk-action-tag--return',
                            'endorsed' => 'fin-chk-action-tag fin-chk-action-tag--endorse',
                            default => 'fin-chk-action-tag fin-chk-action-tag--none',
                        };
                        $dateNotes = [];
                        if ($actionWasUndone) {
                            $actionDateText = ($r['action_date_dmy'] ?? '') !== ''
                                ? (string) $r['action_date_dmy']
                                : '—';
                            if (($r['undone_action_label'] ?? '') !== '') {
                                $dateNotes[] = 'إلغاء ' . (string) $r['undone_action_label'];
                            }
                        } else {
                            $actionDateText = ($r['action_date'] ?? '') !== ''
                                ? format_date_dmY((string) $r['action_date'])
                                : '—';
                            if (($r['return_reason'] ?? '') !== '' && $lifecycle === 'returned') {
                                $dateNotes[] = (string) $r['return_reason'];
                            }
                            if (($r['action_account_name'] ?? '') !== '' && $lifecycle === 'cleared') {
                                $dateNotes[] = (string) $r['action_account_name'];
                            }
                            if (($r['endorsed_party_name'] ?? '') !== '' && $lifecycle === 'endorsed') {
                                $dateNotes[] = 'إلى: ' . (string) $r['endorsed_party_name'];
                            }
                            if (($r['endorse_notes'] ?? '') !== '' && $lifecycle === 'endorsed') {
                                $dateNotes[] = (string) $r['endorse_notes'];
                            }
                        }
                        $dateNotesText = implode(' · ', $dateNotes);
                        $dateLineTitle = $actionDateText;
                        if ($dateNotesText !== '') {
                            $dateLineTitle .= ' · ' . $dateNotesText;
                        }
                        ?>
                        <tr class="<?= !empty($r['is_overdue']) && $lifecycle === 'pending' && !$actionWasUndone ? 'fin-chk-row-overdue' : '' ?><?= $actionWasUndone ? ' fin-chk-row-undone' : '' ?>"
                            data-check-id="<?= (int) ($r['check_id'] ?? 0) ?>"
                            data-check-no="<?= esc((string) ($r['check_no'] ?? '')) ?>"
                            data-check-amount="<?= esc((string) ((float) ($r['check_amount'] ?? 0))) ?>">
                            <td class="col-voucher">
                                <?php if (($r['voucher_url'] ?? '') !== ''): ?>
                                    <a href="<?= esc((string) $r['voucher_url']) ?>"><code><?= esc((string) ($r['voucher_no'] ?? '')) ?></code></a>
                                <?php else: ?>
                                    <code><?= esc((string) ($r['voucher_no'] ?? '')) ?></code>
                                <?php endif; ?>
                            </td>
                            <td class="fin-chk-col-date col-vdate"><?= esc(format_date_dmY((string) ($r['voucher_date'] ?? ''))) ?></td>
                            <td class="fin-chk-col-no col-check-no"><code><?= esc((string) ($r['check_no'] ?: '—')) ?></code></td>
                            <td class="fin-chk-col-date col-due"><?= ($r['due_date'] ?? '') !== '' ? esc(format_date_dmY((string) $r['due_date'])) : '—' ?></td>
                            <td class="fin-chk-col-money col-money"><?= esc(format_money((float) ($r['check_amount'] ?? 0))) ?></td>
                            <td class="fin-chk-col-party fin-chk-col-ellipsis col-party" title="<?= esc((string) ($r['party_name'] ?? '')) ?>"><?= esc((string) ($r['party_name'] ?? '—')) ?></td>
                            <td class="col-post">
                                <span class="<?= esc($postBadgeClass) ?>">
                                    <?= esc($postStatusLabel) ?>
                                </span>
                            </td>
                            <td class="col-action">
                                <span class="<?= esc($actionTagClass) ?>">
                                    <?= esc($actionTypeLabel) ?>
                                </span>
                            </td>
                            <td class="fin-chk-col-dates col-action-date" dir="ltr"<?= $dateLineTitle !== '—' ? ' title="' . esc($dateLineTitle) . '"' : '' ?>>
                                <span class="fin-chk-date-main"><?= esc($actionDateText) ?></span>
                                <?php if ($dateNotes !== []): ?>
                                    <span class="fin-chk-date-note"><?= esc(implode(' · ', $dateNotes)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-urgency">
                                <?php if (($r['due_date'] ?? '') !== '' && $lifecycle === 'pending' && !$actionWasUndone): ?>
                                    <span class="<?= esc($urgencyClass) ?>"><?= esc((string) ($r['urgency_label'] ?? '')) ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="row-actions fin-chk-actions-cell col-exec">
                                <?php if ($actionWasUndone): ?>
                                    <span class="fin-chk-badge fin-chk-badge--undo fin-chk-execute-undo">
                                        <?= esc($executeStatusLabel) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($r['can_action'])): ?>
                                    <button type="button" class="btn btn-sm fin-chk-act-btn fin-chk-act-btn--clear"
                                            data-check-action="clear"<?= $attrHtml ?>>صرف</button>
                                    <button type="button" class="btn btn-sm fin-chk-act-btn fin-chk-act-btn--return"
                                            data-check-action="return"<?= $attrHtml ?>>إرجاع</button>
                                    <button type="button" class="btn btn-sm fin-chk-act-btn fin-chk-act-btn--endorse"
                                            data-check-action="endorse"<?= $attrHtml ?>>تجيير</button>
                                <?php elseif (!$actionWasUndone && $lifecycle !== 'pending'): ?>
                                    <span class="badge badge-ok"><?= esc((string) ($r['status_display'] ?? '')) ?></span>
                                <?php elseif (!($r['is_posted'] ?? false)): ?>
                                    <span class="badge badge-warn">غير مرحّل</span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php sales_ora12_workspace_close(); ?>
</div>

<div id="fin-check-modal" class="fin-check-modal fin-check-modal--ora12 sales-ora12-screen" hidden aria-hidden="true">
    <div class="fin-check-modal__backdrop" data-fin-check-close></div>
    <div class="dashboard-ora-panel fin-check-modal__panel fin-check-modal__panel--ora" role="dialog" aria-modal="true">
        <h2 class="dashboard-ora-panel__title" id="fin-check-modal-title">—</h2>
        <div class="dashboard-ora-panel__body">
            <section class="dashboard-ora-panel fin-check-modal__summary-panel">
                <h3 class="dashboard-ora-panel__title fin-check-modal__summary-title">بيانات الشيك</h3>
                <div class="dashboard-ora-panel__body fin-check-modal__summary-body">
                    <table class="data-table fin-check-modal__summary-table">
                        <tbody>
                        <tr>
                            <th scope="row">نوع الشيك</th>
                            <td id="fin-check-sum-direction">وارد</td>
                            <th scope="row">رقم الشيك</th>
                            <td id="fin-check-sum-no"><code>—</code></td>
                        </tr>
                        <tr>
                            <th scope="row">القيمة</th>
                            <td id="fin-check-sum-amount" class="fin-chk-col-money">—</td>
                            <th scope="row">البنك</th>
                            <td id="fin-check-sum-bank">—</td>
                        </tr>
                        <tr>
                            <th scope="row">العميل</th>
                            <td id="fin-check-sum-party">—</td>
                            <th scope="row">سند</th>
                            <td id="fin-check-sum-voucher"><code>—</code></td>
                        </tr>
                        <tr>
                            <th scope="row">تاريخ السند</th>
                            <td id="fin-check-sum-vdate">—</td>
                            <th scope="row">الاستحقاق</th>
                            <td id="fin-check-sum-due">—</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div id="fin-check-modal-error" class="alert alert-error" style="display:none;margin:0.75rem 0;"></div>
            <form id="fin-check-modal-form" class="form-grid fin-check-modal__form">
                <input type="hidden" name="action" id="fin-check-modal-action" value="">
                <input type="hidden" name="check_id" id="fin-check-modal-check-id" value="">
                <label class="field">
                    <span class="field-label">تاريخ الترحيل *</span>
                    <input class="input input-compact js-date-dmy" type="text" name="action_date" id="fin-check-action-date"
                           value="<?= esc($todayDisplay) ?>" placeholder="يوم-شهر-سنة" dir="ltr" required autocomplete="off">
                </label>
                <div id="fin-check-account-wrap">
                    <label class="field">
                        <span class="field-label">إيداع إلى حساب (صندوق / بنك) *</span>
                        <select class="input input-compact" name="account_id" id="fin-check-account-id" required>
                            <option value="">— اختر حساب الاستلام —</option>
                            <?php
                            $lastGroup = '';
                            foreach ($cashAccounts as $acc):
                                $gk = (string) ($acc['group_key'] ?? 'liquid');
                                $gl = (string) ($acc['group_label'] ?? 'النقدية والبنوك');
                                if ($gk !== $lastGroup):
                                    if ($lastGroup !== '') {
                                        echo '</optgroup>';
                                    }
                                    $lastGroup = $gk;
                                    echo '<optgroup label="' . esc($gl) . '">';
                                endif;
                                $sel = (int) ($acc['id'] ?? 0) === $defaultDepositAccountId ? ' selected' : '';
                                ?>
                                <option value="<?= (int) ($acc['id'] ?? 0) ?>"<?= $sel ?>>
                                    <?= esc((string) ($acc['code'] ?? '')) ?> — <?= esc((string) ($acc['name_ar'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($lastGroup !== ''): ?></optgroup><?php endif; ?>
                        </select>
                    </label>
                    <p class="muted fin-check-modal__account-hint">جميع حسابات الصناديق والبنوك النشطة تحت مجموعتي «الصندوق/الصناديق» و«البنوك» في شجرة الحسابات.</p>
                </div>
                <div id="fin-check-reason-wrap" style="display:none;">
                    <label class="field">
                        <span class="field-label">سبب الإرجاع *</span>
                        <textarea class="input" name="return_reason" id="fin-check-return-reason" rows="3" placeholder="سبب الإرجاع"></textarea>
                    </label>
                </div>
                <div id="fin-check-endorse-wrap" style="display:none;">
                    <input type="hidden" name="party_type" id="fin-check-party-type" value="supplier">
                    <div class="fin-check-modal__party-pickers">
                        <?= supplier_picker_field([
                            'id' => 'fin-check-endorse-supplier-id',
                            'label' => 'المورد المُجيَّر إليه *',
                            'placeholder' => 'اختر المورد',
                            'compact' => true,
                            'manual_bind' => true,
                            'json_id' => 'fin-checks-suppliers-json',
                            'name' => null,
                        ]) ?>
                    </div>
                    <label class="field">
                        <span class="field-label">ملاحظات التجيير</span>
                        <textarea class="input" name="endorse_notes" id="fin-check-endorse-notes" rows="2" placeholder="اختياري"></textarea>
                    </label>
                </div>
                <div class="fin-check-modal__actions">
                    <button type="button" class="dashboard-ora-btn" id="fin-check-modal-cancel">إلغاء</button>
                    <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--primary" id="fin-check-modal-submit">ترحيل الشيك</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
<?php
supplier_picker_enqueue_assets();
supplier_picker_json_script($suppliers, 'fin-checks-suppliers-json');
?>
