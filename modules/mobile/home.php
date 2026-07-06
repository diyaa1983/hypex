<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_icons.php');

$flash = flash_get();
$homeTiles = mobile_home_launcher_tiles();
$userName = trim((string) (current_user()['full_name_ar'] ?? ''));
$avatarLetter = 'م';
if ($userName !== '') {
    if (function_exists('mb_substr')) {
        $avatarLetter = mb_substr($userName, 0, 1, 'UTF-8');
    } else {
        $avatarLetter = substr($userName, 0, 1);
    }
}
?>
<?php if ($flash): ?>
<div class="m-alert m-alert--<?= esc($flash['type'] === 'error' ? 'error' : 'success') ?>">
    <?= esc($flash['message']) ?>
</div>
<?php endif; ?>

<div class="m-home">
    <div class="m-home-hero">
        <a class="m-home-logout" href="<?= esc(app_url('m/logout.php')) ?>">خروج</a>
        <div class="m-home-avatar" aria-hidden="true"><?= esc($avatarLetter) ?></div>
        <div class="m-home-hero-text">
            <p class="m-home-welcome">مرحباً، <?= esc($userName !== '' ? $userName : '—') ?></p>
            <p class="m-home-sub muted">اختر شاشة للبدء</p>
        </div>
    </div>

    <?php if ($homeTiles !== []): ?>
    <div class="m-tile-grid m-tile-grid--square" role="list">
        <?php foreach ($homeTiles as $tile): ?>
        <?php $ico = mobile_icon_tile((string) ($tile['icon'] ?? 'invoice')); ?>
        <?php $tileKind = (string) ($tile['kind'] ?? 'doc'); ?>
        <a class="m-tile m-tile--square m-tile--<?= $tileKind === 'list' ? 'list' : 'doc' ?>" role="listitem" href="<?= esc($tile['url']) ?>">
            <span class="m-tile-icon-wrap <?= esc($ico['class']) ?>" aria-hidden="true"><?= $ico['html'] ?></span>
            <span class="m-tile-label"><?= esc($tile['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="m-home-empty muted">لا توجد شاشات متاحة لحسابك على الهاتف.</p>
    <?php endif; ?>
</div>
