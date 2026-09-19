<?php
declare(strict_types=1);

require_permission('inv_movement_types_settings');

require_once app_path('includes/inv_movement_type_schema.php');
require_once app_path('includes/inv_wh_move_gl.php');

$pdo = db();
inv_movement_type_ensure_schema($pdo);

$listUrl = app_url('index.php?r=inv_movement_types_settings');
$formId = 'inv-movement-types-form';
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $msgType = 'error';
    } elseif (($_POST['_action'] ?? '') !== 'save_all') {
        $msg = 'إجراء غير معروف.';
        $msgType = 'error';
    } else {
        $rows = $_POST['types'] ?? [];
        if (!is_array($rows) || $rows === []) {
            $msg = 'لا توجد بيانات للحفظ.';
            $msgType = 'error';
        } else {
            $known = [];
            foreach (inv_movement_types_all($pdo) as $t) {
                $known[(int) $t['id']] = (string) $t['code'];
            }
            $updates = [];
            foreach ($rows as $idStr => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) $idStr;
                if ($id < 1 || !isset($known[$id])) {
                    continue;
                }
                $postAuto = isset($row['post_auto']) ? 1 : 0;
                $postManual = isset($row['post_manual']) ? 1 : 0;
                if ($postAuto === 1 && $postManual === 1) {
                    $postManual = 0;
                }
                $isActive = isset($row['is_active']) ? 1 : 0;
                $updates[$id] = [
                    'post_auto' => $postAuto,
                    'post_manual' => $postManual,
                    'is_active' => $isActive,
                    'affects_gl' => isset($row['affects_gl']) ? 1 : 0,
                ];
            }
            if ($updates === []) {
                $msg = 'لا توجد بيانات للحفظ.';
                $msgType = 'error';
            } else {
                try {
                    $pdo->beginTransaction();
                    inv_movement_type_ensure_affects_gl_column($pdo);
                    $st = $pdo->prepare(
                        'UPDATE inv_movement_type
                         SET post_auto = ?, post_manual = ?, is_active = ?, affects_gl = ?
                         WHERE id = ?'
                    );
                    foreach ($updates as $id => $u) {
                        $st->execute([
                            $u['post_auto'],
                            $u['post_manual'],
                            $u['is_active'],
                            $u['affects_gl'],
                            $id,
                        ]);
                    }
                    $pdo->commit();
                    $msg = 'تم حفظ إعدادات أنواع الحركات.';
                    $msgType = 'success';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $msg = 'تعذر الحفظ.';
                    $msgType = 'error';
                }
            }
        }
    }
}

$types = inv_movement_types_all($pdo);
$jsUrl = app_url('assets/js/inv-movement-types-settings.js');
?>
<div class="card" style="max-width:920px;">
    <p class="muted" style="margin:0 0 1rem;">
        اضبط هنا أنواع حركات المستودع قبل إنشاء مستندات الحركة (تعديل، نقل، إتلاف).
        يمكنك إيقاف جميع الأنواع مؤقتاً؛ عندها لن تظهر في شاشة حركات المستودع حتى تُفعّل نوعاً واحداً على الأقل.
    </p>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= esc($msg) ?></div>
    <?php endif; ?>

    <?php if ($types !== []): ?>
        <form id="<?= esc($formId) ?>" method="post" action="<?= esc($listUrl) ?>" class="sr-only" aria-hidden="true">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save_all">
            <button type="submit" id="inv-movement-types-submit">حفظ</button>
        </form>
        <p class="muted" style="margin:0 0 0.75rem;font-size:0.9rem;">
            عدّل الخيارات ثم اضغط <strong>حفظ</strong> في الشريط العلوي.
        </p>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data-table inv-movement-types-table">
            <thead>
            <tr>
                <th>نوع الحركة</th>
                <th style="width:7rem;text-align:center;">ترحيل تلقائي</th>
                <th style="width:7rem;text-align:center;">ترحيل يدوي</th>
                <th style="width:6rem;text-align:center;">مفعّل</th>
                <th style="width:7rem;text-align:center;">تأثير محاسبي</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($types as $t):
                $tid = (int) $t['id'];
                $postAuto = (int) ($t['post_auto'] ?? 0);
                $postManual = (int) ($t['post_manual'] ?? 0);
                if ($postAuto === 1 && $postManual === 1) {
                    $postManual = 0;
                }
                ?>
                <tr>
                    <td>
                        <strong><?= esc((string) $t['name_ar']) ?></strong>
                    </td>
                    <td style="text-align:center;">
                        <label class="inv-movement-types-chk" title="ترحيل تلقائي عند الحفظ">
                            <input type="checkbox" form="<?= esc($formId) ?>"
                                   class="inv-movement-types-post-auto"
                                   name="types[<?= $tid ?>][post_auto]" value="1"
                                <?= $postAuto === 1 ? 'checked' : '' ?>>
                            <span class="sr-only">ترحيل تلقائي</span>
                        </label>
                    </td>
                    <td style="text-align:center;">
                        <label class="inv-movement-types-chk" title="ترحيل يدوي من شاشة الترحيل">
                            <input type="checkbox" form="<?= esc($formId) ?>"
                                   class="inv-movement-types-post-manual"
                                   name="types[<?= $tid ?>][post_manual]" value="1"
                                <?= $postManual === 1 ? 'checked' : '' ?>>
                            <span class="sr-only">ترحيل يدوي</span>
                        </label>
                    </td>
                    <td style="text-align:center;">
                        <label class="inv-movement-types-chk" title="تفعيل النوع في النظام">
                            <input type="checkbox" form="<?= esc($formId) ?>"
                                   name="types[<?= $tid ?>][is_active]" value="1"
                                <?= (int) ($t['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <span class="sr-only">مفعّل</span>
                        </label>
                    </td>
                    <td style="text-align:center;">
                        <?php
                        $affectsGl = (int) ($t['affects_gl'] ?? (inv_wh_move_type_affects_gl_by_code((string) ($t['code'] ?? '')) ? 1 : 0));
                        ?>
                        <label class="inv-movement-types-chk" title="إنشاء قيد محاسبي عند الترحيل (النقل عادةً بدون قيد)">
                            <input type="checkbox" form="<?= esc($formId) ?>"
                                   name="types[<?= $tid ?>][affects_gl]" value="1"
                                <?= $affectsGl === 1 ? 'checked' : '' ?>>
                            <span class="sr-only">تأثير محاسبي</span>
                        </label>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($types === []): ?>
                <tr>
                    <td colspan="5" class="muted" style="text-align:center;">لا توجد أنواع. نفّذ ترحيل قاعدة البيانات.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="<?= esc($jsUrl) ?>" defer></script>
