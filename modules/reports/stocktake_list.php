<?php
declare(strict_types=1);

require_once app_path('includes/inv_stocktake_schema.php');

$pdo = db();
inv_stocktake_ensure_schema($pdo);

$q = trim((string) ($_GET['q'] ?? ''));
$listUrl = app_url('index.php?r=report_stocktake_list');
$openBaseUrl = app_url('index.php?r=inventory_stocktake');

$sql = 'SELECT d.id, d.take_no, d.take_date, d.status, w.name_ar AS warehouse_name
        FROM inv_stocktake_doc d
        LEFT JOIN inv_warehouse w ON w.id = d.warehouse_id';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE d.take_no LIKE ? OR IFNULL(w.name_ar, \'\') LIKE ?';
    $like = '%' . $q . '%';
    $params = [$like, $like];
}
$sql .= ' ORDER BY d.id DESC LIMIT 300';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="sales-inv-list-page">
    <div class="card" style="margin-bottom:0.75rem;padding:0.75rem 1rem;">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap;margin:0;">
            <input type="hidden" name="r" value="report_stocktake_list">
            <label class="field" style="flex:1;min-width:220px;margin:0;">
                <span class="field-label">بحث</span>
                <input class="input" type="search" name="q" value="<?= esc($q) ?>" placeholder="رقم السند أو المستودع" autocomplete="off">
            </label>
            <button type="submit" class="btn btn-primary btn-sm">بحث</button>
            <?php if ($q !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl) ?>">مسح</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th style="width:3rem;">#</th>
                    <th>رقم سند الجرد</th>
                    <th>التاريخ</th>
                    <th>المستودع</th>
                    <th>الحالة</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="5" class="muted" style="text-align:center;padding:1.25rem;">لا توجد سندات جرد.</td>
                    </tr>
                <?php endif; ?>
                <?php $seq = 0; foreach ($rows as $r): $seq++; ?>
                    <tr class="is-clickable"
                        tabindex="0"
                        role="link"
                        data-href="<?= esc($openBaseUrl . '&id=' . (int) ($r['id'] ?? 0)) ?>"
                        title="فتح سند الجرد">
                        <td><?= $seq ?></td>
                        <td><code><?= esc((string) ($r['take_no'] ?? '')) ?></code></td>
                        <td><?= esc(format_date_dmY((string) ($r['take_date'] ?? ''))) ?></td>
                        <td><?= esc((string) ($r['warehouse_name'] ?? '—')) ?></td>
                        <td>
                            <?php if ((string) ($r['status'] ?? '') === 'posted'): ?>
                                <span class="badge badge-ok">مرحّل</span>
                            <?php else: ?>
                                <span class="badge badge-warn">مسودة</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', function (e) {
  var row = e.target.closest('tr.is-clickable[data-href]');
  if (!row) return;
  window.location.href = row.getAttribute('data-href') || '';
});
document.addEventListener('keydown', function (e) {
  var row = e.target && e.target.closest ? e.target.closest('tr.is-clickable[data-href]') : null;
  if (!row) return;
  if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault();
    window.location.href = row.getAttribute('data-href') || '';
  }
});
</script>
