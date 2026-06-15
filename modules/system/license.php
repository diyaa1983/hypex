<?php
declare(strict_types=1);

require_once app_path('includes/license.php');

$pageUrl = app_url('index.php?r=system_license');
$flash = flash_get();
$pdo = db();
$generatedKey = '';
$genError = '';
$genInput = [
    'fingerprint_hash' => '',
    'customer' => '',
    'license_no' => '',
    'max_users' => '',
    'expires_on' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($pageUrl);
    }

    $action = (string) ($_POST['_action'] ?? 'activate');
    if ($action === 'generate_key') {
        $genInput['fingerprint_hash'] = strtolower(trim((string) ($_POST['fingerprint_hash'] ?? '')));
        $genInput['customer'] = trim((string) ($_POST['customer'] ?? ''));
        $genInput['license_no'] = trim((string) ($_POST['license_no'] ?? ''));
        $genInput['max_users'] = trim((string) ($_POST['max_users'] ?? ''));
        $genInput['expires_on'] = trim((string) ($_POST['expires_on'] ?? ''));

        if (!user_is_system_admin()) {
            $genError = 'توليد أرقام التفعيل متاح فقط لمسؤول النظام (Admin).';
        } elseif (!preg_match('/^[a-f0-9]{64}$/', $genInput['fingerprint_hash'])) {
            $genError = 'Fingerprint Hash غير صالح. يجب أن يكون 64 حرفاً (hex).';
        } elseif (license_secret() === '' || strlen(license_secret()) < 16) {
            $genError = 'APP_LICENSE_SECRET غير مضبوط أو قصير في config/app.local.php.';
        } else {
            $maxUsers = null;
            if ($genInput['max_users'] !== '') {
                if (!preg_match('/^\d+$/', $genInput['max_users']) || (int) $genInput['max_users'] <= 0) {
                    $genError = 'حد المستخدمين يجب أن يكون رقماً صحيحاً أكبر من صفر.';
                } else {
                    $maxUsers = (int) $genInput['max_users'];
                }
            }
            if ($genError === '') {
                try {
                    $generatedKey = license_generate_key(
                        $genInput['fingerprint_hash'],
                        license_secret(),
                        $genInput['expires_on'] !== '' ? $genInput['expires_on'] : null,
                        $genInput['customer'],
                        $genInput['license_no'],
                        $maxUsers
                    );
                } catch (Throwable $e) {
                    $genError = 'تعذر توليد رقم التفعيل: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'deactivate') {
        if (!user_is_system_admin()) {
            flash_set('error', 'إلغاء تفعيل النسخة متاح فقط لمسؤول النظام.');
        } else {
            try {
                license_deactivate($pdo);
                flash_set('success', 'تم إلغاء تفعيل النسخة الحالية بنجاح.');
            } catch (Throwable $e) {
                flash_set('error', 'تعذر إلغاء التفعيل: ' . $e->getMessage());
            }
        }
        redirect($pageUrl);
    } elseif ($action === 'delete_log') {
        if (!user_is_system_admin()) {
            flash_set('error', 'حذف سجل التفعيل متاح فقط لمسؤول النظام.');
        } else {
            try {
                $logId = (int) ($_POST['log_id'] ?? 0);
                license_delete_activation_log($pdo, $logId);
                // حسب طلب العمل: حذف السجل من شاشة التراخيص يعني إلغاء التفعيل الحالي وطلب رخصة جديدة.
                license_deactivate($pdo);
                flash_set('success', 'تم حذف السجل وإلغاء التفعيل الحالي. يلزم إدخال رخصة جديدة.');
            } catch (Throwable $e) {
                flash_set('error', 'تعذر حذف السجل: ' . $e->getMessage());
            }
        }
        redirect($pageUrl);
    } elseif ($action === 'revoke_user') {
        if (!user_is_system_admin()) {
            flash_set('error', 'إلغاء ترخيص المستخدم متاح فقط لمسؤول النظام.');
        } else {
            try {
                $targetUserId = (int) ($_POST['user_id'] ?? 0);
                if ($targetUserId <= 0) {
                    throw new RuntimeException('معرّف المستخدم غير صالح.');
                }
                if (user_is_system_admin($targetUserId)) {
                    throw new RuntimeException('لا يمكن إلغاء ترخيص مستخدم Admin من هذه الشاشة.');
                }
                $statusNow = license_status($pdo);
                $licenseNoNow = (string) ($statusNow['license_no'] ?? '');
                license_revoke_user_binding($pdo, $targetUserId, $licenseNoNow);
                flash_set('success', 'تم إلغاء ترخيص المستخدم. لن يستطيع الدخول حتى إعادة التفعيل.');
            } catch (Throwable $e) {
                flash_set('error', 'تعذر إلغاء ترخيص المستخدم: ' . $e->getMessage());
            }
        }
        redirect($pageUrl);
    } else {
        try {
            $result = license_activate($pdo, (string) ($_POST['license_key'] ?? ''));
            flash_set($result['ok'] ? 'success' : 'error', $result['message']);
        } catch (Throwable $e) {
            flash_set('error', 'تعذر حفظ الترخيص: ' . $e->getMessage());
        }
        redirect($pageUrl);
    }
}

$status = license_status($pdo);
$activatePublicUrl = app_url('activate_license.php');
$logs = license_recent_activation_logs($pdo, 20);
$activeLinkedUsers = [];
if ((string) ($status['license_no'] ?? '') !== '') {
    $activeLinkedUsers = license_active_linked_users($pdo, (string) $status['license_no'], 500);
}
if ($genInput['fingerprint_hash'] === '') {
    $genInput['fingerprint_hash'] = (string) ($status['fingerprint_hash'] ?? '');
}
?>
<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
        <?= esc($flash['message']) ?>
    </div>
<?php endif; ?>

<section class="dashboard-ora-panel">
    <h2 class="dashboard-ora-panel__title">بصمة الخادم</h2>
    <div class="dashboard-ora-panel__body">
        <label class="field">
            <span class="field-label">Fingerprint Hash (بصمة الخادم)</span>
            <input class="input" type="text" readonly dir="ltr"
                   value="<?= esc((string) ($status['fingerprint_hash'] ?? '')) ?>">
        </label>
        <p class="muted" style="margin:0;">
            أرسل هذه البصمة لمزوّد النظام لإصدار رقم تفعيل مطابق لهذا الخادم.
        </p>
    </div>
</section>

<section class="dashboard-ora-panel">
    <h2 class="dashboard-ora-panel__title">حالة الترخيص</h2>
    <div class="dashboard-ora-panel__body">
        <div class="alert alert-<?= !empty($status['valid']) ? 'success' : 'error' ?>" style="margin-bottom:1rem;">
            <?= esc((string) ($status['message'] ?? '')) ?>
        </div>
        <div class="muted" style="line-height:1.9;">
            <div><strong>الإلزام:</strong> <?= !empty($status['enforced']) ? 'مفعّل' : 'غير مفعّل' ?></div>
            <div><strong>رقم التفعيل المحفوظ:</strong> <code dir="ltr"><?= esc((string) ($status['license_key_masked'] ?? '—')) ?></code></div>
            <?php if ((string) ($status['license_no'] ?? '') !== ''): ?>
                <div><strong>رقم النسخة:</strong> <code dir="ltr"><?= esc((string) $status['license_no']) ?></code></div>
            <?php endif; ?>
            <?php if ((string) ($status['issued_to'] ?? '') !== ''): ?>
                <div><strong>مرخّص إلى:</strong> <?= esc((string) $status['issued_to']) ?></div>
            <?php endif; ?>
            <?php if ((string) ($status['activated_by_username'] ?? '') !== ''): ?>
                <div><strong>فُعّل بواسطة:</strong>
                    <?= esc((string) ($status['activated_by_name'] ?: $status['activated_by_username'])) ?>
                    (<code dir="ltr"><?= esc((string) $status['activated_by_username']) ?></code>)
                </div>
            <?php endif; ?>
            <div><strong>عدد المستخدمين النشطين:</strong> <?= (int) ($status['active_users'] ?? 0) ?></div>
            <?php if (!empty($status['max_users'])): ?>
                <div><strong>حد المستخدمين المرخّص:</strong> <?= (int) $status['max_users'] ?></div>
            <?php else: ?>
                <div><strong>حد المستخدمين المرخّص:</strong> غير محدد</div>
            <?php endif; ?>
            <?php if (!empty($status['expires_on'])): ?>
                <div><strong>تاريخ الانتهاء:</strong> <span dir="ltr"><?= esc((string) $status['expires_on']) ?></span></div>
            <?php else: ?>
                <div><strong>تاريخ الانتهاء:</strong> غير محدد</div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="dashboard-ora-panel">
    <h2 class="dashboard-ora-panel__title">تحديث رقم التفعيل</h2>
    <div class="dashboard-ora-panel__body">
        <?php if (!empty($status['enforced'])): ?>
            <form method="post" class="form-grid" action="<?= esc($pageUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="activate">
                <label class="field">
                    <span class="field-label">رقم التفعيل الجديد</span>
                    <textarea class="input" name="license_key" rows="4" dir="ltr" required
                              placeholder="LIC1.xxxxx.yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy"></textarea>
                </label>
                <button type="submit" class="btn btn-primary">حفظ رقم التفعيل</button>
            </form>
        <?php else: ?>
            <div class="alert alert-error">
                تفعيل الترخيص غير مفعل حالياً. فعّل <code>APP_LICENSE_ENFORCE</code> من
                <code>config/app.local.php</code> أولاً.
            </div>
        <?php endif; ?>
        <p class="muted" style="margin:1rem 0 0;">
            يمكن فتح شاشة التفعيل العامة من الرابط:
            <a href="<?= esc($activatePublicUrl) ?>" target="_blank" rel="noopener"><?= esc($activatePublicUrl) ?></a>
        </p>
        <?php if ((string) ($status['license_key_masked'] ?? '—') !== '—' && user_is_system_admin()): ?>
            <form method="post" action="<?= esc($pageUrl) ?>" style="margin-top:1rem;"
                  onsubmit="return confirm('هل أنت متأكد من إلغاء تفعيل النسخة الحالية؟');">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="deactivate">
                <button type="submit" class="btn btn-danger">إلغاء تفعيل النسخة</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="dashboard-ora-panel">
    <h2 class="dashboard-ora-panel__title">المستخدمون المفعلون على النسخة الحالية</h2>
    <div class="dashboard-ora-panel__body">
        <?php if ((string) ($status['license_no'] ?? '') === ''): ?>
            <p class="muted">لا يوجد رقم نسخة مرتبط بالترخيص الحالي.</p>
        <?php elseif ($activeLinkedUsers === []): ?>
            <p class="muted">لا يوجد مستخدمون نشطون مربوطون بهذه النسخة حالياً.</p>
        <?php else: ?>
            <p class="muted" style="margin:0 0 .6rem;">
                العدد الحالي: <strong><?= count($activeLinkedUsers) ?></strong>
            </p>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم المستخدم</th>
                        <th>الاسم</th>
                        <th>البريد</th>
                        <th style="width:8.5rem;">إجراء</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $uSeq = 0; ?>
                    <?php foreach ($activeLinkedUsers as $usr): ?>
                        <?php $uSeq++; ?>
                        <tr>
                            <td><?= $uSeq ?></td>
                            <td><code dir="ltr"><?= esc((string) ($usr['username'] ?? '')) ?></code></td>
                            <td><?= esc((string) ($usr['full_name_ar'] ?? '')) ?></td>
                            <td><?= esc((string) ($usr['email'] ?? '—')) ?></td>
                            <td>
                                <?php if (user_is_system_admin()): ?>
                                    <form method="post" action="<?= esc($pageUrl) ?>"
                                          onsubmit="return confirm('هل تريد إلغاء ترخيص هذا المستخدم؟');">
                                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="_action" value="revoke_user">
                                        <input type="hidden" name="user_id" value="<?= (int) ($usr['id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">إلغاء الترخيص</button>
                                    </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted" style="margin:.65rem 0 0;">
                يتم تحديث هذه القائمة تلقائياً؛ عند تعطيل أي مستخدم يُزال منها مباشرة.
            </p>
        <?php endif; ?>
    </div>
</section>

<section class="dashboard-ora-panel">
    <h2 class="dashboard-ora-panel__title">توليد رقم تفعيل (Admin)</h2>
    <div class="dashboard-ora-panel__body">
        <?php if (!user_is_system_admin()): ?>
            <div class="alert alert-error">هذه الأداة متاحة لمسؤول النظام فقط.</div>
        <?php else: ?>
            <?php if ($genError !== ''): ?>
                <div class="alert alert-error"><?= esc($genError) ?></div>
            <?php endif; ?>
            <form method="post" class="form-grid" action="<?= esc($pageUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="generate_key">
                <label class="field">
                    <span class="field-label">Fingerprint Hash (64)</span>
                    <input class="input" type="text" name="fingerprint_hash" dir="ltr" required
                           value="<?= esc($genInput['fingerprint_hash']) ?>">
                </label>
                <label class="field">
                    <span class="field-label">اسم العميل (اختياري)</span>
                    <input class="input" type="text" name="customer" value="<?= esc($genInput['customer']) ?>">
                </label>
                <label class="field">
                    <span class="field-label">رقم النسخة (اختياري)</span>
                    <input class="input" type="text" name="license_no" dir="ltr" value="<?= esc($genInput['license_no']) ?>">
                </label>
                <label class="field">
                    <span class="field-label">حد المستخدمين (اختياري)</span>
                    <input class="input" type="number" min="1" step="1" name="max_users" dir="ltr"
                           value="<?= esc($genInput['max_users']) ?>">
                </label>
                <label class="field">
                    <span class="field-label">تاريخ الانتهاء (اختياري)</span>
                    <input class="input" type="date" name="expires_on" dir="ltr" value="<?= esc($genInput['expires_on']) ?>">
                </label>
                <button type="submit" class="btn btn-primary">توليد رقم تفعيل</button>
            </form>
            <?php if ($generatedKey !== ''): ?>
                <label class="field" style="margin-top:1rem;">
                    <span class="field-label">رقم التفعيل الناتج</span>
                    <textarea class="input" rows="4" readonly dir="ltr"><?= esc($generatedKey) ?></textarea>
                </label>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<section class="dashboard-ora-panel">
    <h2 class="dashboard-ora-panel__title">سجل التفعيلات</h2>
    <div class="dashboard-ora-panel__body">
        <?php if ($logs === []): ?>
            <p class="muted">لا يوجد تفعيلات مسجلة بعد.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>وقت التفعيل</th>
                        <th>رقم النسخة</th>
                        <th>المستخدم</th>
                        <th>المستخدمون</th>
                        <th>Fingerprint</th>
                        <th style="width:7rem;">إجراء</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td dir="ltr"><?= esc((string) ($log['activated_at'] ?? '')) ?></td>
                            <td><code dir="ltr"><?= esc((string) ($log['license_no'] ?? '—')) ?></code></td>
                            <td>
                                <?= esc((string) (($log['activated_by_name'] ?? '') ?: ($log['activated_by_username'] ?? '—'))) ?>
                                <?php if ((string) ($log['activated_by_username'] ?? '') !== ''): ?>
                                    <div class="muted"><code dir="ltr"><?= esc((string) $log['activated_by_username']) ?></code></div>
                                <?php endif; ?>
                            </td>
                            <td dir="ltr">
                                <?= (int) ($log['active_users'] ?? 0) ?>
                                <?php if (!empty($log['max_users'])): ?>
                                    / <?= (int) $log['max_users'] ?>
                                <?php endif; ?>
                            </td>
                            <td><code dir="ltr"><?= esc(license_fingerprint_display((string) ($log['fingerprint_hash'] ?? ''))) ?></code></td>
                            <td>
                                <?php if (user_is_system_admin()): ?>
                                    <form method="post" action="<?= esc($pageUrl) ?>"
                                          onsubmit="return confirm('هل تريد حذف هذا السجل من جدول التفعيلات؟');">
                                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="_action" value="delete_log">
                                        <input type="hidden" name="log_id" value="<?= (int) ($log['id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                                    </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
