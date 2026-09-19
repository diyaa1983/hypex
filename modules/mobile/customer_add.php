<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');

require_mobile_permission('m_customer_add');

$pdo = db();
crm_customer_ensure_gps_columns($pdo);
$msg = '';
$msgType = '';
$uid = (int) (current_user()['id'] ?? 0);
$rep = crm_sales_rep_row_for_user($pdo, $uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة.';
        $msgType = 'error';
    } else {
        $gps = [
            'latitude' => $_POST['latitude'] ?? null,
            'longitude' => $_POST['longitude'] ?? null,
            'gps_accuracy' => $_POST['gps_accuracy'] ?? null,
        ];
        $result = crm_mobile_customer_create_for_user(
            $pdo,
            $uid,
            (string) ($_POST['name_ar'] ?? ''),
            (string) ($_POST['phone'] ?? ''),
            (string) ($_POST['address_ar'] ?? ''),
            $gps
        );
        $msg = $result['message'];
        $msgType = $result['ok'] ? 'success' : 'error';
    }
}

$nameVal = $msgType === 'error' ? trim((string) ($_POST['name_ar'] ?? '')) : '';
$phoneVal = $msgType === 'error' ? trim((string) ($_POST['phone'] ?? '')) : '';
$addrVal = $msgType === 'error' ? trim((string) ($_POST['address_ar'] ?? '')) : '';
$latVal = $msgType === 'error' ? trim((string) ($_POST['latitude'] ?? '')) : '';
$lngVal = $msgType === 'error' ? trim((string) ($_POST['longitude'] ?? '')) : '';
$accVal = $msgType === 'error' ? trim((string) ($_POST['gps_accuracy'] ?? '')) : '';
?>
<div class="m-ora12 m-ora12-invoice">
<div class="m-ora12-workspace">
    <?php if ($msg !== ''): ?>
        <div class="m-alert m-alert--<?= $msgType === 'success' ? 'ok' : 'error' ?>"><?= esc($msg) ?></div>
    <?php endif; ?>

    <?php if ($rep === null): ?>
        <div class="m-alert m-alert--error">
            حسابك غير مربوط بمندوب مبيعات. راجع مدير النظام من شاشة المستخدمون.
        </div>
    <?php else: ?>
        <section class="m-ora12-panel">
            <h2 class="m-ora12-panel__title">إضافة عميل</h2>
            <div class="m-ora12-panel__body">
                <p class="m-muted" style="margin:0 0 12px;">
                    سيُربط العميل تلقائياً بالمندوب: <strong><?= esc((string) ($rep['name_ar'] ?? '')) ?></strong>
                </p>
                <form method="post" class="m-form" id="m-cust-add-form">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="latitude" id="m-cust-lat" value="<?= esc($latVal) ?>">
                    <input type="hidden" name="longitude" id="m-cust-lng" value="<?= esc($lngVal) ?>">
                    <input type="hidden" name="gps_accuracy" id="m-cust-acc" value="<?= esc($accVal) ?>">
                    <label class="m-field">
                        <span class="m-field__label">اسم العميل *</span>
                        <input class="m-input" name="name_ar" required maxlength="200"
                               value="<?= esc($nameVal) ?>" autocomplete="organization">
                    </label>
                    <label class="m-field">
                        <span class="m-field__label">رقم التلفون</span>
                        <input class="m-input" name="phone" type="tel" maxlength="40"
                               value="<?= esc($phoneVal) ?>" autocomplete="tel" dir="ltr">
                    </label>
                    <label class="m-field">
                        <span class="m-field__label">العنوان</span>
                        <textarea class="m-input" name="address_ar" rows="3" maxlength="500"><?= esc($addrVal) ?></textarea>
                    </label>
                    <div class="m-field">
                        <span class="m-field__label">موقع العميل (GPS)</span>
                        <p id="m-cust-gps-status" class="m-muted" style="margin:0 0 8px;">
                            <?= $latVal !== '' && $lngVal !== ''
                                ? 'الموقع: ' . esc($latVal) . ' ، ' . esc($lngVal)
                                : 'لم يُحدَّد موقع بعد.' ?>
                        </p>
                        <button type="button" class="m-btn m-btn--secondary" id="m-cust-gps-btn" style="width:100%;">
                            تحديد الموقع
                        </button>
                        <button type="button" class="m-btn m-btn--secondary" id="m-cust-gps-clear" style="width:100%;margin-top:6px;"
                            <?= $latVal !== '' && $lngVal !== '' ? '' : 'disabled' ?>>
                            مسح الموقع
                        </button>
                    </div>
                    <button type="submit" class="m-btn m-btn--primary" style="width:100%;margin-top:8px;">حفظ العميل</button>
                </form>
            </div>
        </section>
        <script>
        (function () {
          var latEl = document.getElementById('m-cust-lat');
          var lngEl = document.getElementById('m-cust-lng');
          var accEl = document.getElementById('m-cust-acc');
          var statusEl = document.getElementById('m-cust-gps-status');
          var btn = document.getElementById('m-cust-gps-btn');
          var clearBtn = document.getElementById('m-cust-gps-clear');
          function fmt(n) {
            var x = parseFloat(n);
            return isFinite(x) ? String(Math.round(x * 1e7) / 1e7) : '';
          }
          function setGps(gps) {
            if (!gps) return;
            latEl.value = fmt(gps.latitude);
            lngEl.value = fmt(gps.longitude);
            accEl.value = gps.accuracy != null && isFinite(gps.accuracy) ? String(gps.accuracy) : '';
            statusEl.textContent = 'الموقع: ' + latEl.value + ' ، ' + lngEl.value;
            clearBtn.disabled = false;
          }
          function clearGps() {
            latEl.value = '';
            lngEl.value = '';
            accEl.value = '';
            statusEl.textContent = 'لم يُحدَّد موقع بعد.';
            clearBtn.disabled = true;
          }
          if (clearBtn) clearBtn.addEventListener('click', clearGps);
          if (!btn) return;
          btn.addEventListener('click', function () {
            btn.disabled = true;
            function done(gps) {
              btn.disabled = false;
              if (gps === undefined) return;
              if (!gps) {
                alert('تعذر تحديد الموقع. فعّل GPS واسمح بالوصول للموقع.');
                return;
              }
              setGps(gps);
            }
            if (window.AppGeo && typeof AppGeo.withGpsForPost === 'function') {
              AppGeo.withGpsForPost('mobile', done);
              return;
            }
            if (window.AppGeoMapPick && typeof AppGeoMapPick.pickLocationOnMap === 'function') {
              AppGeoMapPick.pickLocationOnMap({ forPost: false }).then(setGps).catch(function () {}).finally(function () {
                btn.disabled = false;
              });
              return;
            }
            btn.disabled = false;
            alert('خدمة الموقع غير متاحة في هذا المتصفح.');
          });
        })();
        </script>
    <?php endif; ?>
</div>
</div>
