<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_icons.php');

$flash = flash_get();
$homeTiles = mobile_home_launcher_tiles();
?>
<?php if ($flash): ?>
<div class="m-alert m-alert--<?= esc($flash['type'] === 'error' ? 'error' : 'success') ?>">
    <?= esc($flash['message']) ?>
</div>
<?php endif; ?>

<div class="m-home">
    <p class="m-home-welcome">مرحباً، <?= esc((string) (current_user()['full_name_ar'] ?? '')) ?></p>
    <p class="m-home-sub muted">اختر شاشة للبدء</p>
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
    <p class="m-home-note muted">نفس بيانات النظام الرئيسي — واجهة مخصصة للهاتف فقط.</p>
</div>
