<?php
declare(strict_types=1);

require_permission('system_error_log');
require_once app_path('includes/sys_error_log.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/list_pagination.php');

$pdo = db();
sys_error_log_ensure_schema($pdo);

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$source = trim((string) ($_GET['source'] ?? 'all'));
$searchQ = trim((string) ($_GET['q'] ?? ''));
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $msgType = 'error';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'clear_all') {
            $n = sys_error_log_clear($pdo);
            $msg = 'تم مسح سجل الأخطاء (' . $n . ' سجل).';
            $msgType = 'success';
        } elseif ($action === 'clear_before') {
            $before = trim((string) ($_POST['before_date'] ?? ''));
            $beforeIso = parse_date_to_iso($before);
            if ($beforeIso === null) {
                $msg = 'تاريخ المسح غير صالح.';
                $msgType = 'error';
            } else {
                $n = sys_error_log_clear($pdo, $beforeIso);
                $msg = 'تم حذف السجلات الأقدم من ' . format_date_dmY($beforeIso) . ' (' . $n . ').';
                $msgType = 'success';
            }
        }
    }
}

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$err = '';
$listTotal = 0;
$pager = list_pager_from_request($pdo);
$listPagerUrl = '';
$showResult = true;

$fromIso = parse_date_to_iso($from);
$toIso = parse_date_to_iso($to);
if ($fromIso === null || $toIso === null) {
    $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    $showResult = false;
} elseif ($fromIso > $toIso) {
    $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    $showResult = false;
} else {
    $from = $fromIso;
    $to = $toIso;
    $sourceFilter = ($source !== '' && $source !== 'all') ? $source : null;
    $fetched = sys_error_log_fetch(
        $pdo,
        $fromIso,
        $toIso,
        $sourceFilter,
        $searchQ !== '' ? $searchQ : null,
        1,
        0
    );
    $listTotal = (int) $fetched['total'];
    $pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
    $fetched = sys_error_log_fetch(
        $pdo,
        $fromIso,
        $toIso,
        $sourceFilter,
        $searchQ !== '' ? $searchQ : null,
        (int) $pager['limit'],
        (int) $pager['offset']
    );
    $rows = $fetched['rows'];
    $pagerQuery = [
        'from' => format_date_dmY($from),
        'to' => format_date_dmY($to),
        'source' => $source,
    ];
    if ($searchQ !== '') {
        $pagerQuery['q'] = $searchQ;
    }
    $listPagerUrl = list_pager_base_url('system_error_log', $pagerQuery);
}

$sourceOptions = [
    'all' => 'الكل',
    'server' => 'الخادم',
    'fatal' => 'خطأ قاتل',
    'ui' => 'واجهة المستخدم',
    'api' => 'واجهة API',
];

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$pageTitle = 'سجل أخطاء النظام';
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style>
.sys-error-log-detail {
  max-width: 42rem;
  white-space: pre-wrap;
  word-break: break-word;
  font-family: ui-monospace, Consolas, monospace;
  font-size: 0.82rem;
  direction: ltr;
  text-align: left;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 0.75rem 0.9rem;
  margin: 0;
}
.sys-error-log-msg { font-weight: 600; }
.sys-error-log-row { cursor: pointer; }
.sys-error-log-row:hover { background: #fef2f2; }
.sys-error-log-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: flex-end; }
.sys-error-log-badge-fatal { background: #7f1d1d; color: #fff; }
.sys-error-log-badge-ui { background: #9a3412; color: #fff; }
.sys-error-log-badge-server { background: #1e3a5f; color: #fff; }
.sys-error-log-badge-api { background: #334155; color: #fff; }
</style>

<div class="card report-sales-page" data-report-title="<?= esc($pageTitle) ?>" data-report-route="system_error_log">

    <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?> no-print" style="margin-bottom:1rem;"><?= esc($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <div class="report-sales-result report-sales-print-area">
        <?= document_print_header_html($pageTitle, $pdo) ?>

        <p class="field-hint no-print" style="margin:0 0 0.75rem;">
            تُخزَّن هنا الأخطاء والاستثناءات الظاهرة للمستخدمين (واجهة المستخدم والخادم) لمراجعتها من المسؤول.
        </p>

        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
            <input type="hidden" name="r" value="system_error_log">
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
                    <span class="field-label">المصدر</span>
                    <select class="input" name="source">
                        <?php foreach ($sourceOptions as $code => $label): ?>
                            <option value="<?= esc($code) ?>" <?= $source === $code ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field" style="flex:1 1 14rem;">
                    <span class="field-label">بحث</span>
                    <input class="input" type="search" name="q" value="<?= esc($searchQ) ?>"
                           placeholder="نص الخطأ، مستخدم، رابط…" autocomplete="off">
                </label>
                <div class="field" style="flex:0 0 auto;align-self:flex-end;">
                    <button type="submit" class="btn btn-primary">عرض</button>
                </div>
            </div>
        </form>

        <form method="post" class="no-print sys-error-log-actions" style="margin:0 0 1rem;" id="sys-error-log-clear-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="action" id="sys-error-log-clear-action" value="clear_before">
            <label class="field" style="flex:0 1 10rem;">
                <span class="field-label">حذف الأقدم من</span>
                <input class="input" type="text" name="before_date" value="<?= esc(format_date_dmY($from)) ?>" placeholder="يوم-شهر-سنة" autocomplete="off">
            </label>
            <button type="button" class="btn" id="sys-error-log-clear-before">حذف الأقدم</button>
            <button type="button" class="btn btn-danger" id="sys-error-log-clear-all">مسح السجل بالكامل</button>
        </form>

        <?php if ($showResult): ?>
            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>الفترة:</strong> <?= esc(format_date_dmY($from)) ?> — <?= esc(format_date_dmY($to)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>المصدر:</strong> <?= esc($sourceOptions[$source] ?? $source) ?>
                            <?php if ($searchQ !== ''): ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                <strong>بحث:</strong> <?= esc($searchQ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>عدد السجلات:</strong> <?= (int) $listTotal ?></td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table">
                    <thead>
                    <tr>
                        <th class="col-seq">ت</th>
                        <th>آخر ظهور</th>
                        <th>المصدر</th>
                        <th>الرسالة</th>
                        <th>المستخدم</th>
                        <th>الشاشة / الرابط</th>
                        <th>مرات</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد أخطاء مطابقة للفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = (int) $pager['offset'];
                    foreach ($rows as $r):
                        $seq += 1;
                        $src = (string) ($r['source'] ?? 'server');
                        $badgeClass = match ($src) {
                            'fatal' => 'sys-error-log-badge-fatal',
                            'ui', 'client' => 'sys-error-log-badge-ui',
                            'api' => 'sys-error-log-badge-api',
                            default => 'sys-error-log-badge-server',
                        };
                        $lastSeen = (string) ($r['last_seen_at'] ?? $r['logged_at'] ?? '');
                        $lastSeenLabel = $lastSeen;
                        $ts = $lastSeen !== '' ? strtotime($lastSeen) : false;
                        if ($ts !== false) {
                            $lastSeenLabel = date('d-m-Y H:i:s', $ts);
                        }
                        $detail = (string) ($r['detail'] ?? '');
                        if (strlen($detail) > 6000) {
                            $detail = substr($detail, 0, 6000) . "\n…";
                        }
                        $message = (string) ($r['message'] ?? '');
                        $screen = trim((string) ($r['screen_code'] ?? ''));
                        $uri = trim((string) ($r['request_uri'] ?? ''));
                        $where = $screen !== '' ? $screen : ($uri !== '' ? $uri : '—');
                        ?>
                        <tr class="sys-error-log-row"
                            data-message="<?= esc($message) ?>"
                            data-detail="<?= esc($detail) ?>"
                            title="عرض التفاصيل">
                            <td class="col-seq"><?= $seq ?></td>
                            <td dir="ltr"><?= esc($lastSeenLabel) ?></td>
                            <td><span class="badge <?= esc($badgeClass) ?>"><?= esc(sys_error_log_source_label($src)) ?></span></td>
                            <td class="sys-error-log-msg"><?= esc($message) ?></td>
                            <td><?= esc((string) ($r['username'] ?? '—')) ?></td>
                            <td dir="ltr" style="font-size:0.85rem;"><?= esc($where) ?></td>
                            <td><?= (int) ($r['occurrence_count'] ?? 1) ?></td>
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

<script>
(function () {
  var clearForm = document.getElementById('sys-error-log-clear-form');
  var clearAction = document.getElementById('sys-error-log-clear-action');
  var btnBefore = document.getElementById('sys-error-log-clear-before');
  var btnAll = document.getElementById('sys-error-log-clear-all');

  function submitClear(action, confirmMsg) {
    if (!clearForm || !clearAction) return;
    var go = window.AppDialog && typeof AppDialog.confirm === 'function'
      ? AppDialog.confirm(confirmMsg, { type: 'confirm', title: 'تأكيد' })
      : Promise.resolve(window.confirm(confirmMsg));
    Promise.resolve(go).then(function (ok) {
      if (!ok) return;
      clearAction.value = action;
      clearForm.submit();
    });
  }

  if (btnBefore) {
    btnBefore.addEventListener('click', function () {
      submitClear('clear_before', 'حذف السجلات الأقدم من التاريخ المحدد؟');
    });
  }
  if (btnAll) {
    btnAll.addEventListener('click', function () {
      submitClear('clear_all', 'مسح كل سجل الأخطاء؟ لا يمكن التراجع.');
    });
  }

  document.querySelectorAll('.sys-error-log-row').forEach(function (row) {
    row.addEventListener('click', function () {
      var msg = row.getAttribute('data-message') || '';
      var detail = row.getAttribute('data-detail') || '';
      var html = '<p style="margin:0 0 .75rem;font-weight:600;">' + escapeHtml(msg) + '</p>';
      if (detail) {
        html += '<pre class="sys-error-log-detail">' + escapeHtml(detail) + '</pre>';
      } else {
        html += '<p class="muted" style="margin:0;">لا توجد تفاصيل إضافية.</p>';
      }
      if (window.AppDialog && typeof AppDialog.alert === 'function') {
        AppDialog.alert(html, { type: 'info', title: 'تفاصيل الخطأ', html: true, skipLog: true });
      } else {
        window.alert(msg + (detail ? '\n\n' + detail : ''));
      }
    });
  });

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
})();
</script>
