<?php
declare(strict_types=1);

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_customer_sync.php');

$pdo = db();
oracle_customer_schema_ensure($pdo);

$msg = '';
$err = '';
$connInfo = null;
$candidateTables = [];
$allAppTables = [];
$describeCols = [];
$previewRows = [];
$syncResult = null;
$tableFilter = trim((string) ($_POST['table_filter'] ?? $_GET['table_filter'] ?? ''));

$owner = strtoupper(trim((string) ($_POST['owner'] ?? $_GET['owner'] ?? '')));
$table = strtoupper(trim((string) ($_POST['table'] ?? $_GET['table'] ?? '')));
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
// اختبار سريع عبر الرابط أيضاً (يظهر في شريط العنوان)
if ($action === '' && isset($_GET['do'])) {
    $action = (string) $_GET['do'];
}

$map = [
    'oracle_key' => strtoupper(trim((string) ($_POST['map_oracle_key'] ?? ''))),
    'code' => strtoupper(trim((string) ($_POST['map_code'] ?? ''))),
    'name_ar' => strtoupper(trim((string) ($_POST['map_name'] ?? ''))),
    'phone' => strtoupper(trim((string) ($_POST['map_phone'] ?? ''))),
    'email' => strtoupper(trim((string) ($_POST['map_email'] ?? ''))),
    'tax_number' => strtoupper(trim((string) ($_POST['map_tax'] ?? ''))),
    'address_ar' => strtoupper(trim((string) ($_POST['map_address'] ?? ''))),
    'is_active' => strtoupper(trim((string) ($_POST['map_active'] ?? ''))),
];

// تحميل تعيين محفوظ مسبقاً في الحقول
$savedMap = oracle_customers_saved_mapping();
if ($savedMap !== null) {
    if ($owner === '') {
        $owner = $savedMap['owner'];
    }
    if ($table === '') {
        $table = $savedMap['table'];
    }
    foreach ($map as $k => $v) {
        if ($v === '' && !empty($savedMap['columns'][$k])) {
            $map[$k] = $savedMap['columns'][$k];
        }
    }
}

if ($action === 'save_config') {
    try {
        $path = oracle_write_local_config([
            'enabled' => isset($_POST['cfg_enabled']),
            'host' => (string) ($_POST['cfg_host'] ?? ''),
            'port' => (int) ($_POST['cfg_port'] ?? 1521),
            'sid' => (string) ($_POST['cfg_sid'] ?? ''),
            'service_name' => (string) ($_POST['cfg_service'] ?? ''),
            'user' => (string) ($_POST['cfg_user'] ?? ''),
            'pass' => (string) ($_POST['cfg_pass'] ?? ''),
            'charset' => (string) ($_POST['cfg_charset'] ?? 'AL32UTF8'),
            'odbc_dsn' => (string) ($_POST['cfg_odbc'] ?? ''),
        ]);
        $msg = "تم حفظ الإعداد بنجاح:\n" . $path
            . "\nالحالة الآن: " . (oracle_is_enabled() ? 'مفعّل' : 'غير مفعّل — راجع الحقول');
        if (!oracle_php_has_oracle_driver()) {
            $msg .= "\nتنبيه: ما زال PHP بلا pdo_oci/oci8 — الاتصال لن ينجح حتى تثبت Instant Client + الامتداد.";
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
} elseif ($action !== '') {
    $connInfo = oracle_connect();
    if (!$connInfo['ok']) {
        $err = (string) $connInfo['message'];
    } else {
        try {
            if ($action === 'test') {
                $msg = 'نجح الاتصال — المشغّل: ' . $connInfo['driver'] . ' — ' . $connInfo['message'];
                $one = oracle_query_all($connInfo, 'SELECT USER AS U, BANNER FROM V$VERSION WHERE ROWNUM = 1');
                if ($one) {
                    $msg .= ' | USER=' . ($one[0]['U'] ?? $one[0]['u'] ?? '')
                        . ' | ' . ($one[0]['BANNER'] ?? $one[0]['banner'] ?? '');
                }
            } elseif ($action === 'discover') {
                $candidateTables = oracle_discover_customer_tables($connInfo);
                $msg = 'عُثر على ' . count($candidateTables) . ' جدول مرشّح لاسمّ يحتوي CUST/CLIENT/…';
            } elseif ($action === 'list_tables') {
                $allAppTables = oracle_list_app_tables($connInfo, $tableFilter, 500);
                $msg = 'عُرض ' . count($allAppTables) . ' جدول تطبيق'
                    . ($tableFilter !== '' ? ' (فلتر: ' . $tableFilter . ')' : '')
                    . '. اختر الجدول أو أدخل المالك.الاسم يدوياً.';
            } elseif ($action === 'open_manual') {
                if ($owner === '' || $table === '') {
                    $err = 'أدخل Schema (owner) واسم الجدول.';
                } else {
                    $describeCols = oracle_describe_table($connInfo, $owner, $table);
                    $previewRows = oracle_preview_table($connInfo, $owner, $table, 15);
                    $msg = 'هيكل ' . $owner . '.' . $table . ' — ' . count($describeCols) . ' عمود.';
                }
            } elseif ($action === 'describe' && $owner !== '' && $table !== '') {
                $describeCols = oracle_describe_table($connInfo, $owner, $table);
                $previewRows = oracle_preview_table($connInfo, $owner, $table, 15);
                $msg = 'هيكل ' . $owner . '.' . $table . ' — ' . count($describeCols) . ' عمود.';
            } elseif ($action === 'sync' && $owner !== '' && $table !== '') {
                if ($map['name_ar'] === '' || ($map['oracle_key'] === '' && $map['code'] === '')) {
                    $err = 'اربط على الأقل: الاسم + (oracle_key أو code).';
                } else {
                    if ($map['oracle_key'] === '') {
                        $map['oracle_key'] = $map['code'];
                    }
                    $syncResult = oracle_sync_customers_to_mysql($pdo, $connInfo, $owner, $table, $map);
                    $msg = 'انتهت المزامنة: جديد ' . $syncResult['inserted']
                        . ' | محدّث ' . $syncResult['updated']
                        . ' | متجاوز ' . $syncResult['skipped'];
                    if ($syncResult['errors'] !== []) {
                        $err = implode("\n", array_slice($syncResult['errors'], 0, 15));
                    } elseif (($syncResult['inserted'] + $syncResult['updated']) > 0) {
                        try {
                            oracle_customers_save_mapping($owner, $table, $map);
                            $msg .= "\nتم حفظ التعيين — يمكن التحديث لاحقاً من شاشة العملاء.";
                        } catch (Throwable $e) {
                            $msg .= "\n(تحذير) لم يُحفظ التعيين: " . $e->getMessage();
                        }
                    }
                    $describeCols = oracle_describe_table($connInfo, $owner, $table);
                }
            } elseif ($action === 'sync_saved') {
                $syncResult = oracle_sync_customers_from_saved_config($pdo);
                $msg = 'مزامنة محفوظة: جديد ' . $syncResult['inserted']
                    . ' | محدّث ' . $syncResult['updated']
                    . ' | متجاوز ' . $syncResult['skipped'];
                if ($syncResult['errors'] !== []) {
                    $err = implode("\n", array_slice($syncResult['errors'], 0, 15));
                }
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

// اقتراح تلقائي لأسماء الأعمدة الشائعة
$suggest = static function (array $cols, array $needles): string {
    foreach ($cols as $c) {
        $n = strtoupper((string) ($c['column_name'] ?? ''));
        foreach ($needles as $needle) {
            if (str_contains($n, $needle)) {
                return $n;
            }
        }
    }
    return '';
};
if ($describeCols !== []) {
    if ($map['oracle_key'] === '') {
        $map['oracle_key'] = $suggest($describeCols, ['CUST_ID', 'CUSTOMER_ID', 'CLIENT_ID', 'ID_NO', 'CODE', 'NO']) ?: ($describeCols[0]['column_name'] ?? '');
    }
    if ($map['code'] === '') {
        $map['code'] = $suggest($describeCols, ['CODE', 'NO', 'NUM', 'رقم']);
    }
    if ($map['name_ar'] === '') {
        $map['name_ar'] = $suggest($describeCols, ['NAME', 'NAME_A', 'اسم', 'CNAME']);
    }
    if ($map['phone'] === '') {
        $map['phone'] = $suggest($describeCols, ['PHONE', 'TEL', 'MOBILE', 'هاتف']);
    }
    if ($map['tax_number'] === '') {
        $map['tax_number'] = $suggest($describeCols, ['TAX', 'VAT', 'ضريب']);
    }
    if ($map['address_ar'] === '') {
        $map['address_ar'] = $suggest($describeCols, ['ADDR', 'ADDRESS', 'عنوان']);
    }
}

$cfg = oracle_config();
$drivers = oracle_php_drivers_status();
$cfgEnabled = oracle_is_enabled();
$cfgPath = (string) ($cfg['_path'] ?? app_path('config/oracle.local.php'));
$passPlaceholder = is_file($cfgPath) && trim((string) ($cfg['pass'] ?? '')) !== '';
?>
<div class="card" style="max-width:1100px;">
    <h2 style="margin-top:0;">تكامل Oracle — تجربة العملاء</h2>
    <p class="muted" style="margin-top:0;">
        اتصال تجريبي بـ Oracle واستكشاف جداول العملاء ثم مزامنتها إلى Hypex.
    </p>

    <div id="oracle-action-result" style="min-height:0;">
        <?php if ($msg !== ''): ?>
            <div class="alert alert-success oracle-sync-flash" style="white-space:pre-wrap;border:2px solid #0a7;font-size:1.05rem;">
                <strong>نتيجة العملية:</strong><br><?= esc($msg) ?>
            </div>
        <?php endif; ?>
        <?php if ($err !== ''): ?>
            <div class="alert alert-error oracle-sync-flash" style="white-space:pre-wrap;border:2px solid #c00;font-size:1.05rem;">
                <strong>نتيجة العملية (خطأ):</strong><br><?= esc($err) ?>
            </div>
        <?php endif; ?>
        <?php if ($action !== '' && $msg === '' && $err === ''): ?>
            <div class="alert alert-error oracle-sync-flash" style="white-space:pre-wrap;">
                لم تُرجع العملية رسالة — قد يكون الطلب انقطع. أعد المحاولة أو جرّب الرابط السريع أدناه.
            </div>
        <?php endif; ?>
    </div>

    <div class="oracle-status-box <?= $cfgEnabled ? 'oracle-status-box--ok' : 'oracle-status-box--err' ?>" style="white-space:pre-wrap;padding:0.75rem 1rem;border-radius:10px;margin:0.75rem 0;border:1px solid <?= $cfgEnabled ? '#bbf7d0' : '#fecaca' ?>;background:<?= $cfgEnabled ? '#ecfdf5' : '#fef2f2' ?>;color:<?= $cfgEnabled ? '#166534' : '#991b1b' ?>;">
        <strong>1) ملف الإعداد</strong><br>
        المسار: <code><?= esc($cfgPath) ?></code><br>
        الحالة: <?= $cfgEnabled ? 'مفعّل' : 'غير مفعّل' ?><br>
        <?php if (!$cfgEnabled): ?>
            <?= esc(oracle_config_status_message()) ?><br>
            <strong>مهم:</strong> احفظ الإعداد من النموذج أدناه (لا تعتمد على example فقط).
            على السيرفر يكون المسار غالباً: <code>C:\xampp\htdocs\system\config\oracle.local.php</code>
        <?php endif; ?>
    </div>

    <div class="oracle-status-box <?= oracle_php_has_oracle_driver() ? 'oracle-status-box--ok' : 'oracle-status-box--err' ?>" style="white-space:pre-wrap;padding:0.75rem 1rem;border-radius:10px;margin:0.75rem 0;border:1px solid <?= oracle_php_has_oracle_driver() ? '#bbf7d0' : '#fecaca' ?>;background:<?= oracle_php_has_oracle_driver() ? '#ecfdf5' : '#fef2f2' ?>;color:<?= oracle_php_has_oracle_driver() ? '#166534' : '#991b1b' ?>;">
        <strong>2) مشغّلات PHP على هذا الجهاز</strong><br>
        pdo_oci: <?= !empty($drivers['pdo_oci']) ? 'موجود' : 'غير محمّل' ?><br>
        oci8: <?= !empty($drivers['oci8']) ? 'موجود' : 'غير محمّل' ?><br>
        pdo_odbc: <?= !empty($drivers['pdo_odbc']) ? 'موجود' : 'غير محمّل' ?>
        <?php if (!oracle_php_has_oracle_driver()): ?>
            <br><br>
            <strong>هذا سبب فشل «اختبار الاتصال» بعد تفعيل الملف.</strong><br>
            ثبّت Oracle Instant Client + فعّل <code>pdo_oci</code> أو <code>oci8</code> في php.ini لـ XAMPP ثم أعد تشغيل Apache.
        <?php endif; ?>
    </div>

    <h3>إعداد الاتصال (يحفظ oracle.local.php هنا)</h3>
    <form method="post" class="report-sales-filters" style="margin-bottom:1.25rem;">
        <input type="hidden" name="action" value="save_config">
        <div class="form-row" style="flex-wrap:wrap;gap:0.75rem;">
            <label class="field" style="flex:0 0 auto;">
                <span class="field-label">
                    <input type="checkbox" name="cfg_enabled" value="1" <?= !empty($cfg['enabled']) || !is_file($cfgPath) ? 'checked' : '' ?>>
                    مفعّل
                </span>
            </label>
            <label class="field" style="flex:1 1 10rem;">
                <span class="field-label">Host</span>
                <input class="input" name="cfg_host" value="<?= esc((string) ($cfg['host'] ?? '192.168.100.2')) ?>" required>
            </label>
            <label class="field" style="flex:0 1 6rem;">
                <span class="field-label">Port</span>
                <input class="input" name="cfg_port" type="number" value="<?= esc((string) ($cfg['port'] ?? 1521)) ?>">
            </label>
            <label class="field" style="flex:1 1 8rem;">
                <span class="field-label">SID</span>
                <input class="input" name="cfg_sid" value="<?= esc((string) ($cfg['sid'] ?? 'taqwa')) ?>">
            </label>
            <label class="field" style="flex:1 1 8rem;">
                <span class="field-label">Service Name (اختياري)</span>
                <input class="input" name="cfg_service" value="<?= esc((string) ($cfg['service_name'] ?? '')) ?>">
            </label>
            <label class="field" style="flex:1 1 8rem;">
                <span class="field-label">User</span>
                <input class="input" name="cfg_user" value="<?= esc((string) ($cfg['user'] ?? '')) ?>" required>
            </label>
            <label class="field" style="flex:1 1 8rem;">
                <span class="field-label">Password<?= $passPlaceholder ? ' (اتركه فارغاً للإبقاء)' : '' ?></span>
                <input class="input" type="password" name="cfg_pass" value="" autocomplete="new-password" <?= $passPlaceholder ? '' : 'required' ?>>
            </label>
            <label class="field" style="flex:1 1 10rem;">
                <span class="field-label">ODBC DSN (اختياري)</span>
                <input class="input" name="cfg_odbc" value="<?= esc((string) ($cfg['odbc_dsn'] ?? '')) ?>" placeholder="إن وُجد Oracle ODBC">
            </label>
            <input type="hidden" name="cfg_charset" value="<?= esc((string) ($cfg['charset'] ?? 'AL32UTF8')) ?>">
        </div>
        <div style="margin-top:0.75rem;">
            <button class="btn btn-primary" type="submit">حفظ الإعداد على هذا السيرفر</button>
        </div>
    </form>

    <div class="form-row" style="gap:0.5rem;flex-wrap:wrap;align-items:center;">
        <form method="post" style="display:inline;" class="js-oracle-action-form" data-wait="جاري اختبار الاتصال بـ Oracle (قد يستغرق حتى 20 ثانية)…">
            <input type="hidden" name="action" value="test">
            <button class="btn btn-primary" type="submit" id="btn-oracle-test">1) اختبار الاتصال</button>
        </form>
        <form method="post" style="display:inline;" class="js-oracle-action-form" data-wait="جاري اكتشاف الجداول…">
            <input type="hidden" name="action" value="discover">
            <button class="btn btn-ghost" type="submit">2) اكتشاف مرشّحي العملاء</button>
        </form>
        <form method="post" style="display:inline-flex;gap:0.35rem;align-items:center;" class="js-oracle-action-form" data-wait="جاري جلب جداول التطبيق…">
            <input type="hidden" name="action" value="list_tables">
            <input class="input" name="table_filter" value="<?= esc($tableFilter) ?>" placeholder="فلتر اسم جدول (مثل CUST)" style="width:10rem;">
            <button class="btn btn-ghost" type="submit">2ب) كل جداول التطبيق</button>
        </form>
        <?php if (oracle_customers_saved_mapping() !== null): ?>
            <form method="post" style="display:inline;" class="js-oracle-action-form" data-wait="جاري المزامنة…">
                <input type="hidden" name="action" value="sync_saved">
                <button class="btn btn-primary" type="submit">مزامنة بالتعيين المحفوظ</button>
            </form>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= esc(app_url('index.php?r=oracle_customers_sync&do=test')) ?>" id="link-oracle-test-get">
            اختبار سريع (رابط)
        </a>
        <a class="btn btn-ghost" href="<?= esc(app_url('index.php?r=customers')) ?>">شاشة العملاء</a>
    </div>
    <form method="post" class="form-row js-oracle-action-form" style="margin-top:0.75rem;flex-wrap:wrap;gap:0.5rem;align-items:end;" data-wait="جاري فتح الجدول…">
        <input type="hidden" name="action" value="open_manual">
        <label class="field" style="flex:1 1 8rem;">
            <span class="field-label">Schema (Owner)</span>
            <input class="input" name="owner" value="<?= esc($owner) ?>" placeholder="مثل SCOTT أو TAQWA">
        </label>
        <label class="field" style="flex:1 1 10rem;">
            <span class="field-label">اسم الجدول</span>
            <input class="input" name="table" value="<?= esc($table) ?>" placeholder="CUSTOMER أو CUS_…">
        </label>
        <button class="btn btn-ghost" type="submit">فتح الجدول يدوياً</button>
    </form>
    <?php
    $sm = oracle_customers_saved_mapping();
    if ($sm !== null):
        ?>
        <p class="muted" style="font-size:0.9rem;">
            تعيين محفوظ: <code><?= esc($sm['owner'] . '.' . $sm['table']) ?></code>
            <?php if ($sm['last_synced_at'] !== ''): ?>
                — آخر مزامنة: <?= esc($sm['last_synced_at']) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <p id="oracle-wait-msg" class="muted" style="display:none;font-weight:600;color:#a60;"></p>
    <script>
    (function () {
        var wait = document.getElementById('oracle-wait-msg');
        document.querySelectorAll('.js-oracle-action-form').forEach(function (form) {
            form.addEventListener('submit', function () {
                if (!wait) return;
                wait.style.display = 'block';
                wait.textContent = form.getAttribute('data-wait') || 'جاري التنفيذ…';
                var btn = form.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = '… انتظر';
                }
            });
        });
        var g = document.getElementById('link-oracle-test-get');
        if (g && wait) {
            g.addEventListener('click', function () {
                wait.style.display = 'block';
                wait.textContent = 'جاري اختبار الاتصال (رابط)…';
            });
        }
        if (location.hash !== '#oracle-result' && document.getElementById('oracle-action-result')) {
            var r = document.querySelector('.oracle-sync-flash');
            if (r) {
                r.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    })();
    </script>

    <p class="muted" style="font-size:0.9rem;">
        Host حالياً: <?= esc((string) ($cfg['host'] ?? '')) ?>
        :<?= esc((string) ($cfg['port'] ?? '')) ?>
        | SID: <?= esc((string) ($cfg['sid'] ?? '')) ?>
        | User: <?= esc((string) ($cfg['user'] ?? '')) ?>
    </p>

    <?php if ($candidateTables !== [] || $allAppTables !== []): ?>
        <h3><?= $candidateTables !== [] ? 'جداول مرشّحة (عملاء)' : 'جداول التطبيق' ?></h3>
        <div class="report-sales-table-wrap" style="max-height:360px;overflow:auto;">
            <table class="report-sales-table">
                <thead>
                <tr><th>Schema</th><th>الجدول</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($candidateTables !== [] ? $candidateTables : $allAppTables) as $t): ?>
                    <tr>
                        <td><code><?= esc($t['owner']) ?></code></td>
                        <td><code><?= esc($t['table_name']) ?></code></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="describe">
                                <input type="hidden" name="owner" value="<?= esc($t['owner']) ?>">
                                <input type="hidden" name="table" value="<?= esc($t['table_name']) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">عرض الأعمدة + عينة</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($owner !== '' && $table !== ''): ?>
        <h3>تعيين الأعمدة — <?= esc($owner . '.' . $table) ?></h3>
        <form method="post" class="report-sales-filters">
            <input type="hidden" name="action" value="sync">
            <input type="hidden" name="owner" value="<?= esc($owner) ?>">
            <input type="hidden" name="table" value="<?= esc($table) ?>">
            <div class="form-row">
                <?php
                $fields = [
                    'map_oracle_key' => ['مفتاح Oracle *', $map['oracle_key']],
                    'map_code' => ['رمز العميل', $map['code']],
                    'map_name' => ['الاسم *', $map['name_ar']],
                    'map_phone' => ['الهاتف', $map['phone']],
                    'map_email' => ['البريد', $map['email']],
                    'map_tax' => ['الرقم الضريبي', $map['tax_number']],
                    'map_address' => ['العنوان', $map['address_ar']],
                    'map_active' => ['الحالة', $map['is_active']],
                ];
                foreach ($fields as $fname => [$label, $val]):
                    ?>
                    <label class="field" style="flex:1 1 12rem;">
                        <span class="field-label"><?= esc($label) ?></span>
                        <select class="input" name="<?= esc($fname) ?>">
                            <option value="">—</option>
                            <?php foreach ($describeCols as $c): ?>
                                <?php $cn = (string) $c['column_name']; ?>
                                <option value="<?= esc($cn) ?>" <?= strtoupper($val) === strtoupper($cn) ? 'selected' : '' ?>>
                                    <?= esc($cn . ' (' . ($c['data_type'] ?? '') . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:0.75rem;">
                <button class="btn btn-primary" type="submit">3) مزامنة العملاء إلى Hypex</button>
                <button class="btn btn-ghost" type="submit" name="action" value="describe" formnovalidate>تحديث العينة</button>
            </div>
        </form>

        <?php if ($describeCols !== []): ?>
            <h4>الأعمدة</h4>
            <p class="muted"><?php foreach ($describeCols as $c) {
                echo esc($c['column_name'] . ':' . $c['data_type']) . ' &nbsp; ';
            } ?></p>
        <?php endif; ?>

        <?php if ($previewRows !== []): ?>
            <h4>عينة (أول <?= count($previewRows) ?> صف)</h4>
            <div class="report-sales-table-wrap" style="overflow:auto;max-height:320px;">
                <table class="report-sales-table" style="font-size:0.85rem;">
                    <thead>
                    <tr>
                        <?php foreach (array_keys($previewRows[0]) as $col): ?>
                            <th><?= esc((string) $col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previewRows as $pr): ?>
                        <tr>
                            <?php foreach ($pr as $cell): ?>
                                <td><?= esc(is_scalar($cell) || $cell === null ? (string) $cell : json_encode($cell, JSON_UNESCAPED_UNICODE)) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (is_array($syncResult)): ?>
        <p>
            النتيجة:
            مدرج <?= (int) $syncResult['inserted'] ?> —
            محدّث <?= (int) $syncResult['updated'] ?> —
            متجاوز <?= (int) $syncResult['skipped'] ?>
        </p>
        <p><a class="btn btn-ghost" href="<?= esc(app_url('index.php?r=customers')) ?>">فتح شاشة العملاء</a></p>
    <?php endif; ?>
</div>
