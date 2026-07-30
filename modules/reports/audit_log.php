<?php
declare(strict_types=1);

require_once app_path('includes/sys_audit_log.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/list_pagination.php');

$pdo = db();
sys_audit_log_ensure_schema($pdo);

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$domainCode = trim((string) ($_GET['domain'] ?? ''));
$screenCode = trim((string) ($_GET['screen'] ?? ''));
$userId = (int) ($_GET['user_id'] ?? 0);
$searchQ = trim((string) ($_GET['q'] ?? ''));

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$err = '';
$showResult = false;
$listTotal = 0;
$pager = list_pager_from_request($pdo);
$listPagerUrl = '';

$domainOptions = sys_audit_log_domain_options();
$screenOptions = sys_audit_log_screen_options($domainCode !== '' ? $domainCode : null);
$userOptions = sys_audit_log_user_options($pdo);

$submitted = isset($_GET['from']) || isset($_GET['to']) || isset($_GET['domain'])
    || isset($_GET['screen']) || isset($_GET['user_id']) || isset($_GET['q'])
    || isset($_GET['page']);

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
        $domainFilter = $domainCode !== '' ? $domainCode : null;
        $screenFilter = $screenCode !== '' ? $screenCode : null;
        $userFilter = $userId > 0 ? $userId : null;
        $searchFilter = $searchQ !== '' ? $searchQ : null;

        $listTotal = sys_audit_log_count(
            $pdo,
            $fromIso,
            $toIso,
            $domainFilter,
            $screenFilter,
            $userFilter,
            $searchFilter
        );
        $pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
        $rows = sys_audit_log_fetch(
            $pdo,
            $fromIso,
            $toIso,
            $domainFilter,
            $screenFilter,
            $userFilter,
            $searchFilter,
            (int) $pager['limit'],
            (int) $pager['offset']
        );
        $pagerQuery = [
            'from' => format_date_dmY($from),
            'to' => format_date_dmY($to),
        ];
        if ($domainCode !== '') {
            $pagerQuery['domain'] = $domainCode;
        }
        if ($screenCode !== '') {
            $pagerQuery['screen'] = $screenCode;
        }
        if ($userId > 0) {
            $pagerQuery['user_id'] = $userId;
        }
        if ($searchQ !== '') {
            $pagerQuery['q'] = $searchQ;
        }
        $listPagerUrl = list_pager_base_url('report_audit_log', $pagerQuery);
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

    <div class="report-sales-result report-sales-print-area">
        <?= document_print_header_html($reportTitle, $pdo) ?>

        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print report-audit-filters">
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
                <label class="field" style="flex:1 1 14rem;">
                    <span class="field-label">بحث</span>
                    <input class="input" type="search" name="q" value="<?= esc($searchQ) ?>"
                           placeholder="مرجع، مستخدم، شاشة، نوع حركة…" autocomplete="off">
                </label>
                <div class="field" style="flex:0 0 auto;align-self:flex-end;">
                    <button type="submit" class="btn btn-primary">عرض</button>
                </div>
            </div>
        </form>

    <?php if ($showResult): ?>
            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>الفترة:</strong> <?= esc(format_date_dmY($from)) ?> — <?= esc(format_date_dmY($to)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>المجال:</strong> <?= esc($domainLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>الشاشة:</strong> <?= esc($screenLabel) ?>
                            <?php if ($searchQ !== ''): ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                <strong>بحث:</strong> <?= esc($searchQ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>عدد الحركات:</strong> <?= (int) $listTotal ?></td>
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
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">
                            لا توجد حركات مطابقة للفلتر المحدد.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php
                $seq = (int) $pager['offset'];
                foreach ($rows as $r):
                    $seq += 1;
                    $entityUrl = sys_audit_log_entity_url((string) ($r['screen_code'] ?? ''), isset($r['entity_id']) ? (int) $r['entity_id'] : null);
                    $entityRef = (string) ($r['entity_ref'] ?? '—');
                    $loggedAt = (string) ($r['logged_at'] ?? '');
                    $loggedAtDate = '';
                    $loggedAtTime = '';
                    $loggedAtSort = $loggedAt;
                    if ($loggedAt !== '') {
                        $ts = strtotime($loggedAt);
                        if ($ts !== false) {
                            $loggedAtDate = date('d-m-Y', $ts);
                            $loggedAtTime = date('H:i:s', $ts);
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
                        data-sort-logged_at="<?= esc($loggedAtSort) ?>"
                        data-sort-user_name="<?= esc((string) ($r['user_name'] ?? '')) ?>"
                        data-sort-screen_label="<?= esc((string) ($r['screen_label_ar'] ?? '')) ?>"
                        data-sort-action_label="<?= esc((string) ($r['action_label_ar'] ?? '')) ?>"
                        data-sort-entity_ref="<?= esc($entityRef) ?>"
                        data-sort-doc_date="<?= esc((string) ($r['doc_date'] ?? '')) ?>">
                        <td class="col-seq"><?= $seq ?></td>
                        <td class="col-audit-datetime" dir="ltr">
                            <?php if ($loggedAtDate !== ''): ?>
                                <span class="report-audit-dt">
                                    <span class="report-audit-dt__date"><?= esc($loggedAtDate) ?></span>
                                    <span class="report-audit-dt__time"><?= esc($loggedAtTime) ?></span>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="col-audit-user"><?= esc((string) ($r['user_name'] ?? '—')) ?></td>
                        <td class="col-audit-screen"><?= esc((string) ($r['screen_label_ar'] ?? '')) ?></td>
                        <td class="col-audit-action">
                            <span class="badge <?= esc($actionBadgeClass) ?>"><?= esc((string) ($r['action_label_ar'] ?? '')) ?></span>
                        </td>
                        <td class="col-audit-ref">
                            <?php if ($entityUrl !== null): ?>
                                <a class="report-audit-ref-link no-print"
                                   href="<?= esc($entityUrl) ?>"
                                   title="فتح المستند <?= esc($entityRef) ?>">
                                    <code><?= esc($entityRef) ?></code>
                                </a>
                                <code class="report-audit-ref-print-only"><?= esc($entityRef) ?></code>
                            <?php else: ?>
                                <code><?= esc($entityRef) ?></code>
                            <?php endif; ?>
                        </td>
                        <td class="col-audit-docdate" dir="ltr"><?= ($r['doc_date'] ?? '') !== '' ? esc(format_date_dmY((string) $r['doc_date'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($listPagerUrl !== ''): ?>
            <div class="no-print" style="margin-top:0.75rem;">
                <?php list_pager_render($pager, $listPagerUrl); ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>

<script src="<?= esc($exportJsUrl) ?>"></script>
<script src="<?= esc($sortJsUrl) ?>"></script>
