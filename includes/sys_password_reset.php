<?php
declare(strict_types=1);

require_once app_path('includes/company_smtp.php');
require_once app_path('includes/login_recaptcha.php');

function sys_password_reset_ensure_schema(PDO $pdo): bool
{
    return login_security_ensure_schema($pdo);
}

/**
 * @return array{ok:bool, message:string}
 */
function sys_password_reset_request(PDO $pdo, string $email): array
{
    $genericOk = 'إذا كان البريد مسجّلاً في النظام، ستصلك رسالة خلال دقائق تحتوي رابط إعادة تعيين كلمة المرور.';

    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'أدخل بريداً إلكترونياً صالحاً.'];
    }

    if (!sys_password_reset_ensure_schema($pdo)) {
        return ['ok' => false, 'message' => 'تعذر تهيئة نظام استعادة كلمة المرور.'];
    }

    if (!company_smtp_is_configured($pdo)) {
        return [
            'ok' => false,
            'message' => 'إرسال البريد غير مهيأ. اطلب من مدير النظام إعداد SMTP من الإعدادات → عام.',
        ];
    }

    $st = $pdo->prepare(
        'SELECT id, username, full_name_ar, email FROM sys_user
         WHERE LOWER(TRIM(email)) = LOWER(?) AND is_active = 1 LIMIT 1'
    );
    $st->execute([$email]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return ['ok' => true, 'message' => $genericOk];
    }

    $userId = (int) ($user['id'] ?? 0);
    if ($userId < 1) {
        return ['ok' => true, 'message' => $genericOk];
    }

    $recent = $pdo->prepare(
        'SELECT COUNT(*) FROM sys_user_password_reset
         WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
    );
    $recent->execute([$userId]);
    if ((int) $recent->fetchColumn() > 0) {
        return ['ok' => true, 'message' => $genericOk];
    }

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);
    $expires = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE sys_user_password_reset SET used_at = NOW()
             WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()'
        )->execute([$userId]);

        $pdo->prepare(
            'INSERT INTO sys_user_password_reset (user_id, token_hash, expires_at) VALUES (?,?,?)'
        )->execute([$userId, $tokenHash, $expires]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'تعذر إنشاء طلب الاستعادة. حاول لاحقاً.'];
    }

    $resetUrl = app_absolute_url('reset_password.php?token=' . urlencode($plainToken));
    $name = trim((string) ($user['full_name_ar'] ?? ''));
    $username = trim((string) ($user['username'] ?? ''));
    $company = company_settings($pdo);
    $companyName = trim((string) ($company['company_name_ar'] ?? 'النظام المحاسبي'));

    $body = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;line-height:1.6;">'
        . '<p>مرحباً' . ($name !== '' ? ' ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : '') . '،</p>'
        . '<p>تلقّينا طلباً لإعادة تعيين كلمة المرور في <strong>'
        . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
    if ($username !== '') {
        $body .= '<p><strong>اسم المستخدم:</strong> '
            . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $body .= '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">اضغط هنا لتعيين كلمة مرور جديدة</a></p>'
        . '<p style="color:#666;font-size:0.9em;">الرابط صالح لمدة ساعة واحدة. بعد تعيين كلمة المرور، سجّل الدخول باستخدام اسم المستخدم أعلاه.</p>'
        . '<p style="color:#666;font-size:0.85em;word-break:break-all;">' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</div>';

    $subject = 'إعادة تعيين كلمة المرور';
    if ($username !== '') {
        $subject .= ' — ' . $username;
    }
    $subject .= ' — ' . $companyName;

    $send = company_smtp_send(
        (string) $user['email'],
        $subject,
        $body
    );

    if (!($send['ok'] ?? false)) {
        return [
            'ok' => false,
            'message' => 'تعذر إرسال البريد: ' . (string) ($send['error'] ?? 'خطأ غير معروف'),
        ];
    }

    return ['ok' => true, 'message' => $genericOk];
}

function sys_password_reset_find_user_id(PDO $pdo, string $plainToken): int
{
    $plainToken = trim($plainToken);
    if ($plainToken === '' || !preg_match('/^[a-f0-9]{64}$/', $plainToken)) {
        return 0;
    }

    if (!sys_password_reset_ensure_schema($pdo)) {
        return 0;
    }

    $hash = hash('sha256', $plainToken);
    $st = $pdo->prepare(
        'SELECT user_id FROM sys_user_password_reset
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $st->execute([$hash]);
    $uid = $st->fetchColumn();

    return $uid !== false ? (int) $uid : 0;
}

function sys_password_reset_complete(PDO $pdo, string $plainToken, string $newPassword): array
{
    if (strlen($newPassword) < 6) {
        return ['ok' => false, 'message' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.'];
    }

    $userId = sys_password_reset_find_user_id($pdo, $plainToken);
    if ($userId < 1) {
        return ['ok' => false, 'message' => 'الرابط غير صالح أو منتهي الصلاحية. اطلب رابطاً جديداً.'];
    }

    $hash = hash('sha256', trim($plainToken));
    $passHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE sys_user SET password_hash = ? WHERE id = ?')->execute([$passHash, $userId]);
        $pdo->prepare(
            'UPDATE sys_user_password_reset SET used_at = NOW()
             WHERE token_hash = ? AND used_at IS NULL'
        )->execute([$hash]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'تعذر حفظ كلمة المرور.'];
    }

    return ['ok' => true, 'message' => 'تم تعيين كلمة المرور. يمكنك تسجيل الدخول الآن.'];
}
