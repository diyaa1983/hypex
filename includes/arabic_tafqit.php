<?php
declare(strict_types=1);

require_once app_path('includes/company_currency.php');

function arabic_tafqit_words_below_1000(int $n): string
{
    $ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];
    $teens = ['عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر',
        'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    $tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    $hundreds = ['', 'مئة', 'مئتان', 'ثلاثمئة', 'أربعمئة', 'خمسمئة', 'ستمئة', 'سبعمئة',
        'ثمانمئة', 'تسعمئة'];
    if ($n === 0) {
        return '';
    }
    $h = intdiv($n, 100);
    $rem = $n % 100;
    $parts = [];
    if ($h > 0) {
        $parts[] = $hundreds[$h];
    }
    if ($rem >= 10 && $rem < 20) {
        $parts[] = $teens[$rem - 10];
    } else {
        $t = intdiv($rem, 10);
        $o = $rem % 10;
        if ($o > 0 && $t > 0) {
            $parts[] = $ones[$o] . ' و' . $tens[$t];
        } elseif ($o > 0) {
            $parts[] = $ones[$o];
        } elseif ($t > 0) {
            $parts[] = $tens[$t];
        }
    }

    return implode(' و', $parts);
}

function arabic_tafqit_group_name(int $n, string $singular, string $dual, string $pluralFew, string $accusative): string
{
    $ones3to10 = ['', '', '', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة'];
    if ($n === 0) {
        return '';
    }
    if ($n === 1) {
        return $singular;
    }
    if ($n === 2) {
        return $dual;
    }
    if ($n >= 3 && $n <= 10) {
        return $ones3to10[$n] . ' ' . $pluralFew;
    }

    return arabic_tafqit_words_below_1000($n) . ' ' . $accusative;
}

function arabic_tafqit_words_int(int $num): string
{
    $num = (int) abs($num);
    if ($num === 0) {
        return 'صفر';
    }
    $billions = intdiv($num, 1000000000);
    $millions = intdiv($num % 1000000000, 1000000);
    $thousands = intdiv($num % 1000000, 1000);
    $units = $num % 1000;
    $parts = [];
    if ($billions > 0) {
        $parts[] = arabic_tafqit_group_name($billions, 'مليار', 'ملياران', 'مليارات', 'ملياراً');
    }
    if ($millions > 0) {
        $parts[] = arabic_tafqit_group_name($millions, 'مليون', 'مليونان', 'ملايين', 'مليوناً');
    }
    if ($thousands > 0) {
        $parts[] = arabic_tafqit_group_name($thousands, 'ألف', 'ألفان', 'آلاف', 'ألفاً');
    }
    if ($units > 0) {
        $parts[] = arabic_tafqit_words_below_1000($units);
    }

    return implode(' و', $parts);
}

/** تفقيط مبلغ — نفس fin-receipt.js */
function arabic_tafqit_amount(float $amount, ?PDO $pdo = null): string
{
    if (!is_finite($amount)) {
        return '';
    }
    $cur = company_currency($pdo);
    $mainCurrency = (string) ($cur['main_ar'] ?? 'ريالاً');
    $fractionCurrency = (string) ($cur['fraction_ar'] ?? 'هللة');
    $fractionUnits = (int) ($cur['fraction_units'] ?? 100);
    if ($fractionUnits !== 1000) {
        $fractionUnits = $fractionUnits === 100 ? 100 : 100;
    }

    $sign = $amount < 0 ? 'سالب ' : '';
    $abs = abs($amount);
    $intPart = (int) floor($abs);
    $fracPart = (int) round(($abs - $intPart) * $fractionUnits);
    if ($fracPart === $fractionUnits) {
        $intPart += 1;
        $fracPart = 0;
    }

    $out = $sign;
    if ($intPart > 0) {
        $out .= arabic_tafqit_words_int($intPart) . ' ' . $mainCurrency;
    } else {
        $out .= 'صفر ' . $mainCurrency;
    }
    if ($fracPart > 0) {
        $out .= ' و' . arabic_tafqit_words_int($fracPart) . ' ' . $fractionCurrency;
    }
    $out .= ' فقط لا غير.';

    return $out;
}
