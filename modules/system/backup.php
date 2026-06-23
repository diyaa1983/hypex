<?php
declare(strict_types=1);

require_once app_path('includes/sys_backup.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
sys_backup_ensure_schema($pdo);

$listUrl = app_url('index.php?r=system_backup');
$flash = flash_get();
$settings = sys_backup_settings($pdo);
$backupDir = (string) ($settings['backup_dir'] ?? '');
$lastBackupAt = $settings['last_backup_at'] ?? null;
$lastBackupPath = (string) ($settings['last_backup_path'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $action = (string) ($_POST['_action'] ?? '');

    try {
        if ($action === 'save_dir') {
            $dir = trim((string) ($_POST['backup_dir'] ?? ''));
            $userId = (int) (current_user()['id'] ?? 0);
            sys_backup_save_dir($pdo, $dir, $userId);
            flash_set('success', 'تم حفظ مجلد النسخ الاحتياطي. يمكنك الآن أخذ نسخة احتياطية.');
            redirect($listUrl);
        }
        if ($action === 'use_recommended') {
            $userId = (int) (current_user()['id'] ?? 0);
            $dir = sys_backup_recommended_dir();
            sys_backup_save_dir($pdo, $dir, $userId);
            flash_set('success', 'تم حفظ المسار المقترح للخادم: ' . $dir);
            redirect($listUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl);
    }

    redirect($listUrl);
}

$settings = sys_backup_settings($pdo);
$backupDir = (string) ($settings['backup_dir'] ?? '');
$lastBackupAt = $settings['last_backup_at'] ?? null;
$lastBackupPath = (string) ($settings['last_backup_path'] ?? '');
$todayFolder = sys_backup_today_folder_name();
$hasDir = $backupDir !== '';
$recommendedDir = sys_backup_recommended_dir();
$pathIssue = sys_backup_path_issue($backupDir);
$canRunBackup = $hasDir && $pathIssue === null;
$serverLabel = sys_backup_server_label();
$exitUrl = nav_exit_url('system_backup');

$cssPath = app_path('assets/css/sys-backup.css');
$cssUrl = app_url('assets/css/sys-backup.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$backupApiUrl = app_url('api/backup_run.php');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="dashboard-ora sys-backup-page"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-backup-api="<?= esc($backupApiUrl) ?>"
     data-csrf="<?= esc(csrf_token()) ?>">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">النسخ الاحتياطي</h1>
        <?php nav_render_screen_close('system_backup'); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sys-backup-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <section class="dashboard-ora-panel sys-backup-panel">
            <h2 class="dashboard-ora-panel__title">مجلد حفظ النسخ</h2>
            <div class="dashboard-ora-panel__body">
                <p class="sys-backup-note muted">
                    الخادم الحالي: <strong dir="ltr"><?= esc($serverLabel) ?></strong>.
                    حدّد مجلداً على جهاز الخادم (مرة واحدة) لحفظ النسخ الاحتياطية.
                    كل نسخة تُنشأ في مجلد باسم تاريخ اليوم (مثل: <code dir="ltr"><?= esc($todayFolder) ?></code>).
                </p>

                <?php if ($pathIssue !== null): ?>
                <div class="alert alert-error sys-backup-flash">
                    <?= esc($pathIssue) ?>
                </div>
                <?php endif; ?>

                <form method="post" action="<?= esc($listUrl) ?>" class="sys-backup-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save_dir">

                    <label class="field sys-backup-field">
                        <span class="field-label required">مسار مجلد النسخ الاحتياطي</span>
                        <input class="input" type="text" name="backup_dir" id="sys-backup-dir-input" required dir="ltr"
                               placeholder="<?= esc($recommendedDir) ?>"
                               value="<?= esc($pathIssue !== null ? $recommendedDir : ($backupDir !== '' ? $backupDir : $recommendedDir)) ?>" autocomplete="off">
                    </label>
                    <p class="sys-backup-hint muted">
                        المسار المقترح لهذا الخادم:
                        <code dir="ltr"><?= esc($recommendedDir) ?></code>
                        <?php if (sys_backup_is_linux_server()): ?>
                        — لا تستخدم <code dir="ltr">D:\...</code> على Linux.
                        <?php endif; ?>
                    </p>

                    <div class="sys-backup-actions">
                        <button type="submit" class="btn btn-primary">حفظ المسار</button>
                    </div>
                </form>
                <form method="post" action="<?= esc($listUrl) ?>" class="sys-backup-form sys-backup-form--inline">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="use_recommended">
                    <button type="submit" class="btn btn-ghost">استخدام المسار المقترح تلقائياً</button>
                </form>
            </div>
        </section>

        <section class="dashboard-ora-panel sys-backup-panel">
            <h2 class="dashboard-ora-panel__title">أخذ نسخة احتياطية</h2>
            <div class="dashboard-ora-panel__body">
                <ul class="sys-backup-contents">
                    <li>نسخة من <strong>قاعدة البيانات</strong> (ملف <code dir="ltr">database.sql</code>)</li>
                    <li>نسخة من <strong>ملفات النظام</strong> (ملف <code dir="ltr">system_files.zip</code>)</li>
                </ul>

                <?php if ($lastBackupAt !== null): ?>
                    <p class="sys-backup-last">
                        <strong>آخر نسخة:</strong>
                        <span dir="ltr"><?= esc($lastBackupAt !== null ? date('d/m/Y H:i', strtotime((string) $lastBackupAt) ?: time()) : '—') ?></span>
                        <?php if ($lastBackupPath !== ''): ?>
                            <br><span class="muted" dir="ltr"><?= esc($lastBackupPath) ?></span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <div class="sys-backup-run-wrap">
                    <button type="button" class="btn btn-primary btn-lg sys-backup-run-btn" id="sys-backup-run-btn"
                            <?= $canRunBackup ? '' : 'disabled aria-disabled="true" title="احفظ مساراً صالحاً للخادم أولاً"' ?>>
                        💾 أخذ نسخة احتياطية الآن
                    </button>
                    <?php if (!$hasDir): ?>
                        <p class="sys-backup-warn muted">احفظ مجلد النسخ الاحتياطي أولاً ثم اضغط الزر.</p>
                    <?php elseif ($pathIssue !== null): ?>
                        <p class="sys-backup-warn muted">المسار الحالي غير صالح لهذا الخادم. اضغط «استخدام المسار المقترح تلقائياً» ثم أعد المحاولة.</p>
                    <?php else: ?>
                        <p class="muted">مجلد اليوم: <code dir="ltr"><?= esc(sys_backup_normalize_dir($backupDir . DIRECTORY_SEPARATOR . $todayFolder)) ?></code></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</div>

<div id="sys-backup-busy" class="sys-backup-busy sys-backup-busy--oracle no-print" hidden aria-live="polite" aria-busy="true">
    <div class="sys-backup-busy-oracle" role="status">
        <div class="sys-backup-busy-oracle-body">
            <p class="sys-backup-busy-oracle-line">Connected to: MySQL Database Server</p>
            <p class="sys-backup-busy-oracle-line sys-backup-busy-oracle-line--active">
                Processing export<span class="sys-backup-busy-dots">...</span><span class="sys-backup-busy-cursor">_</span>
            </p>
            <p class="sys-backup-busy-oracle-line">جاري أخذ نسخة قاعدة البيانات...</p>
            <p class="sys-backup-busy-oracle-line">جاري ضغط ملفات النظام...</p>
            <p class="sys-backup-busy-oracle-line sys-backup-busy-oracle-muted">يرجى الانتظار — لا تغلق المتصفح حتى انتهاء العملية</p>
        </div>
    </div>
</div>
