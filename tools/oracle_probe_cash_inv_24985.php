<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_statement.php');
require_once app_path('includes/oracle_rep_collections.php');

$nl = PHP_EOL;
$out = static function (string $s) use ($nl): void {
    echo $s . $nl;
};

$conn = oracle_connect();
if (empty($conn['ok'])) {
    $out('FAIL ' . (string) ($conn['message'] ?? ''));
    exit(1);
}
$out('OK');

$rows = oracle_query_all(
    $conn,
    "SELECT v.VOD_NUM, v.VOD_FLAG, v.VOD_SIDE,
            TO_CHAR(v.VOD_DATE,'YYYY-MM-DD') DT,
            TRIM(TO_CHAR(v.VOD_ACC)) ACC,
            ROUND(ABS(v.VOD_AMOUNT),3) AMT,
            g.ACC_DESC, c.CUS_NUM, c.CUS_SALESMAN, c.CUS_TITLE
       FROM ACCINV.GLVODMF v
       LEFT JOIN ACCINV.GLACTMF g ON TRIM(TO_CHAR(g.ACC_NUM))=TRIM(TO_CHAR(v.VOD_ACC))
       LEFT JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM))=TRIM(TO_CHAR(v.VOD_ACC))
      WHERE v.VOD_NUM = 24985
      ORDER BY v.VOD_SIDE, v.VOD_ACC"
);
$out('===24985===');
foreach ($rows as $r) {
    $out(json_encode($r, JSON_UNESCAPED_UNICODE));
}

// GL rows matching cash-sales description Nov, with same-voucher customer salesman
$rows2 = oracle_query_all(
    $conn,
    "SELECT * FROM (
        SELECT v.VOD_NUM,
               TO_CHAR(v.VOD_DATE,'YYYY-MM-DD') DT,
               TRIM(TO_CHAR(v.VOD_ACC)) ACC,
               ROUND(ABS(v.VOD_AMOUNT),3) AMT,
               g.ACC_DESC,
               (SELECT MAX(NVL(cx.CUS_SALESMAN,0))
                  FROM ACCINV.GLVODMF vx
                  JOIN ACCINV.CUSTOMER cx ON TRIM(TO_CHAR(cx.CUS_NUM))=TRIM(TO_CHAR(vx.VOD_ACC))
                 WHERE vx.VOD_NUM = v.VOD_NUM AND NVL(cx.CUS_SALESMAN,0) > 0) REP_SAME,
               (SELECT MAX(TRIM(cx.CUS_TITLE))
                  FROM ACCINV.GLVODMF vx
                  JOIN ACCINV.CUSTOMER cx ON TRIM(TO_CHAR(cx.CUS_NUM))=TRIM(TO_CHAR(vx.VOD_ACC))
                 WHERE vx.VOD_NUM = v.VOD_NUM) CUS_SAME,
               (SELECT MAX(d.MAN_NUM) FROM MAS.DAILY d WHERE d.V_NUM = v.VOD_NUM AND NVL(d.MAN_NUM,0)>0) MAN_DAILY
          FROM ACCINV.GLVODMF v
          LEFT JOIN ACCINV.GLACTMF g ON TRIM(TO_CHAR(g.ACC_NUM))=TRIM(TO_CHAR(v.VOD_ACC))
         WHERE v.VOD_DATE >= TO_DATE('2026-11-01','YYYY-MM-DD')
           AND v.VOD_DATE < TO_DATE('2027-01-01','YYYY-MM-DD')
           AND ABS(v.VOD_AMOUNT) > 0
           AND (
             INSTR(NVL(TRIM(g.ACC_DESC),' '), 'فواتير نقدية') > 0
             OR INSTR(NVL(TRIM(g.ACC_DESC),' '), 'مبيعات فواتير') > 0
             OR (INSTR(NVL(TRIM(g.ACC_DESC),' '), 'مبيعات') > 0 AND INSTR(NVL(TRIM(g.ACC_DESC),' '), 'نقد') > 0)
           )
         ORDER BY v.VOD_NUM
      ) WHERE ROWNUM <= 40"
);
$out('===cash-inv GL Nov-Dec===');
foreach ($rows2 as $r) {
    $out(json_encode($r, JSON_UNESCAPED_UNICODE));
}

$res = oracle_fetch_rep_collections('2026-11-01', '2026-12-31', 3, 3, 1);
$out('===fetch rep3===');
$out('ok=' . (!empty($res['ok']) ? '1' : '0') . ' count=' . (int) ($res['totals']['count'] ?? 0)
    . ' amt=' . (string) ($res['totals']['amount'] ?? 0)
    . ' mode=' . json_encode($res['filters']['mode'] ?? [], JSON_UNESCAPED_UNICODE));
$cash = 0;
$chq = 0;
$cashInvLike = 0;
foreach ($res['rows'] ?? [] as $r) {
    if (($r['rcv_type'] ?? '') === 'قبض شيك') {
        $chq++;
    } else {
        $cash++;
        $nm = (string) ($r['customer_name'] ?? '');
        if (str_contains($nm, 'فواتير') || str_contains($nm, 'مبيعات')) {
            $cashInvLike++;
        }
    }
}
$out("cash={$cash} cheque={$chq} cash_name_sales_like={$cashInvLike}");
$out('sample cash:');
$n = 0;
foreach ($res['rows'] ?? [] as $r) {
    if (($r['rcv_type'] ?? '') === 'قبض شيك') {
        continue;
    }
    $out(json_encode($r, JSON_UNESCAPED_UNICODE));
    if (++$n >= 25) {
        break;
    }
}

// voucher 24985 specifically in result?
$found = false;
foreach ($res['rows'] ?? [] as $r) {
    if ((int) ($r['rcv_no'] ?? 0) === 24985) {
        $found = true;
        $out('FOUND 24985: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    }
}
if (!$found) {
    $out('24985 NOT in fetch result for rep 3');
}
