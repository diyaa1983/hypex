<?php
declare(strict_types=1);

/**
 * اعتماد خروج يدوي من زيارة المندوب (بعد دخول GPS ونسيان الخروج من الموقع).
 */
require_once app_path('includes/sal_rep_visit.php');

$pdo = db();
sal_rep_visit_ensure_schema($pdo);

$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $err = 'انتهت صلاحية الجلسة.';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $action = strtolower(trim((string) ($_POST['action'] ?? '')));
        $note = trim((string) ($_POST['note'] ?? ''));
        $uid = (int) (current_user()['id'] ?? 0);
        if ($id > 0 && in_array($action, ['approve', 'reject'], true)) {
            $result = sal_rep_visit_decide_checkout_request($pdo, $id, $action === 'approve', $uid, $note !== '' ? $note : null);
            if (!empty($result['ok'])) {
                $msg = (string) $result['message'];
            } else {
                $err = (string) ($result['message'] ?? 'تعذّر التنفيذ.');
            }
        } else {
            $err = 'طلب غير صالح.';
        }
    }
}

$status = (string) ($_GET['status'] ?? 'pending');
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
    $status = 'pending';
}
$rows = sal_rep_visit_pending_checkout_list($pdo, $status, 200);
$focusId = (int) ($_GET['id'] ?? 0);
$listUrl = app_url('index.php?r=sales_rep_visit_checkout_approve');
?>
<div class="card" style="max-width:1100px;margin:1rem auto">
  <h1 style="margin:0 0 .5rem;font-size:1.25rem">اعتماد خروج يدوي من الزيارة</h1>
  <p class="muted" style="margin:0 0 1rem">
    عندما يدخل المندوب بـ GPS وينسى الخروج من موقع العميل، يطلب خروجاً يدوياً ويظهر هنا للموافقة.
  </p>
  <?php if ($msg !== ''): ?><p class="alert alert-success"><?= esc($msg) ?></p><?php endif; ?>
  <?php if ($err !== ''): ?><p class="alert alert-error"><?= esc($err) ?></p><?php endif; ?>

  <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
    <?php foreach (['pending' => 'معلّق', 'approved' => 'موافق عليه', 'rejected' => 'مرفوض', 'all' => 'الكل'] as $k => $lab): ?>
      <a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-secondary' ?>"
         href="<?= esc($listUrl . '&status=' . rawurlencode($k)) ?>"><?= esc($lab) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($rows === []): ?>
    <p class="muted">لا توجد طلبات.</p>
  <?php else: ?>
  <div style="overflow:auto">
    <table class="table" style="width:100%;border-collapse:collapse">
      <thead>
        <tr>
          <th>#</th>
          <th>المندوب</th>
          <th>العميل</th>
          <th>السبب</th>
          <th>المسافة</th>
          <th>الطلب</th>
          <th>الحالة</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $id = (int) ($r['id'] ?? 0);
          $isFocus = $focusId > 0 && $focusId === $id;
          $st = (string) ($r['status'] ?? '');
        ?>
        <tr<?= $isFocus ? ' style="background:#fff7ed"' : '' ?>>
          <td><?= $id ?></td>
          <td><?= esc((string) ($r['sales_rep_name'] ?? '')) ?></td>
          <td>
            <?= esc((string) ($r['customer_name'] ?? '')) ?>
            <div class="muted" dir="ltr"><?= esc((string) ($r['customer_code'] ?? '')) ?></div>
          </td>
          <td><?= esc((string) ($r['reason'] ?? '')) ?></td>
          <td dir="ltr">
            <?= $r['request_distance_m'] !== null && $r['request_distance_m'] !== ''
              ? esc(number_format((float) $r['request_distance_m'], 0)) . ' م'
              : '—' ?>
          </td>
          <td dir="ltr"><?= esc((string) ($r['created_at'] ?? '')) ?></td>
          <td><?= esc($st) ?></td>
          <td>
            <?php if ($st === 'pending'): ?>
            <form method="post" action="<?= esc($listUrl) ?>" style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center">
              <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="text" name="note" class="input input-compact" placeholder="ملاحظة" style="min-width:8rem">
              <button class="btn btn-sm btn-primary" type="submit" name="action" value="approve">موافقة</button>
              <button class="btn btn-sm btn-secondary" type="submit" name="action" value="reject">رفض</button>
            </form>
            <?php else: ?>
              <span class="muted"><?= esc((string) ($r['decided_by_name'] ?? '')) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
