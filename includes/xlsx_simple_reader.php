<?php
declare(strict_types=1);

/**
 * قارئ XLSX خفيف (أول ورقة) — بدون مكتبات خارجية.
 * يُفضّل ملفات أنشأها Excel/LibreOffice بصيغة OOXML قياسية.
 *
 * يدعم: ZipArchive، ثم PharData، ثم فك ضغط عبر PowerShell (ويندوز).
 *
 * @return list<list<string>>
 */
function xlsx_simple_read_rows(string $path, int $maxRows = 20000): array
{
    if (!is_readable($path)) {
        throw new RuntimeException('ملف Excel غير قابل للقراءة: ' . $path);
    }

    $entries = xlsx_simple_zip_entries($path, [
        'xl/sharedStrings.xml',
        'xl/workbook.xml',
        'xl/_rels/workbook.xml.rels',
        'xl/worksheets/sheet1.xml',
    ]);

    $shared = [];
    $ssXml = $entries['xl/sharedStrings.xml'] ?? '';
    if ($ssXml !== '') {
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
    $wb = $entries['xl/workbook.xml'] ?? '';
    $rels = $entries['xl/_rels/workbook.xml.rels'] ?? '';
    if ($wb !== '' && $rels !== '') {
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

    $sheetXml = $entries[$sheetPath] ?? '';
    if ($sheetXml === '' && $sheetPath !== 'xl/worksheets/sheet1.xml') {
        // قد تُطلب ورقة غير sheet1 — إعادة قراءة بالإسم الفعلي
        $again = xlsx_simple_zip_entries($path, [$sheetPath]);
        $sheetXml = $again[$sheetPath] ?? '';
    }
    if ($sheetXml === '') {
        // محاولة sheet1 إن فشل ربط workbook
        $sheetXml = $entries['xl/worksheets/sheet1.xml'] ?? '';
    }
    if ($sheetXml === '') {
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

/**
 * يستخرج نصوص ملفات داخل حزمة XLSX (ZIP).
 *
 * @param list<string> $wanted
 * @return array<string, string> path => content
 */
function xlsx_simple_zip_entries(string $path, array $wanted): array
{
    $wantedMap = [];
    foreach ($wanted as $w) {
        $wantedMap[str_replace('\\', '/', $w)] = true;
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $out = [];
            // قراءة كل الملفات المطلوبة + اكتشاف sheet من workbook قد يحتاج أسماء إضافية
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                $need = isset($wantedMap[$name])
                    || str_starts_with($name, 'xl/worksheets/')
                    || $name === 'xl/sharedStrings.xml'
                    || $name === 'xl/workbook.xml'
                    || $name === 'xl/_rels/workbook.xml.rels';
                if (!$need) {
                    continue;
                }
                $data = $zip->getFromIndex($i);
                if (is_string($data)) {
                    $out[$name] = $data;
                }
            }
            $zip->close();
            if ($out !== []) {
                return $out;
            }
        }
    }

    $pharOut = xlsx_simple_zip_via_phar($path);
    if ($pharOut !== []) {
        return $pharOut;
    }

    $psOut = xlsx_simple_zip_via_powershell($path);
    if ($psOut !== []) {
        return $psOut;
    }

    throw new RuntimeException(
        'تعذر قراءة Excel. فعّل extension=zip في php.ini (C:\\xampp\\php\\php.ini) ثم أعد تشغيل Apache، أو تأكد من وجود PowerShell على الجهاز.'
    );
}

/**
 * @return array<string, string>
 */
function xlsx_simple_zip_via_phar(string $path): array
{
    if (!class_exists('PharData')) {
        return [];
    }

    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hypex_xlsx_' . bin2hex(random_bytes(4));
    if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
        return [];
    }
    $tmpZip = $tmpDir . DIRECTORY_SEPARATOR . 'book.zip';
    $out = [];
    try {
        if (!@copy($path, $tmpZip)) {
            return [];
        }
        // PharData يقرأ أرشيف ZIP عند الامتداد .zip
        $phar = @new PharData($tmpZip);
        unset($phar);
        $base = 'phar://' . str_replace('\\', '/', $tmpZip);
        $candidates = [
            'xl/sharedStrings.xml',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/worksheets/sheet1.xml',
        ];
        // sheet2.. إن وُجد
        for ($i = 1; $i <= 5; $i++) {
            $candidates[] = 'xl/worksheets/sheet' . $i . '.xml';
        }
        foreach ($candidates as $rel) {
            $full = $base . '/' . $rel;
            if (@is_file($full)) {
                $data = @file_get_contents($full);
                if (is_string($data)) {
                    $out[$rel] = $data;
                }
            }
        }
    } catch (Throwable $e) {
        $out = [];
    } finally {
        xlsx_simple_rm_tree($tmpDir);
    }

    return $out;
}

/**
 * فك ضغط XLSX عبر Expand-Archive (ويندوز) عندما ZipArchive/Phar غير متاحين.
 *
 * @return array<string, string>
 */
function xlsx_simple_zip_via_powershell(string $path): array
{
    if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        return [];
    }

    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hypex_xlsx_ps_' . bin2hex(random_bytes(4));
    if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
        return [];
    }
    $tmpZip = $tmpDir . DIRECTORY_SEPARATOR . 'book.zip';
    $extract = $tmpDir . DIRECTORY_SEPARATOR . 'out';
    $out = [];
    try {
        if (!@copy($path, $tmpZip)) {
            return [];
        }
        @mkdir($extract, 0777, true);
        $ps = 'Expand-Archive -LiteralPath ' . xlsx_simple_ps_quote($tmpZip)
            . ' -DestinationPath ' . xlsx_simple_ps_quote($extract)
            . ' -Force';
        $cmd = 'powershell -NoProfile -NonInteractive -Command ' . escapeshellarg($ps);
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $desc, $pipes, null, null, ['bypass_shell' => false]);
        if (!is_resource($proc)) {
            return [];
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $walk = static function (string $dir, string $prefix) use (&$walk, &$out): void {
            $items = @scandir($dir);
            if (!is_array($items)) {
                return;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $full = $dir . DIRECTORY_SEPARATOR . $item;
                $rel = ($prefix === '' ? $item : $prefix . '/' . $item);
                $rel = str_replace('\\', '/', $rel);
                if (is_dir($full)) {
                    $walk($full, $rel);
                } elseif (is_file($full)) {
                    $data = @file_get_contents($full);
                    if (is_string($data)
                        && (str_starts_with($rel, 'xl/worksheets/')
                            || $rel === 'xl/sharedStrings.xml'
                            || $rel === 'xl/workbook.xml'
                            || $rel === 'xl/_rels/workbook.xml.rels')
                    ) {
                        $out[$rel] = $data;
                    }
                }
            }
        };
        $walk($extract, '');
    } catch (Throwable $e) {
        $out = [];
    } finally {
        xlsx_simple_rm_tree($tmpDir);
    }

    return $out;
}

function xlsx_simple_ps_quote(string $path): string
{
    return "'" . str_replace("'", "''", $path) . "'";
}

function xlsx_simple_rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        @rmdir($dir);

        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) {
            xlsx_simple_rm_tree($full);
        } else {
            @unlink($full);
        }
    }
    @rmdir($dir);
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
