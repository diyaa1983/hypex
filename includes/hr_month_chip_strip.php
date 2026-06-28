<?php
declare(strict_types=1);

/** @return array<int, string> */
function hr_month_short_names_ar(): array
{
    return [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];
}

/**
 * @param list<array{month:int, status?:string, label_suffix?:string}> $monthOptions
 * @param array<string, mixed> $cfg
 */
function hr_render_month_chip_strip(array $monthOptions, array $cfg): void
{
    $year = (int) ($cfg['year'] ?? (int) date('Y'));
    $selectedMonth = (int) ($cfg['selected_month'] ?? 0);
    $monthInputId = trim((string) ($cfg['month_input_id'] ?? 'hr-mchip-filter-month'));
    $monthInputName = trim((string) ($cfg['month_input_name'] ?? 'month'));
    $yearInputId = trim((string) ($cfg['year_input_id'] ?? ''));
    $yearInputName = trim((string) ($cfg['year_input_name'] ?? 'year'));
    $renderYear = !array_key_exists('render_year', $cfg) || !empty($cfg['render_year']);
    $monthShortNames = hr_month_short_names_ar();

    $statusByMonth = [];
    foreach ($monthOptions as $opt) {
        $m = (int) ($opt['month'] ?? 0);
        if ($m >= 1 && $m <= 12) {
            $statusByMonth[$m] = $opt;
        }
    }

    $displayMonth = $selectedMonth >= 1 && $selectedMonth <= 12
        ? $selectedMonth
        : max(1, min(12, (int) date('n')));
    ?>
    <div class="hr-mchip-period-wrap">
        <?php if ($renderYear && $yearInputId !== ''): ?>
            <label class="sr-only" for="<?= esc($yearInputId) ?>">السنة</label>
            <input class="input hr-mchip-year-input" type="number"
                   name="<?= esc($yearInputName) ?>" id="<?= esc($yearInputId) ?>"
                   min="2000" max="2100" value="<?= $year ?>"
                   dir="ltr" required aria-label="السنة">
        <?php endif; ?>
        <input type="hidden"
               name="<?= esc($monthInputName) ?>"
               id="<?= esc($monthInputId) ?>"
               value="<?= $displayMonth ?>">
        <div class="hr-mchip-strip" role="group" aria-label="اختر الشهر">
            <?php for ($m = 1; $m <= 12; $m++):
                $opt = $statusByMonth[$m] ?? ['month' => $m, 'status' => 'empty', 'label_suffix' => ''];
                $status = (string) ($opt['status'] ?? 'empty');
                $suffix = trim((string) ($opt['label_suffix'] ?? ''));
                $title = ($monthShortNames[$m] ?? (string) $m) . ($suffix !== '' ? ' ' . $suffix : '');
                $isSelected = $selectedMonth === $m;
            ?>
                <button type="button"
                        class="hr-mchip-chip<?= $isSelected ? ' is-active' : '' ?>"
                        data-month="<?= $m ?>"
                        data-status="<?= esc($status) ?>"
                        data-name="<?= esc($monthShortNames[$m] ?? (string) $m) ?>"
                        title="<?= esc($title) ?>"
                        aria-pressed="<?= $isSelected ? 'true' : 'false' ?>">
                    <span class="hr-mchip-chip-num" dir="ltr"><?= sprintf('%02d', $m) ?></span>
                    <span class="hr-mchip-chip-label"><?= esc($monthShortNames[$m] ?? (string) $m) ?></span>
                </button>
            <?php endfor; ?>
        </div>
    </div>
    <?php
}

function hr_month_chip_strip_css_url(): string
{
    $path = app_path('assets/css/hr-month-chip-strip.css');

    return app_url('assets/css/hr-month-chip-strip.css')
        . (is_file($path) ? '?v=' . (string) filemtime($path) : '');
}
