<?php
declare(strict_types=1);

require_once app_path('includes/acc_journal.php');
require_once app_path('includes/acc_report_ref.php');
require_once app_path('includes/nav_helpers.php');

function journal_entries_enqueue_oracle_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $dashPath = app_path('assets/css/dashboard.css');
    $dashUrl = app_url('assets/css/dashboard.css');
    if (is_file($dashPath)) {
        $dashUrl .= '?v=' . (string) filemtime($dashPath);
    }

    $oraPath = app_path('assets/css/journal-entries-oracle.css');
    $oraUrl = app_url('assets/css/journal-entries-oracle.css');
    if (is_file($oraPath)) {
        $oraUrl .= '?v=' . (string) filemtime($oraPath);
    }

    echo '<link rel="stylesheet" href="' . esc($dashUrl) . '">' . "\n";
    echo '<link rel="stylesheet" href="' . esc($oraUrl) . '">' . "\n";
}

$listUrl = app_url('index.php?r=journal_entries');

/**
 * رابط الخروج من شاشة عرض/تعديل قيد — العودة للشاشة السابقة أو قائمة القيود.
 *
 * @return array{url: string, hint: string}
 */
function journal_entries_view_exit_info(string $listUrl): array
{
    $fromRequest = trim((string) ($_GET['return'] ?? ''));
    if ($fromRequest !== '' && nav_is_safe_back_url($fromRequest)) {
        return [
            'url' => $fromRequest,
            'hint' => 'رجوع للشاشة السابقة',
        ];
    }

    $back = nav_back_link('journal_entries');
    if ($back === null) {
        return [
            'url' => $listUrl,
            'hint' => 'العودة لقائمة القيود',
        ];
    }

    $url = trim((string) ($back['url'] ?? ''));
    if ($url === '' || !nav_is_safe_back_url($url)) {
        return [
            'url' => $listUrl,
            'hint' => 'العودة لقائمة القيود',
        ];
    }

    if (preg_match('/[?&]r=journal_entries\b/', $url)) {
        if (preg_match('/[?&]action=(?:view|edit|add)\b/', $url)) {
            return [
                'url' => $listUrl,
                'hint' => 'العودة لقائمة القيود',
            ];
        }

        return [
            'url' => $url,
            'hint' => 'العودة لقائمة القيود',
        ];
    }

    return [
        'url' => $url,
        'hint' => 'رجوع للشاشة السابقة',
    ];
}

/** @deprecated استخدم journal_entries_view_exit_info */
function journal_entries_list_return_url(string $listUrl): string
{
    return journal_entries_view_exit_info($listUrl)['url'];
}

/** خروج من قائمة القيود — إلى قائمة الأيقونات وليس إلى شاشة عرض قيد سابقة. */
function journal_entries_screen_exit_url(string $activeRoute): string
{
    $stored = trim((string) ($_SESSION['nav_return_url'] ?? ''));
    if ($stored !== '' && nav_is_safe_back_url($stored)) {
        return $stored;
    }

    $hub = nav_resolve_active_hub($activeRoute);
    $hubUrl = nav_hub_folder_url($hub);
    if ($hubUrl !== null) {
        return $hubUrl;
    }

    $cfg = require app_path('config/master_toolbar.php');
    $exitRoute = (string) ($cfg['exit_route'] ?? 'dashboard');

    return app_url('index.php?r=' . rawurlencode($exitRoute));
}
$pdo = db();

if (!acc_journal_ensure_schema($pdo)) {
    echo '<div class="card"><p class="alert alert-error">تعذر إنشاء جداول القيود. نفّذ <code>database/migrations/026_acc_journal_tables.sql</code> من Workbench إن لزم، ثم حدّث الصفحة.</p></div>';
    return;
}

$accounts = acc_journal_load_leaf_accounts($pdo);
require_once app_path('includes/account_picker.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save' || $act === 'save_post') {
            $id = (int) ($_POST['id'] ?? 0);
            $entryNo = trim((string) ($_POST['entry_no'] ?? ''));
            $entryDate = parse_date_to_iso(trim((string) ($_POST['entry_date'] ?? ''))) ?? '';
            $description = trim((string) ($_POST['description_ar'] ?? ''));
            $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);

            if ($entryDate === '') {
                throw new RuntimeException('تاريخ القيد غير صالح.');
            }
            if (!is_array($lines)) {
                throw new RuntimeException('أسطر القيد غير صالحة.');
            }

            $pdo->beginTransaction();
            $savedId = acc_journal_save($pdo, $id, $entryNo, $entryDate, $description, $lines, $act === 'save_post');
            $pdo->commit();

            flash_set('success', $act === 'save_post' ? 'تم حفظ وترحيل القيد.' : 'تم حفظ القيد كمسودة.');
            redirect(app_url('index.php?r=journal_entries&action=edit&id=' . $savedId));
        } elseif ($act === 'post') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $pdo->beginTransaction();
            acc_journal_post_by_id($pdo, $id);
            $pdo->commit();
            flash_set('success', 'تم ترحيل القيد.');
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $pdo->beginTransaction();
            acc_journal_delete_draft($pdo, $id);
            $pdo->commit();
            flash_set('success', 'تم حذف المسودة.');
        } else {
            throw new RuntimeException('إجراء غير معروف.');
        }
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'تعذر تنفيذ العملية.');
    }

    redirect($listUrl);
}

$flash = flash_get();
$action = (string) ($_GET['action'] ?? 'list');

if ($action === 'add' || $action === 'edit' || $action === 'view') {
    $header = [
        'id' => 0,
        'entry_no' => '',
        'entry_date' => date('Y-m-d'),
        'description_ar' => '',
        'status' => 'draft',
    ];
    $lines = [];
    $readOnly = false;

    if ($action === 'add') {
        $header['entry_no'] = acc_journal_next_entry_no($pdo, (string) $header['entry_date']);
    } else {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            flash_set('error', 'قيد غير موجود.');
            redirect($listUrl);
        }
        $loaded = acc_journal_load_entry($pdo, $id);
        if (!$loaded) {
            flash_set('error', 'قيد غير موجود.');
            redirect($listUrl);
        }
        $header = array_merge($header, $loaded['header']);
        $lines = $loaded['lines'];
        $readOnly = $action === 'view' || (string) ($header['status'] ?? '') !== 'draft';
    }

    $formTitle = $action === 'add' ? 'قيد محاسبي جديد' : ($readOnly ? 'عرض قيد' : 'تعديل قيد');
    $printSubtitle = '';
    if ($readOnly) {
        $printSubtitle = 'رقم القيد: ' . (string) ($header['entry_no'] ?? '')
            . ' — التاريخ: ' . format_date_dmY((string) ($header['entry_date'] ?? ''));
        $desc = trim((string) ($header['description_ar'] ?? ''));
        if ($desc !== '') {
            $printSubtitle .= ' — ' . $desc;
        }
    }
    $jsPath = app_path('assets/js/journal-entry.js');
    $jsUrl = app_url('assets/js/journal-entry.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
    $cssPath = app_path('assets/css/journal-entry.css');
    $cssUrl = app_url('assets/css/journal-entry.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
    $cssInvPath = app_path('assets/css/sales-invoice.css');
    $cssInvUrl = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
    $childExit = journal_entries_view_exit_info($listUrl);
    $childExitUrl = (string) ($childExit['url'] ?? $listUrl);
    $childExitHint = (string) ($childExit['hint'] ?? 'العودة لقائمة القيود');
    $jvOpenLink = $action === 'view' ? acc_journal_entry_open_link($header) : null;
    $checkUndo = $action === 'view' ? acc_journal_entry_check_undo($header) : null;
    $checkUndoApi = app_url('api/fin_check_action.php');
    account_picker_enqueue_assets();
    journal_entries_enqueue_oracle_assets();
    if ($readOnly) {
        require_once app_path('includes/document_header.php');
    }
    ?>
    <link rel="stylesheet" href="<?= esc($cssInvUrl) ?>">
    <link rel="stylesheet" href="<?= esc($cssUrl) ?>">
    <?php if ($readOnly): ?>
    <style><?= document_print_header_css() ?></style>
    <?php endif; ?>

    <div class="dashboard-ora journal-entries-ora" data-exit-guard-root data-exit-url="<?= esc($childExitUrl) ?>"<?= $readOnly ? ' data-journal-readonly="1"' : '' ?><?= $checkUndo ? ' data-check-undo-api="' . esc($checkUndoApi) . '" data-check-undo-id="' . (int) $checkUndo['check_id'] . '" data-check-undo-label="' . esc($checkUndo['label']) . '"' : '' ?>>
        <header class="dashboard-ora-screen-title no-print" role="banner">
            <h1 class="dashboard-ora-screen-title__text"><?= esc($formTitle) ?></h1>
            <?php if ($readOnly): ?>
                <span class="dashboard-ora-screen-title__meta"><?= esc(acc_journal_status_label((string) $header['status'])) ?></span>
            <?php endif; ?>
            <?php nav_render_screen_close('journal_entries', $childExitUrl, $childExitHint); ?>
        </header>

        <div class="dashboard-ora-workspace">
            <div class="dashboard-ora-toolbar no-print">
                <a class="dashboard-ora-btn" href="<?= esc($listUrl) ?>">رجوع للقائمة</a>
                <?php if ($jvOpenLink !== null): ?>
                    <a class="dashboard-ora-btn dashboard-ora-btn--primary" href="<?= esc($jvOpenLink['url']) ?>"><?= esc($jvOpenLink['label']) ?></a>
                <?php endif; ?>
                <?php if ($checkUndo !== null): ?>
                    <button type="button" class="dashboard-ora-btn dashboard-ora-btn--danger" id="journal-check-undo-btn"><?= esc($checkUndo['label']) ?></button>
                <?php endif; ?>
            </div>

            <?php if ($flash): ?>
                <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
            <?php endif; ?>

            <section class="dashboard-ora-panel journal-entry-card">
        <div class="journal-entry-print-area" id="journal-entry-print-area">
            <?php if ($readOnly): ?>
                <?= document_print_header_html('قيد محاسبي', $pdo, $printSubtitle) ?>
            <?php endif; ?>
        <form method="post" action="<?= esc($listUrl) ?>" id="journal-entry-form" class="journal-entry-form"<?= $readOnly ? ' data-readonly="1"' : '' ?>>
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save" id="journal-form-action">
            <input type="hidden" name="id" value="<?= (int) $header['id'] ?>">
            <input type="hidden" name="lines_json" id="journal-lines-json" value="">

                <h2 class="dashboard-ora-panel__title">بيانات القيد</h2>
                <div class="dashboard-ora-panel__body">
            <div class="form-row journal-entry-header">
                <label class="field">
                    <span class="field-label">رقم القيد</span>
                    <input class="input" name="entry_no" id="entry_no" value="<?= esc((string) $header['entry_no']) ?>"<?= $readOnly ? ' readonly' : '' ?>>
                </label>
                <label class="field">
                    <span class="field-label">التاريخ *</span>
                    <input class="input js-date-dmy" type="text" name="entry_date" id="entry_date"
                           value="<?= esc(format_date_dmY((string) $header['entry_date'])) ?>"
                           placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" required<?= $readOnly ? ' readonly' : '' ?>>
                </label>
                <label class="field field-grow">
                    <span class="field-label">البيان</span>
                    <input class="input" name="description_ar" value="<?= esc((string) ($header['description_ar'] ?? '')) ?>"<?= $readOnly ? ' readonly' : '' ?>>
                </label>
                <div class="field">
                    <span class="field-label">الحالة</span>
                    <span class="je-ora-status je-ora-status--<?= esc((string) $header['status']) ?>"><?= esc(acc_journal_status_label((string) $header['status'])) ?></span>
                </div>
            </div>
                </div>

            <h2 class="dashboard-ora-panel__title">أسطر القيد</h2>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
            <div class="journal-lines-wrap">
                <table class="journal-lines-table" id="journal-lines-table">
                    <thead>
                    <tr>
                        <th class="col-acc">الحساب *</th>
                        <th class="col-money">مدين</th>
                        <th class="col-money">دائن</th>
                        <th class="col-memo">ملاحظة</th>
                        <?php if (!$readOnly): ?>
                            <th class="col-act"></th>
                        <?php endif; ?>
                    </tr>
                    </thead>
                    <tbody id="journal-lines-body"></tbody>
                    <tfoot>
                    <tr class="journal-totals-row">
                        <td><strong>المجموع</strong></td>
                        <td class="col-money" id="journal-total-debit">0.00</td>
                        <td class="col-money" id="journal-total-credit">0.00</td>
                        <td colspan="<?= $readOnly ? 1 : 2 ?>">
                            <span id="journal-balance-hint" class="journal-balance-ok">متوازن</span>
                        </td>
                    </tr>
                    </tfoot>
                </table>
            </div>
            <?php if (!$readOnly): ?>
                <div class="journal-entry-actions">
                    <button type="button" class="dashboard-ora-btn" id="journal-add-line">+ سطر</button>
                    <div class="journal-entry-actions-end">
                        <button type="submit" class="dashboard-ora-btn" data-action="save">حفظ مسودة</button>
                        <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--primary" data-action="save_post">حفظ وترحيل</button>
                    </div>
                </div>
            <?php endif; ?>
            </div>
        </form>
        </div>
            </section>
        </div>
    </div>

    <?php account_picker_json_script($accounts, 'journal-accounts-json'); ?>
    <script type="application/json" id="journal-initial-lines-json"><?= json_encode($lines, JSON_UNESCAPED_UNICODE) ?: '[]' ?></script>
    <script src="<?= esc($jsUrl) ?>" defer></script>
    <?php
    return;
}

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$dateFromRaw = trim((string) ($_GET['date_from'] ?? ''));
$dateToRaw = trim((string) ($_GET['date_to'] ?? ''));
$sortColumn = acc_journal_list_normalize_sort(trim((string) ($_GET['sort'] ?? 'created_at')));
$sortDir = acc_journal_list_normalize_dir(trim((string) ($_GET['dir'] ?? 'desc')));
$searchClause = acc_journal_list_search_clause($q);
$dateClause = acc_journal_list_date_clause($dateFromRaw, $dateToRaw);
$dateFrom = (string) ($dateClause['from'] ?? '');
$dateTo = (string) ($dateClause['to'] ?? '');
$listFrom = acc_journal_list_from_sql();

$sql = 'SELECT e.id, e.entry_no, e.entry_date, e.created_at, e.description_ar, e.status,
               e.created_by, e.updated_by,
               u_creator.full_name_ar AS created_by_name,
               u_updater.full_name_ar AS updated_by_name
        ' . $listFrom . '
        WHERE 1=1' . $searchClause['where'] . $dateClause['where'];
$params = array_merge($searchClause['params'], $dateClause['params']);
if (in_array($statusFilter, ['draft', 'posted', 'cancelled'], true)) {
    $sql .= ' AND e.status = ?';
    $params[] = $statusFilter;
}
require_once app_path('includes/list_pagination.php');

$countSql = 'SELECT COUNT(DISTINCT e.id) ' . $listFrom . ' WHERE 1=1' . $searchClause['where'] . $dateClause['where'];
$countParams = array_merge($searchClause['params'], $dateClause['params']);
if (in_array($statusFilter, ['draft', 'posted', 'cancelled'], true)) {
    $countSql .= ' AND e.status = ?';
    $countParams[] = $statusFilter;
}
$stCount = $pdo->prepare($countSql);
$stCount->execute($countParams);
$listTotal = (int) $stCount->fetchColumn();
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerQuery = [];
if ($q !== '') {
    $listPagerQuery['q'] = $q;
}
if ($statusFilter !== '') {
    $listPagerQuery['status'] = $statusFilter;
}
if ($dateFrom !== '') {
    $listPagerQuery['date_from'] = format_date_dmY($dateFrom);
}
if ($dateTo !== '') {
    $listPagerQuery['date_to'] = format_date_dmY($dateTo);
}
$listPagerQuery['sort'] = $sortColumn;
$listPagerQuery['dir'] = $sortDir;
$listPagerUrl = list_pager_base_url('journal_entries', $listPagerQuery);

$sql .= ' GROUP BY e.id' . acc_journal_list_order_clause($sortColumn, $sortDir) . list_pager_sql_limit($pager);
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
journal_entries_enqueue_oracle_assets();
$screenExitUrl = journal_entries_screen_exit_url($activeRoute ?? 'journal_entries');
?>
<div class="dashboard-ora journal-entries-ora" data-exit-url="<?= esc($screenExitUrl) ?>">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">القيود المحاسبية</h1>
        <span class="dashboard-ora-screen-title__meta"><?= (int) $listTotal ?> قيد</span>
        <?php nav_render_screen_close('journal_entries', $screenExitUrl); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <div class="dashboard-ora-toolbar">
            <a class="dashboard-ora-btn dashboard-ora-btn--primary" href="<?= esc(app_url('index.php?r=journal_entries&action=add')) ?>">+ قيد جديد</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <section class="dashboard-ora-panel" aria-label="بحث وتصفية">
            <h2 class="dashboard-ora-panel__title">بحث وتصفية</h2>
            <div class="dashboard-ora-panel__body">
                <form method="get" action="<?= esc(app_url('index.php')) ?>" class="je-ora-filters">
                    <input type="hidden" name="r" value="journal_entries">
                    <input type="hidden" name="sort" value="<?= esc($sortColumn) ?>">
                    <input type="hidden" name="dir" value="<?= esc($sortDir) ?>">
                    <label class="field je-ora-search-field">
                        <span class="field-label">بحث</span>
                        <input class="input" type="search" name="q" value="<?= esc($q) ?>"
                               placeholder="رقم القيد، البيان، اسم الحساب، المبلغ…" autocomplete="off">
                    </label>
                    <label class="field">
                        <span class="field-label">من تاريخ</span>
                        <input class="input js-date-dmy" type="text" name="date_from"
                               value="<?= esc($dateFromRaw) ?>" placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off">
                    </label>
                    <label class="field">
                        <span class="field-label">إلى تاريخ</span>
                        <input class="input js-date-dmy" type="text" name="date_to"
                               value="<?= esc($dateToRaw) ?>" placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off">
                    </label>
                    <label class="field">
                        <span class="field-label">الحالة</span>
                        <select class="input" name="status">
                            <option value="">الكل</option>
                            <option value="draft"<?= $statusFilter === 'draft' ? ' selected' : '' ?>>مسودة</option>
                            <option value="posted"<?= $statusFilter === 'posted' ? ' selected' : '' ?>>مرحّل</option>
                            <option value="cancelled"<?= $statusFilter === 'cancelled' ? ' selected' : '' ?>>ملغى</option>
                        </select>
                    </label>
                    <div class="je-ora-filter-actions">
                        <button type="submit" class="dashboard-ora-btn">تصفية</button>
                        <?php if ($q !== '' || $statusFilter !== '' || $dateFromRaw !== '' || $dateToRaw !== ''): ?>
                            <a class="dashboard-ora-btn" href="<?= esc($listUrl) ?>">مسح</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <section class="dashboard-ora-panel" aria-label="سجل القيود">
            <h2 class="dashboard-ora-panel__title">سجل القيود</h2>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                <div class="dashboard-ora-table-wrap">
                    <table class="dashboard-ora-table je-ora-list-table">
                        <thead>
                        <tr>
                            <th><?= acc_journal_list_sort_th_html('رقم القيد', 'entry_no', 'journal_entries', $listPagerQuery, $sortColumn, $sortDir) ?></th>
                            <th><?= acc_journal_list_sort_th_html('تاريخ القيد', 'entry_date', 'journal_entries', $listPagerQuery, $sortColumn, $sortDir) ?></th>
                            <th><?= acc_journal_list_sort_th_html('تاريخ الإنشاء', 'created_at', 'journal_entries', $listPagerQuery, $sortColumn, $sortDir) ?></th>
                            <th><?= acc_journal_list_sort_th_html('البيان', 'description_ar', 'journal_entries', $listPagerQuery, $sortColumn, $sortDir) ?></th>
                            <th><?= acc_journal_list_sort_th_html('الحالة', 'status', 'journal_entries', $listPagerQuery, $sortColumn, $sortDir) ?></th>
                            <th>إجراءات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="6" class="dashboard-ora-empty">لا توجد قيود بعد.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $e):
                            $st = (string) ($e['status'] ?? 'draft');
                            $eid = (int) $e['id'];
                            $createdRaw = trim((string) ($e['created_at'] ?? ''));
                            $createdDisplay = '—';
                            if ($createdRaw !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $createdRaw, $createdMatch)) {
                                $createdDisplay = format_date_dmY($createdMatch[1]);
                            }
                            ?>
                            <tr>
                                <td class="je-ora-entry-cell">
                                    <code class="je-ora-entry-no"><?= esc((string) $e['entry_no']) ?></code>
                                    <?php
                                    $actorName = acc_journal_entry_actor_name($e);
                                    if ($actorName !== '—'):
                                        $actorKind = acc_journal_entry_actor_kind($e);
                                    ?>
                                        <span class="je-ora-entry-user" title="<?= esc($actorKind) ?>">
                                            <?= esc($actorName) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc(format_date_dmY((string) $e['entry_date'])) ?></td>
                                <td><?= esc($createdDisplay) ?></td>
                                <td><?= esc((string) ($e['description_ar'] ?? '')) ?></td>
                                <td><span class="je-ora-status je-ora-status--<?= esc($st) ?>"><?= esc(acc_journal_status_label($st)) ?></span></td>
                                <td>
                                    <div class="row-actions">
                                        <?php if ($st === 'draft'): ?>
                                            <a class="dashboard-ora-btn" href="<?= esc(app_url('index.php?r=journal_entries&action=edit&id=' . $eid)) ?>">تعديل</a>
                                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="حذف المسودة؟">
                                                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                                <input type="hidden" name="_action" value="delete">
                                                <input type="hidden" name="id" value="<?= $eid ?>">
                                                <button type="submit" class="dashboard-ora-btn dashboard-ora-btn--danger">حذف</button>
                                            </form>
                                        <?php else: ?>
                                            <a class="dashboard-ora-btn" href="<?= esc(app_url('index.php?r=journal_entries&action=view&id=' . $eid)) ?>">عرض</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php list_pager_render($pager, $listPagerUrl); ?>
            </div>
        </section>
    </div>
</div>
