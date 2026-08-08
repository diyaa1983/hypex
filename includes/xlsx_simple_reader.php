<?php
declare(strict_types=1);

/**
 * قارئ XLSX خفيف (أول ورقة) — بدون مكتبات خارجية.
 * يُفضّل ملفات أنشأها Excel/LibreOffice بصيغة OOXML قياسية.
 *
 * @return list<list<string>>
 */
function xlsx_simple_read_rows(string $path, int $maxRows = 20000): array
{
    if (!is_readable($path)) {
        throw new RuntimeException('ملف Excel غير قابل للقراءة: ' . $path);
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('امتداد ZipArchive مطلوب لقراءة Excel.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('تعذر فتح ملف Excel (صيغة غير مدعومة؟).');
    }

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($ssXml) && $ssXml !== '') {
        $sx = @simplexml_load_string($ssXml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } else {
                    $parts = [];
                    foreach ($si->r as $r) {
                        $parts[] = (string) ($r->t ?? '');
                    }
                    $shared[] = implode('', $parts);
                }
            }
        }
    }

    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (is_string($wb) && is_string($rels) && $wb !== '' && $rels !== '') {
        $wbX = @simplexml_load_string($wb);
        $relX = @simplexml_load_string($rels);
        if ($wbX !== false && $relX !== false) {
            $wbX->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $relX->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
            $sheets = $wbX->xpath('//m:sheets/m:sheet');
            $firstRid = '';
            if (is_array($sheets) && isset($sheets[0])) {
                $attrs = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $firstRid = (string) ($attrs['id'] ?? '');
            }
            if ($firstRid !== '') {
                foreach ($relX->Relationship as $rel) {
                    $id = (string) ($rel['Id'] ?? '');
                    $target = (string) ($rel['Target'] ?? '');
                    if ($id === $firstRid && $target !== '') {
                        $target = ltrim(str_replace('\\', '/', $target), '/');
                        if (str_starts_with($target, 'xl/')) {
                            $sheetPath = $target;
                        } else {
                            $sheetPath = 'xl/' . $target;
                        }
                        break;
                    }
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if (!is_string($sheetXml) || $sheetXml === '') {
        throw new RuntimeException('تعذر قراءة ورقة العمل من Excel.');
    }

    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('XML ورقة العمل تالف.');
    }
    $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rowsXml = $sheet->xpath('//m:sheetData/m:row');
    if (!is_array($rowsXml)) {
        return [];
    }

    $out = [];
    $count = 0;
    foreach ($rowsXml as $row) {
        if ($count >= $maxRows) {
            break;
        }
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string) ($c['r'] ?? '');
            $col = xlsx_simple_col_index($ref);
            if ($col < 0) {
                continue;
            }
            $type = (string) ($c['t'] ?? '');
            $raw = isset($c->v) ? (string) $c->v : '';
            if ($type === 's') {
                $idx = (int) $raw;
                $val = $shared[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = isset($c->is->t) ? (string) $c->is->t : '';
            } else {
                $val = $raw;
            }
            $cells[$col] = trim(str_replace(["\r", "\n"], ' ', $val));
        }
        if ($cells === []) {
            $count++;
            continue;
        }
        $maxCol = max(array_keys($cells));
        $line = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $line[] = $cells[$i] ?? '';
        }
        // تخطّ الصف الفارغ
        $allEmpty = true;
        foreach ($line as $v) {
            if (trim((string) $v) !== '') {
                $allEmpty = false;
                break;
            }
        }
        if (!$allEmpty) {
            $out[] = $line;
        }
        $count++;
    }

    return $out;
}

function xlsx_simple_col_index(string $cellRef): int
{
    if (!preg_match('/^([A-Z]+)/i', $cellRef, $m)) {
        return -1;
    }
    $letters = strtoupper($m[1]);
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }

    return $n - 1;
}
