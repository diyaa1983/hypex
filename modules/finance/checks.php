<?php
declare(strict_types=1);

require_once app_path('includes/fin_checks_manage.php');
require_once app_path('includes/fin_voucher.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
fin_voucher_ensure_schema_full($pdo);
fin_checks_manage_ensure_schema($pdo);

$activeRoute = $activeRoute ?? 'fin_checks';
$flash = flash_get();
$filters = fin_checks_manage_parse_filters($_GET);

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

$rows = [];
$err = '';
$sumAmount = 0.0;

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
}

$fromDisplay = $filters['from'] !== '' ? format_date_dmY($filters['from']) : '';
$toDisplay = $filters['to'] !== '' ? format_date_dmY($filters['to']) : '';
$today = date('Y-m-d');
$todayDisplay = format_date_dmY($today);

$finCssPath = app_path('assets/css/fin-checks.css');
$finCssUrl = app_url('assets/css/fin-checks.css') . (is_file($finCssPath) ? '?v=' . (string) filemtime($finCssPath) : '');
$jsPath = app_path('assets/js/fin-checks.js');
$jsUrl = app_url('assets/js/fin-checks.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');

$dateFieldLabels = [
    'voucher' => 'تاريخ السند',
    'due' => 'تاريخ الاستحقاق',
    'cleared' => 'تاريخ الصرف',
    'returned' => 'تاريخ الإرجاع',
];
$dateFieldLabel = $dateFieldLabels[$filters['date_field']] ?? 'تاريخ السند';

sales_ora12_enqueue_assets();
sales_inv_oracle12_enqueue_assets();
?>
<link rel="stylesheet" href="<?= esc($finCssUrl) ?>">

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page fin-checks-page"
     id="fin-checks-screen"
     data-api-url="<?= esc($apiUrl) ?>"
     data-csrf="<?= esc($csrf) ?>"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('شاشة الشيكات', '', $activeRoute); ?>
    <?php sales_ora12_workspace_open(); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error sales-ora-flash"><?= esc($err) ?></div>
    <?php endif; ?>

    <p class="sales-ora-info muted">
        جميع شيكات النظام (واردة وصادرة). الشيكات المُحصّلة أو المُصروفة سابقاً تظهر <strong>تم الترحيل — صرف</strong>.
        اترك حقول التاريخ فارغة لعرض كل الشيكات، أو حدّد فترة للتصفية.
    </p>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row fin-checks-filters">
            <input type="hidden" name="r" value="fin_checks">
            <label class="field">
                <span class="field-label">نوع الشيك</span>
                <select class="input input-compact" name="direction">
                    <option value="all" <?= $filters['direction'] === 'all' ? 'selected' : '' ?>>الكل</option>
                    <option value="incoming" <?= $filters['direction'] === 'incoming' ? 'selected' : '' ?>>وارد</option>
                    <option value="outgoing" <?= $filters['direction'] === 'outgoing' ? 'selected' : '' ?>>صادر</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">حالة الترحيل</span>
                <select class="input input-compact" name="status">
                    <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>الكل</option>
                    <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>لم يُرحَّل</option>
                    <option value="cleared" <?= $filters['status'] === 'cleared' ? 'selected' : '' ?>>تم الترحيل — صرف</option>
                    <option value="returned" <?= $filters['status'] === 'returned' ? 'selected' : '' ?>>تم الترحيل — إرجاع</option>
                </select>
            </label>
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
                <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--primary">عرض</button>
            </div>
        </form>
    </div>

    <div class="sales-ora-panel card fin-checks-results">
        <p class="sales-ora-info fin-checks-summary">
            <?php if ($filters['date_range_active']): ?>
                <?= esc($dateFieldLabel) ?>:
                <strong><?= esc($fromDisplay) ?></strong> — <strong><?= esc($toDisplay) ?></strong>
                —
            <?php else: ?>
                <strong>كل الشيكات</strong> —
            <?php endif; ?>
            العدد: <strong id="fin-checks-count"><?= count($rows) ?></strong>
            — الإجمالي: <strong id="fin-checks-total"><?= esc(format_money($sumAmount)) ?></strong>
        </p>
        <div class="table-wrap">
            <table class="data-table fin-checks-table" id="fin-checks-table">
                <thead>
                <tr>
                    <th>نوع</th>
                    <th>رقم الشيك</th>
                    <th>البنك</th>
                    <th>القيمة</th>
                    <th>العميل / المورد</th>
                    <th>سند</th>
                    <th>تاريخ السند</th>
                    <th>الاستحقاق</th>
                    <th>متأخر؟</th>
                    <th>حالة الترحيل</th>
                    <th>الإجراء</th>
                    <th>تاريخ الصرف / الإرجاع</th>
                    <th>تنفيذ</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="13" class="muted" style="text-align:center;">لا توجد شيكات مطابقة.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $checkLabel = trim(
                                ($r['direction'] ?? '') . ' — '
                                . (($r['check_no'] ?? '') !== '' ? $r['check_no'] : ('#' . ($r['check_id'] ?? 0)))
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
                        $actionTagClass = match ($lifecycle) {
                            'cleared' => 'fin-chk-action-tag fin-chk-action-tag--clear',
                            'returned' => 'fin-chk-action-tag fin-chk-action-tag--return',
                            default => 'fin-chk-action-tag fin-chk-action-tag--none',
                        };
                        ?>
                        <tr class="<?= !empty($r['is_overdue']) && $lifecycle === 'pending' ? 'fin-chk-row-overdue' : '' ?>"
                            data-check-id="<?= (int) ($r['check_id'] ?? 0) ?>"
                            data-check-no="<?= esc((string) ($r['check_no'] ?? '')) ?>"
                            data-check-amount="<?= esc((string) ((float) ($r['check_amount'] ?? 0))) ?>">
                            <td><?= esc((string) ($r['direction'] ?? '')) ?></td>
                            <td class="fin-chk-col-no"><code><?= esc((string) ($r['check_no'] ?: '—')) ?></code></td>
                            <td><?= esc((string) ($r['bank_name'] ?: '—')) ?></td>
                            <td class="fin-chk-col-money"><?= esc(format_money((float) ($r['check_amount'] ?? 0))) ?></td>
                            <td><?= esc((string) ($r['party_name'] ?? '—')) ?></td>
                            <td>
                                <?php if (($r['voucher_url'] ?? '') !== ''): ?>
                                    <a href="<?= esc((string) $r['voucher_url']) ?>"><code><?= esc((string) ($r['voucher_no'] ?? '')) ?></code></a>
                                <?php else: ?>
                                    <code><?= esc((string) ($r['voucher_no'] ?? '')) ?></code>
                                <?php endif; ?>
                            </td>
                            <td><?= esc(format_date_dmY((string) ($r['voucher_date'] ?? ''))) ?></td>
                            <td><?= ($r['due_date'] ?? '') !== '' ? esc(format_date_dmY((string) $r['due_date'])) : '—' ?></td>
                            <td>
                                <?php if (($r['due_date'] ?? '') !== '' && $lifecycle === 'pending'): ?>
                                    <span class="<?= esc($urgencyClass) ?>"><?= esc((string) ($r['urgency_label'] ?? '')) ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?= esc((string) ($r['status_badge_class'] ?? 'fin-chk-badge')) ?>">
                                    <?= esc((string) ($r['post_status_label'] ?? '')) ?>
                                </span>
                            </td>
                            <td>
                                <span class="<?= esc($actionTagClass) ?>">
                                    <?= esc((string) ($r['action_type_label'] ?? '—')) ?>
                                </span>
                            </td>
                            <td class="fin-chk-col-dates">
                                <?= ($r['action_date'] ?? '') !== '' ? esc(format_date_dmY((string) $r['action_date'])) : '—' ?>
                                <?php if (($r['return_reason'] ?? '') !== '' && $lifecycle === 'returned'): ?>
                                    <br><small class="muted"><?= esc((string) $r['return_reason']) ?></small>
                                <?php endif; ?>
                                <?php if (($r['action_account_name'] ?? '') !== '' && $lifecycle === 'cleared'): ?>
                                    <br><small class="muted"><?= esc((string) $r['action_account_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="row-actions fin-chk-actions-cell">
                                <?php if (!empty($r['can_action'])): ?>
                                    <button type="button" class="btn btn-primary btn-sm"
                                            data-check-action="clear"<?= $attrHtml ?>>صرف</button>
                                    <button type="button" class="btn btn-secondary btn-sm"
                                            data-check-action="return"<?= $attrHtml ?>>إرجاع</button>
                                <?php elseif ($lifecycle !== 'pending'): ?>
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

<div id="fin-check-modal" class="fin-check-modal sales-ora12-screen" hidden aria-hidden="true">
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
                            <td id="fin-check-sum-direction">—</td>
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
                            <th scope="row">العميل / المورد</th>
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
                <div class="fin-check-modal__actions">
                    <button type="button" class="dashboard-ora-btn" id="fin-check-modal-cancel">إلغاء</button>
                    <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--primary" id="fin-check-modal-submit">ترحيل الشيك</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
