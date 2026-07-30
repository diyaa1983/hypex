<?php
declare(strict_types=1);

/**
 * الجلسات المفتوحة — Windows / Mobile مع IP والموقع وإنهاء الجلسة.
 */
require_once app_path('includes/sys_user_open_session.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/sql_migration.php');

$pdo = db();
try {
    sql_migration_run_file($pdo, 'database/migrations/231_sys_user_open_session.sql');
} catch (Throwable $e) {
    // ensure_schema يغطي الجدول إن فشل الملف
}
sys_user_open_session_ensure_schema($pdo);

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $msgType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'kill') {
            $sid = (int) ($_POST['session_id'] ?? 0);
            $adminId = (int) (current_user()['id'] ?? 0);
            $result = sys_user_open_session_kill($pdo, $sid, $adminId > 0 ? $adminId : null);
            $msg = $result['message'];
            $msgType = $result['ok'] ? 'success' : 'error';
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$clientType = trim((string) ($_GET['client_type'] ?? ''));
if ($clientType !== 'windows' && $clientType !== 'mobile') {
    $clientType = '';
}

$rows = sys_user_open_session_list_active($pdo, $search, $clientType !== '' ? $clientType : null);
$exitUrl = nav_exit_url('open_sessions');
$pageUrl = app_url('index.php?r=open_sessions');

$cssPath = app_path('assets/css/settings-oracle12.css');
$cssUrl = app_url('assets/css/settings-oracle12.css')
    . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
?>
<?php sales_ora12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style>
.open-sessions-page .os-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border-radius: 999px;
  padding: 3px 10px;
  font-size: 12px;
  font-weight: 800;
  white-space: nowrap;
}
.open-sessions-page .os-badge--windows {
  background: #e8f1ff;
  color: #0b63ce;
}
.open-sessions-page .os-badge--mobile {
  background: #e9f9f0;
  color: #0f8a4b;
}
.open-sessions-page .os-ip {
  font-family: ui-monospace, Consolas, monospace;
  direction: ltr;
  unicode-bidi: embed;
  font-weight: 700;
}
.open-sessions-page .os-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  align-items: end;
}
.open-sessions-page .os-filters .field { margin: 0; min-width: 10rem; }
.open-sessions-page .os-kill {
  white-space: nowrap;
}
</style>

<div class="dashboard-ora sales-ora12-screen open-sessions-page" data-exit-guard-root>
    <?php sales_ora12_render_title_bar('الجلسات المفتوحة'); ?>
    <?php sales_ora12_workspace_open(); ?>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= esc($msg) ?></div>
    <?php endif; ?>

    <section class="dashboard-ora-panel">
        <div class="dashboard-ora-panel__body">
            <form method="get" action="<?= esc(app_url('index.php')) ?>" class="os-filters">
                <input type="hidden" name="r" value="open_sessions">
                <label class="field">
                    <span class="field-label">بحث</span>
                    <input class="input" type="search" name="q" value="<?= esc($search) ?>"
                           placeholder="مستخدم / IP / جهاز" autocomplete="off">
                </label>
                <label class="field">
                    <span class="field-label">النوع</span>
                    <select class="input" name="client_type">
                        <option value="" <?= $clientType === '' ? 'selected' : '' ?>>الكل</option>
                        <option value="windows" <?= $clientType === 'windows' ? 'selected' : '' ?>>Windows</option>
                        <option value="mobile" <?= $clientType === 'mobile' ? 'selected' : '' ?>>Mobile</option>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary btn-sm">عرض</button>
                <a class="btn btn-secondary btn-sm" href="<?= esc($pageUrl) ?>">تحديث</a>
            </form>
            <p class="field-hint" style="margin:0.75rem 0 0;">
                تُعرض الجلسات النشطة فقط (Windows خلال 30 دقيقة، Mobile خلال 3 دقائق).
                إنهاء الجلسة يفصل المستخدم عند الطلب التالي.
            </p>
        </div>
    </section>

    <section class="dashboard-ora-panel">
        <h2 class="dashboard-ora-panel__title">
            الجلسات النشطة
            <span class="dashboard-ora-screen-title__meta">(<?= count($rows) ?>)</span>
        </h2>
        <div class="dashboard-ora-panel__body">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>المستخدم</th>
                        <th style="width:7rem;">النوع</th>
                        <th>الجهاز / التسمية</th>
                        <th style="width:9rem;">IP</th>
                        <th>المكان</th>
                        <th style="width:9.5rem;">دخول</th>
                        <th style="width:9.5rem;">آخر نشاط</th>
                        <th style="width:7rem;"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="8" class="muted" style="text-align:center;padding:1.5rem;">
                                لا توجد جلسات مفتوحة حالياً.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $type = (string) ($r['client_type'] ?? 'windows');
                            $badgeClass = $type === 'mobile' ? 'os-badge--mobile' : 'os-badge--windows';
                            $name = trim((string) ($r['full_name_ar'] ?? ''));
                            if ($name === '') {
                                $name = (string) ($r['username'] ?? '');
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= esc($name) ?></strong>
                                    <div class="muted" style="font-size:12px;"><?= esc((string) ($r['username'] ?? '')) ?></div>
                                </td>
                                <td>
                                    <span class="os-badge <?= esc($badgeClass) ?>">
                                        <?= $type === 'mobile' ? '📱 Mobile' : '🖥 Windows' ?>
                                    </span>
                                </td>
                                <td><?= esc((string) ($r['client_label'] ?? '—')) ?></td>
                                <td class="os-ip"><?= esc((string) ($r['ip_address'] ?? '—')) ?></td>
                                <td>
                                    <?= esc((string) ($r['place_display'] ?? '—')) ?>
                                    <?php if (!empty($r['map_url'])): ?>
                                        <div>
                                            <a href="<?= esc((string) $r['map_url']) ?>" target="_blank" rel="noopener">خريطة</a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc((string) ($r['login_at_hi'] ?? '—')) ?></td>
                                <td><?= esc((string) ($r['last_seen_hi'] ?? '—')) ?></td>
                                <td>
                                    <form method="post" action="<?= esc($pageUrl) ?>" class="os-kill-form">
                                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="kill">
                                        <input type="hidden" name="session_id" value="<?= (int) ($r['id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-sm btn-danger os-kill-btn">إنهاء</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php sales_ora12_workspace_close(); ?>
</div>
<script>
(function () {
  document.querySelectorAll('.os-kill-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var submitForm = function () { form.submit(); };
      if (window.AppDialog && typeof AppDialog.confirm === 'function') {
        AppDialog.confirm('إنهاء جلسة هذا المستخدم؟', {
          title: 'إنهاء الجلسة',
          okText: 'إنهاء',
          cancelText: 'إلغاء',
          type: 'warning',
          danger: true,
        }).then(function (ok) {
          if (ok) submitForm();
        });
        return;
      }
      if (window.confirm('إنهاء جلسة هذا المستخدم؟')) {
        submitForm();
      }
    });
  });
})();
</script>
