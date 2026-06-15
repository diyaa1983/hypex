<?php
declare(strict_types=1);

require_once app_path('includes/app_icons.php');

/**
 * صف إدخال افتراضي في جدول بنود الفاتورة (يظهر حتى قبل تحميل JS).
 *
 * @var string $bootstrapLineId
 * @var string $priceStep
 * @var string|null $unitPriceStep
 * @var string|null $amountStep
 * @var array<int, array<string, mixed>> $taxRates
 */
$bootstrapLineId = $bootstrapLineId ?? ('L-boot-' . str_replace('.', '', uniqid('', true)));
$unitPriceStep = $unitPriceStep ?? $priceStep ?? '0.01';
$amountStep = $amountStep ?? $priceStep ?? '0.01';
?>
<tr data-line-id="<?= esc((string) $bootstrapLineId) ?>" data-item-id="" data-name-ar="" class="is-entry-row">
    <td class="sales-inv-col-seq"><span class="js-seq"></span></td>
    <td class="sales-inv-col-sku">
        <code class="js-sku"></code>
        <input type="text" class="input js-barcode-inp" placeholder="مسح أو باركود" autocomplete="off" spellcheck="false" title="امسح الباركود أو أدخل رقم المادة">
    </td>
    <td class="sales-inv-item-cell sales-inv-col-item">
        <button type="button" class="sales-inv-cust-open input js-pick-open" title="اختيار المادة">
            <span class="js-name sales-inv-cust-open-label is-placeholder">اضغط لاختيار المادة</span>
            <span class="sales-inv-cust-open-ico" aria-hidden="true">▾</span>
        </button>
    </td>
    <td class="sales-inv-col-qty"><input type="number" class="input input-num js-qty" min="0" step="1" inputmode="decimal" value="" placeholder=""></td>
    <td class="sales-inv-col-qty-extra"><input type="number" class="input input-num js-qty-extra" min="0" step="1" inputmode="decimal" value="" title="كمية إضافية تُحسب في المخزون فقط"></td>
    <td class="sales-inv-col-price"><input type="text" class="input input-num js-price" min="0" step="<?= esc((string) $unitPriceStep) ?>" inputmode="decimal" value="" title="السعر الإفرادي"></td>
    <td class="sales-inv-col-discount"><input type="text" class="input input-num js-discount" inputmode="decimal" value="" title="نسبة % أو مبلغ ثابت قبل الضريبة" autocomplete="off"></td>
    <td class="sales-inv-col-money"><input type="text" class="input input-num js-line-sub" min="0" step="<?= esc((string) $amountStep) ?>" inputmode="decimal" value="" title="بعد الخصم وقبل الضريبة"></td>
    <td class="sales-inv-col-money js-tax-amt"></td>
    <td class="sales-inv-col-tax">
        <select class="input js-tax">
            <?php foreach ($taxRates as $tr): ?>
                <option value="<?= (int) $tr['id'] ?>" data-rate="<?= esc((string) $tr['rate_percent']) ?>">
                    <?= esc((string) $tr['name_ar']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </td>
    <td class="sales-inv-col-total js-line-gross" title="يُحسب تلقائياً"></td>
    <td class="sales-inv-col-del"><button type="button" class="btn-icon danger js-remove" title="حذف" aria-label="حذف البند" style="visibility:hidden"><?= app_icon_svg('trash', 18) ?></button></td>
</tr>
