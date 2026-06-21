<?php
declare(strict_types=1);

require_once app_path('includes/sys_audit_log.php');
require_once app_path('includes/document_header.php');

$pdo = db();
sys_audit_log_ensure_schema($pdo);

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$domainCode = trim((string) ($_GET['domain'] ?? ''));
$screenCode = trim((string) ($_GET['screen'] ?? ''));
$userId = (int) ($_GET['user_id'] ?? 0);

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$err = '';
$showResult = false;

$domainOptions = sys_audit_log_domain_options();
$screenOptions = sys_audit_log_screen_options($domainCode !== '' ? $domainCode : null);
$userOptions = sys_audit_log_user_options($pdo);

$submitted = isset($_GET['from']) || isset($_GET['to']) || isset($_GET['domain'])
    || isset($_GET['screen']) || isset($_GET['user_id']);

if ($submitted) {
    $fromIso = parse_date_to_iso($from);
    $toIso = parse_date_to_iso($to);
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $from = $fromIso;
        $to = $toIso;
        $rows = sys_audit_log_fetch(
            $pdo,
            $fromIso,
            $toIso,
            $domainCode !== '' ? $domainCode : null,
            $screenCode !== '' ? $screenCode : null,
            $userId > 0 ? $userId : null
        );
        $showResult = true;
    }
}

$reportTitle = 'حركات التعديل';
$domainLabel = 'الكل';
if ($domainCode !== '') {
    foreach ($domainOptions as $d) {
        if ($d['id'] === $domainCode) {
            $domainLabel = $d['title'];
            break;
        }
    }
}

$screenLabel = 'الكل';
if ($screenCode !== '') {
    foreach ($screenOptions as $s) {
        if ($s['code'] === $screenCode) {
            $screenLabel = $s['label'];
            break;
        }
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$sortJsPath = app_path('assets/js/report-sales-table-sort.js');
$sortJsUrl = app_url('assets/js/report-sales-table-sort.js') . (is_file($sortJsPath) ? '?v=' . (string) filemtime($sortJsPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_audit_log"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($domainLabel . ' / ' . $screenLabel) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_audit_log">
        <div class="form-row">
            <label class="field" style="flex:1 1 10rem;">
                <span class="field-label">من تاريخ</span>
                <input class="input" type="text" name="from" value="<?= esc(format_date_dmY($from)) ?>" placeholder="يوم-شهر-سنة" autocomplete="off">
            </label>
            <label class="field" style="flex:1 1 10rem;">
                <span class="field-label">إلى تاريخ</span>
                <input class="input" type="text" name="to" value="<?= esc(format_date_dmY($to)) ?>" placeholder="يوم-شهر-سنة" autocomplete="off">
            </label>
            <label class="field" style="flex:1 1 12rem;">
                <span class="field-label">القائمة / المجال</span>
                <select class="input" name="domain" id="audit-domain">
                    <option value="">— الكل —</option>
                    <?php foreach ($domainOptions as $d): ?>
                        <option value="<?= esc($d['id']) ?>" <?= $domainCode === $d['id'] ? 'selected' : '' ?>><?= esc($d['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" style="flex:1 1 14rem;">
                <span class="field-label">الشاشة</span>
                <select class="input" name="screen" id="audit-screen">
                    <option value="">— الكل —</option>
                    <?php foreach ($screenOptions as $s): ?>
                        <option value="<?= esc($s['code']) ?>" <?= $screenCode === $s['code'] ? 'selected' : '' ?>><?= esc($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" style="flex:1 1 12rem;">
                <span class="field-label">المستخدم</span>
                <select class="input" name="user_id">
                    <option value="0">— الكل —</option>
                    <?php foreach ($userOptions as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= esc($u['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="field" style="flex:0 0 auto;align-self:flex-end;">
                <button type="submit" class="btn btn-primary">عرض</button>
            </div>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>الفترة:</strong> <?= esc(format_date_dmY($from)) ?> — <?= esc(format_date_dmY($to)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>المجال:</strong> <?= esc($domainLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>الشاشة:</strong> <?= esc($screenLabel) ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>عدد الحركات:</strong> <?= count($rows) ?></td>
                    </tr>
                </table>
            </div>

        <div class="report-sales-table-wrap">
            <table class="report-sales-table report-audit-log-table js-sortable-report"
                   data-default-sort="logged_at"
                   data-default-dir="desc">
                <colgroup>
                    <col class="col-seq">
                    <col class="col-audit-datetime">
                    <col class="col-audit-user">
                    <col class="col-audit-screen">
                    <col class="col-audit-action">
                    <col class="col-audit-ref">
                    <col class="col-audit-docdate">
                    <col class="col-audit-summary">
                </colgroup>
                <thead>
                <tr>
                    <th class="col-seq js-sort-th" data-sort="seq" data-sort-type="number">ت</th>
                    <th class="col-audit-datetime js-sort-th" data-sort="logged_at" data-sort-type="text">تاريخ ووقت الحركة</th>
                    <th class="col-audit-user js-sort-th" data-sort="user_name" data-sort-type="text">المستخدم</th>
                    <th class="col-audit-screen js-sort-th" data-sort="screen_label" data-sort-type="text">الشاشة</th>
                    <th class="col-audit-action js-sort-th" data-sort="action_label" data-sort-type="text">نوع الحركة</th>
                    <th class="col-audit-ref js-sort-th" data-sort="entity_ref" data-sort-type="text">المرجع</th>
                    <th class="col-audit-docdate js-sort-th" data-sort="doc_date" data-sort-type="date">تاريخ المستند</th>
                    <th class="col-audit-summary js-sort-th" data-sort="summary" data-sort-type="text">ملاحظة</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="8" class="muted" style="text-align:center;padding:1.25rem;">
                            لا توجد حركات مطابقة للفلتر المحدد.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php
                $seq = 0;
                foreach ($rows as $r):
                    $seq += 1;
                    $entityUrl = sys_audit_log_entity_url((string) ($r['screen_code'] ?? ''), isset($r['entity_id']) ? (int) $r['entity_id'] : null);
                    $loggedAt = (string) ($r['logged_at'] ?? '');
                    $loggedAtDisplay = '—';
                    if ($loggedAt !== '') {
                        $ts = strtotime($loggedAt);
                        if ($ts !== false) {
                            $loggedAtDisplay = date('d-m-Y H:i:s', $ts);
                        } else {
                            $loggedAtDisplay = $loggedAt;
                        }
                    }
                    $actionCode = (string) ($r['action_code'] ?? '');
                    $actionBadgeClass = match ($actionCode) {
                        'delete' => 'report-audit-badge-delete',
                        'unpost' => 'badge-warn',
                        'post' => 'badge-ok',
                        default => 'badge-off',
                    };
                    ?>
                    <tr data-sort-row="1"
                        data-sort-seq="<?= $seq ?>"
                        data-sort-logged_at="<?= esc($loggedAt) ?>"
                        data-sort-user_name="<?= esc((string) ($r['user_name'] ?? '')) ?>"
                        data-sort-screen_label="<?= esc((string) ($r['screen_label_ar'] ?? '')) ?>"
                        data-sort-action_label="<?= esc((string) ($r['action_label_ar'] ?? '')) ?>"
                        data-sort-entity_ref="<?= esc((string) ($r['entity_ref'] ?? '')) ?>"
                        data-sort-doc_date="<?= esc((string) ($r['doc_date'] ?? '')) ?>"
                        data-sort-summary="<?= esc((string) ($r['summary'] ?? '')) ?>">
                        <td class="col-seq"><?= $seq ?></td>
                        <td class="col-audit-datetime" dir="ltr"><?= esc($loggedAtDisplay) ?></td>
                        <td class="col-audit-user"><?= esc((string) ($r['user_name'] ?? '—')) ?></td>
                        <td class="col-audit-screen"><?= esc((string) ($r['screen_label_ar'] ?? '')) ?></td>
                        <td class="col-audit-action">
                            <span class="badge <?= esc($actionBadgeClass) ?>"><?= esc((string) ($r['action_label_ar'] ?? '')) ?></span>
                        </td>
                        <td class="col-audit-ref">
                            <?php if ($entityUrl !== null): ?>
                                <span class="report-audit-ref-wrap">
                                    <code><?= esc((string) ($r['entity_ref'] ?? '—')) ?></code>
                                    <a class="btn btn-ghost btn-sm no-print report-audit-ref-link"
                                       href="<?= esc($entityUrl) ?>">عرض</a>
                                </span>
                            <?php else: ?>
                                <?= esc((string) ($r['entity_ref'] ?? '—')) ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-audit-docdate" dir="ltr"><?= ($r['doc_date'] ?? '') !== '' ? esc(format_date_dmY((string) $r['doc_date'])) : '—' ?></td>
                        <td class="col-audit-summary"><?= esc((string) ($r['summary'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </div>
    <?php endif; ?>
</div>

<script src="<?= esc($exportJsUrl) ?>"></script>
<script src="<?= esc($sortJsUrl) ?>"></script>
