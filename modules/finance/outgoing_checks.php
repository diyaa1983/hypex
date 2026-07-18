<?php
declare(strict_types=1);

require_once app_path('includes/fin_outgoing_check_register.php');
require_once app_path('includes/fin_checks_manage.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/document_header.php');

$pdo = db();
fin_outgoing_check_register_ensure_schema($pdo);
fin_checks_manage_ensure_schema($pdo);

$activeRoute = $activeRoute ?? 'fin_outgoing_checks';
$flash = flash_get();
$filters = fin_outgoing_check_register_parse_filters($_GET);

$cashAccounts = fin_checks_manage_load_deposit_accounts($pdo);
$defaultDepositAccountId = (int) ($cashAccounts[0]['id'] ?? 0);
require_once app_path('includes/acc_gl.php');
$bankId = acc_gl_bank_account_id($pdo);
if ($bankId > 0) {
    $defaultDepositAccountId = $bankId;
}
$csrf = csrf_token();
$apiUrl = app_url('api/fin_check_action.php');
$todayDisplay = format_date_dmY(date('Y-m-d'));

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
    $rows = fin_outgoing_check_register_fetch($pdo, $filters);
    foreach ($rows as $r) {
        $sumAmount += (float) ($r['check_amount'] ?? 0);
    }
}

$fromDisplay = ($filters['from'] ?? '') !== '' ? format_date_dmY((string) $filters['from']) : '';
$toDisplay = ($filters['to'] ?? '') !== '' ? format_date_dmY((string) $filters['to']) : '';

$finCssPath = app_path('assets/css/fin-checks.css');
$finCssUrl = app_url('assets/css/fin-checks.css') . (is_file($finCssPath) ? '?v=' . (string) filemtime($finCssPath) : '');
$docCssPath = app_path('assets/css/document-header.css');
$docCssUrl = app_url('assets/css/document-header.css') . (is_file($docCssPath) ? '?v=' . (string) filemtime($docCssPath) : '');
$jsPath = app_path('assets/js/fin-outgoing-checks.js');
$jsUrl = app_url('assets/js/fin-outgoing-checks.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$printCheckApi = app_url('api/fin_outgoing_check_print.php');
$printCheckCssPath = app_path('assets/css/fin-outgoing-check-print.css');
$printCheckCssUrl = app_url('assets/css/fin-outgoing-check-print.css')
    . (is_file($printCheckCssPath) ? '?v=' . (string) filemtime($printCheckCssPath) : '');
$printFilterLines = fin_outgoing_check_register_filter_caption_lines($filters, $fromDisplay, $toDisplay);
$exitUrl = nav_exit_url('fin_outgoing_checks');

sales_ora12_enqueue_assets();
sales_inv_oracle12_enqueue_assets();
?>
<link rel="stylesheet" href="<?= esc($finCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($printCheckCssUrl) ?>">

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page fin-checks-page fin-outgoing-checks-page"
     id="fin-outgoing-checks-screen"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-api-url="<?= esc($apiUrl) ?>"
     data-csrf="<?= esc($csrf) ?>"
     data-print-check-api="<?= esc($printCheckApi) ?>"
     data-print-check-css="<?= esc($printCheckCssUrl) ?>">
    <?php sales_ora12_render_title_bar('سجل الشيكات الصادرة', '', $activeRoute); ?>
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
        يُسجَّل كل شيك صادر تلقائياً عند حفظ <strong>سند صرف</strong> بطريقة دفع «شيك».
        عند <strong>ترحيل</strong> السند يتأثر حساب الطرف (مورد/عميل/موظف/حساب) ويُضاف للشيكات الآجلة — دون خصم البنك.
        عند الضغط على <strong>صرف</strong> هنا يُنقل القيد من الشيكات الآجلة ويُخصم من البنك المختار.
    </p>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row fin-checks-filters">
            <input type="hidden" name="r" value="fin_outgoing_checks">
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
                <span class="field-label">رقم تسلسلي</span>
                <input class="input input-compact" type="search" name="register_no" value="<?= esc($filters['register_no']) ?>"
                       placeholder="001-2026" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">رقم الشيك</span>
                <input class="input input-compact" type="search" name="check_no" value="<?= esc($filters['check_no']) ?>"
                       placeholder="بحث" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">الجهة المصروف لها</span>
                <input class="input input-compact" type="search" name="party_q" value="<?= esc($filters['party_q']) ?>"
                       placeholder="اسم العميل / المورد / الموظف" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">ترحيل السند</span>
                <select class="input input-compact" name="posted">
                    <option value="all"<?= $filters['posted'] === 'all' ? ' selected' : '' ?>>الكل</option>
                    <option value="posted"<?= $filters['posted'] === 'posted' ? ' selected' : '' ?>>مرحّل</option>
                    <option value="unposted"<?= $filters['posted'] === 'unposted' ? ' selected' : '' ?>>غير مرحّل</option>
                </select>
            </label>
            <div class="field fin-checks-filter-actions">
                <span class="field-label">&nbsp;</span>
                <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--primary">عرض</button>
            </div>
        </form>
    </div>

    <div class="sales-ora-panel card fin-checks-results">
        <div class="fin-outgoing-checks-toolbar no-print">
            <button type="button" class="dashboard-ora-btn" id="fin-outgoing-checks-print-list"
                    title="طباعة الشيكات المعروضة حسب الفلاتر الحالية"<?= $rows === [] ? ' disabled' : '' ?>>
                طباعة القائمة
            </button>
        </div>
        <div class="fin-outgoing-checks-print-area" id="fin-outgoing-checks-print-area">
            <div class="fin-outgoing-checks-print-only">
                <?= document_print_header_html('سجل الشيكات الصادرة', $pdo) ?>
                <div class="doc-print-meta">
                    <table>
                        <?php foreach ($printFilterLines as $line): ?>
                            <tr><td><?= esc($line) ?></td></tr>
                        <?php endforeach; ?>
                        <tr>
                            <td>
                                <strong>عدد الشيكات:</strong> <?= count($rows) ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                <strong>الإجمالي:</strong> <?= esc(format_money($sumAmount)) ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        <p class="sales-ora-info fin-checks-summary no-print">
            <?php if ($filters['date_range_active']): ?>
                الفترة: <strong><?= esc($fromDisplay) ?></strong> — <strong><?= esc($toDisplay) ?></strong> —
            <?php else: ?>
                <strong>جميع الشيكات الصادرة</strong> —
            <?php endif; ?>
            العدد: <strong><?= count($rows) ?></strong>
            — الإجمالي: <strong><?= esc(format_money($sumAmount)) ?></strong>
        </p>
        <div class="table-wrap">
            <table class="data-table fin-checks-table fin-outgoing-checks-table" id="fin-outgoing-checks-table">
                <thead>
                <tr>
                    <th>رقم تسلسلي</th>
                    <th>رقم الشيك</th>
                    <th>المبلغ</th>
                    <th>الجهة المصروف لها</th>
                    <th>نوع الجهة</th>
                    <th>البنك</th>
                    <th>سند الصرف</th>
                    <th>تاريخ السند</th>
                    <th>الاستحقاق</th>
                    <th>ترحيل السند</th>
                    <th>حالة الشيك</th>
                    <th class="no-print">تنفيذ</th>
                    <th class="fin-outgoing-checks-col-print no-print">طباعة</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="13" class="muted" style="text-align:center;padding:1.25rem;">
                            لا توجد شيكات صادرة مطابقة.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $checkLabel = trim(
                                (($r['check_no'] ?? '') !== '' ? (string) $r['check_no'] : ('#' . ($r['check_id'] ?? 0)))
                                . ' — ' . format_money((float) ($r['check_amount'] ?? 0))
                            );
                            $rowAttrs = fin_checks_manage_row_data_attrs($r);
                            $attrHtml = '';
                            foreach ($rowAttrs as $k => $v) {
                                $attrHtml .= ' ' . $k . '="' . esc($v) . '"';
                            }
                            $attrHtml .= ' data-check-label="' . esc($checkLabel) . '"';
                            $lifecycle = (string) ($r['lifecycle_status'] ?? 'pending');
                            $statusExtra = '';
                            if ($lifecycle === 'cleared' && ($r['action_account_name'] ?? '') !== '') {
                                $statusExtra = (string) $r['action_account_name'];
                            }
                            if ($lifecycle === 'cleared' && ($r['action_date'] ?? '') !== '') {
                                $statusExtra = trim($statusExtra . ' · ' . format_date_dmY((string) $r['action_date']));
                            }
                        ?>
                        <tr data-check-id="<?= (int) ($r['check_id'] ?? 0) ?>">
                            <td><code><?= esc((string) ($r['register_no'] ?? '')) ?></code></td>
                            <td class="fin-chk-col-no"><code><?= esc((string) (($r['check_no'] ?? '') !== '' ? $r['check_no'] : '—')) ?></code></td>
                            <td class="fin-chk-col-money"><?= esc(format_money((float) ($r['check_amount'] ?? 0))) ?></td>
                            <td class="fin-chk-col-party fin-chk-col-ellipsis" title="<?= esc((string) ($r['party_name'] ?? '')) ?>">
                                <?= esc((string) ($r['party_name'] ?? '—')) ?>
                            </td>
                            <td><?= esc((string) ($r['party_type_label'] ?? '—')) ?></td>
                            <td class="fin-chk-col-ellipsis" title="<?= esc((string) ($r['bank_name'] ?? '')) ?>">
                                <?= esc((string) (($r['bank_name'] ?? '') !== '' ? $r['bank_name'] : '—')) ?>
                            </td>
                            <td>
                                <?php if (($r['voucher_url'] ?? '') !== ''): ?>
                                    <a class="no-print" href="<?= esc((string) $r['voucher_url']) ?>"><code><?= esc((string) ($r['voucher_no'] ?? '')) ?></code></a>
                                    <code class="fin-outgoing-checks-print-only"><?= esc((string) ($r['voucher_no'] ?? '')) ?></code>
                                <?php else: ?>
                                    <code><?= esc((string) ($r['voucher_no'] ?? '')) ?></code>
                                <?php endif; ?>
                            </td>
                            <td class="fin-chk-col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['voucher_date'] ?? ''))) ?></td>
                            <td class="fin-chk-col-date" dir="ltr">
                                <?= ($r['due_date'] ?? '') !== '' ? esc(format_date_dmY((string) $r['due_date'])) : '—' ?>
                            </td>
                            <td>
                                <span class="fin-chk-badge<?= !empty($r['is_posted']) ? ' fin-chk-badge--posted' : '' ?>">
                                    <?= esc((string) ($r['posted_label'] ?? '')) ?>
                                </span>
                            </td>
                            <td title="<?= esc($statusExtra) ?>">
                                <?= esc((string) ($r['lifecycle_label'] ?? 'قيد')) ?>
                                <?php if ($statusExtra !== ''): ?>
                                    <br><small class="muted"><?= esc($statusExtra) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="row-actions fin-chk-actions-cell no-print">
                                <?php if (!empty($r['can_clear'])): ?>
                                    <button type="button" class="btn btn-sm fin-chk-act-btn fin-chk-act-btn--clear"
                                            data-check-action="clear"<?= $attrHtml ?>>صرف</button>
                                <?php elseif (!empty($r['can_undo'])): ?>
                                    <button type="button" class="btn btn-sm btn-secondary fin-chk-act-btn"
                                            data-check-action="undo"
                                            data-check-id="<?= (int) ($r['check_id'] ?? 0) ?>"
                                            data-undo-label="<?= esc((string) ($r['undo_label'] ?? 'إلغاء الصرف')) ?>">
                                        <?= esc((string) ($r['undo_label'] ?? 'إلغاء الصرف')) ?>
                                    </button>
                                <?php elseif (empty($r['is_posted'])): ?>
                                    <span class="badge badge-warn">غير مرحّل</span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="fin-outgoing-checks-col-print no-print">
                                <button type="button"
                                        class="btn btn-secondary btn-sm fin-outgoing-check-print-one"
                                        data-check-id="<?= (int) ($r['check_id'] ?? 0) ?>"
                                        title="طباعة هذا الشيك">طباعة</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div>
    </div>

    <?php sales_ora12_workspace_close(); ?>
</div>

<div id="fin-oc-clear-modal" class="fin-check-modal fin-check-modal--ora12 sales-ora12-screen" hidden aria-hidden="true">
    <div class="fin-check-modal__backdrop" data-fin-oc-clear-close></div>
    <div class="dashboard-ora-panel fin-check-modal__panel fin-check-modal__panel--ora" role="dialog" aria-modal="true">
        <h2 class="dashboard-ora-panel__title" id="fin-oc-clear-modal-title">ترحيل صرف الشيك الصادر</h2>
        <div class="dashboard-ora-panel__body">
            <section class="dashboard-ora-panel fin-check-modal__summary-panel">
                <h3 class="dashboard-ora-panel__title fin-check-modal__summary-title">بيانات الشيك</h3>
                <div class="dashboard-ora-panel__body fin-check-modal__summary-body">
                    <table class="data-table fin-check-modal__summary-table">
                        <tbody>
                        <tr>
                            <th scope="row">رقم الشيك</th>
                            <td id="fin-oc-sum-no"><code>—</code></td>
                            <th scope="row">القيمة</th>
                            <td id="fin-oc-sum-amount" class="fin-chk-col-money">—</td>
                        </tr>
                        <tr>
                            <th scope="row">الجهة</th>
                            <td id="fin-oc-sum-party">—</td>
                            <th scope="row">سند الصرف</th>
                            <td id="fin-oc-sum-voucher"><code>—</code></td>
                        </tr>
                        <tr>
                            <th scope="row">تاريخ السند</th>
                            <td id="fin-oc-sum-vdate">—</td>
                            <th scope="row">الاستحقاق</th>
                            <td id="fin-oc-sum-due">—</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div id="fin-oc-clear-modal-error" class="alert alert-error" style="display:none;margin:0.75rem 0;"></div>
            <form id="fin-oc-clear-modal-form" class="form-grid fin-check-modal__form">
                <input type="hidden" name="action" value="clear">
                <input type="hidden" name="check_id" id="fin-oc-clear-check-id" value="">
                <label class="field">
                    <span class="field-label">تاريخ الصرف *</span>
                    <input class="input input-compact js-date-dmy" type="text" name="action_date" id="fin-oc-clear-action-date"
                           value="<?= esc($todayDisplay) ?>" placeholder="يوم-شهر-سنة" dir="ltr" required autocomplete="off">
                </label>
                <label class="field">
                    <span class="field-label">خصم من حساب البنك / الصندوق *</span>
                    <select class="input input-compact" name="account_id" id="fin-oc-clear-account-id"
                            data-default-account-id="<?= (int) $defaultDepositAccountId ?>" required>
                        <option value="">— اختر الحساب —</option>
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
                        <?php endforeach;
                        if ($lastGroup !== '') {
                            echo '</optgroup>';
                        }
                        ?>
                    </select>
                </label>
                <div class="form-actions" style="margin-top:0.75rem;">
                    <button type="submit" class="btn btn-primary" id="fin-oc-clear-modal-submit">ترحيل — صرف</button>
                    <button type="button" class="btn btn-secondary" id="fin-oc-clear-modal-cancel" data-fin-oc-clear-close>إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="fin-oc-print-overlay" class="sales-inv-print-overlay fin-oc-print-overlay no-print" hidden>
    <div class="sales-inv-print-overlay-panel fin-oc-print-overlay-panel">
        <div class="sales-inv-print-overlay-head">
            <h3 class="sales-inv-print-overlay-title" id="fin-oc-print-overlay-title">معاينة الشيك</h3>
            <div class="sales-inv-print-overlay-actions">
                <button type="button" class="btn btn-primary btn-sm" id="fin-oc-print-run">طباعة</button>
                <button type="button" class="btn btn-secondary btn-sm" id="fin-oc-print-close">إغلاق</button>
            </div>
        </div>
        <div class="fin-oc-print-preview-body bank-check-page" id="fin-oc-print-preview"></div>
    </div>
</div>

<script src="<?= esc($jsUrl) ?>"></script>
