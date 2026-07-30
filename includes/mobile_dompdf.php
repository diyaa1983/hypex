<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

function mobile_dompdf_available(): bool
{
    return is_file(app_path('vendor/autoload.php'));
}

function mobile_mpdf_available(): bool
{
    if (!mobile_dompdf_available()) {
        return false;
    }
    require_once app_path('vendor/autoload.php');

    return class_exists(Mpdf::class);
}

/** تحويل مسارات التطبيق في src إلى مسارات ملفات محلية */
function mobile_pdf_prepare_html(string $html): string
{
    $base = rtrim(APP_URL_BASE, '/');
    if ($base === '') {
        return $html;
    }

    $pattern = '#\bsrc=(["\'])' . preg_quote($base, '#') . '/([^"\']+)\1#u';

    return (string) preg_replace_callback(
        $pattern,
        static function (array $m): string {
            $rel = str_replace('/', DIRECTORY_SEPARATOR, $m[2]);
            $file = app_path($rel);
            if (!is_file($file)) {
                return $m[0];
            }
            $path = str_replace('\\', '/', realpath($file) ?: $file);

            return 'src=' . $m[1] . $path . $m[1];
        },
        $html
    );
}

function mobile_pdf_send_headers(string $downloadFilename): void
{
    $safeAscii = preg_replace('/[^\x20-\x7E]+/', '_', $downloadFilename) ?: 'document.pdf';
    if (!str_ends_with(strtolower($safeAscii), '.pdf')) {
        $safeAscii .= '.pdf';
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safeAscii . '"; filename*=UTF-8\'\''
        . rawurlencode($downloadFilename));
    header('Cache-Control: private, max-age=0, must-revalidate');
}

function mobile_mpdf_temp_dir(): string
{
    $dir = app_path('logs/mpdf_tmp');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'namma_mpdf';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    return $dir;
}

/**
 * خط عربي متصل لـ mPDF: Arial من المشروع أولاً، ثم Windows، ثم DejaVu من mPDF.
 *
 * @return array{fontDir: list<string>, fontdata: array<string, mixed>, default_font: string}
 */
function mobile_mpdf_arabic_font_config(): array
{
    $defaultConfig = (new ConfigVariables())->getDefaults();
    $fontDirs = $defaultConfig['fontDir'];
    $fontData = (new FontVariables())->getDefaults()['fontdata'];

    $projectFonts = app_path('assets/fonts');
    if (is_dir($projectFonts)) {
        $fontDirs[] = $projectFonts;
    }
    if (is_dir('C:/Windows/Fonts')) {
        $fontDirs[] = 'C:/Windows/Fonts';
    }

    $resolvePair = static function (array $dirs, array $regularNames, array $boldNames): ?array {
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $regular = null;
            foreach ($regularNames as $name) {
                if (is_file($dir . DIRECTORY_SEPARATOR . $name)) {
                    $regular = $name;
                    break;
                }
            }
            if ($regular === null) {
                continue;
            }
            $bold = $regular;
            foreach ($boldNames as $name) {
                if (is_file($dir . DIRECTORY_SEPARATOR . $name)) {
                    $bold = $name;
                    break;
                }
            }

            return ['dir' => $dir, 'R' => $regular, 'B' => $bold];
        }

        return null;
    };

    $arial = $resolvePair(
        [$projectFonts, 'C:/Windows/Fonts'],
        ['arial.ttf', 'ARIAL.TTF', 'Arial.ttf'],
        ['arialbd.ttf', 'ARIALBD.TTF', 'Arialbd.ttf', 'Arial-Bold.ttf']
    );
    if ($arial !== null) {
        if (!in_array($arial['dir'], $fontDirs, true)) {
            $fontDirs[] = $arial['dir'];
        }
        $fontData['arial'] = [
            'R' => $arial['R'],
            'B' => $arial['B'],
            'useOTL' => 0xFF,
            'useKashida' => 75,
        ];

        return [
            'fontDir' => array_values(array_unique($fontDirs)),
            'fontdata' => $fontData,
            'default_font' => 'arial',
        ];
    }

    // DejaVu المضمّن مع mPDF يدعم العربية عند تفعيل OTL.
    if (isset($fontData['dejavusans'])) {
        $fontData['dejavusans']['useOTL'] = 0xFF;
        $fontData['dejavusans']['useKashida'] = 75;
    }

    return [
        'fontDir' => array_values(array_unique($fontDirs)),
        'fontdata' => $fontData,
        'default_font' => 'dejavusans',
    ];
}

/** @deprecated استخدم mobile_mpdf_arabic_font_config */
function mobile_mpdf_arial_font_config(): array
{
    return mobile_mpdf_arabic_font_config();
}

function mobile_mpdf_prepare_arabic_html(string $html): string
{
    $html = mobile_pdf_prepare_html($html);
    $html = str_replace(
        [
            'width:400px;max-width:400px;min-width:400px',
            'width:680px;max-width:680px;min-width:680px',
            'min-width:400px',
            'min-width:680px',
            'font-family:Arial,Helvetica,sans-serif',
            "font-family:Arial, Helvetica, sans-serif",
        ],
        [
            'width:100%;max-width:100%',
            'width:100%;max-width:100%',
            'min-width:0',
            'min-width:0',
            'font-family:arial,dejavusans,sans-serif',
            'font-family:arial,dejavusans,sans-serif',
        ],
        $html
    );

    // ضمان RTL + خط عربي حتى لو نُسي في القالب.
    if (!str_contains($html, 'font-family:arial,dejavusans')) {
        $html = str_replace(
            '<head>',
            '<head><style>html,body,#pdf-export-root{direction:rtl;font-family:arial,dejavusans,sans-serif;}</style>',
            $html
        );
    }

    return $html;
}

/** mPDF — عربي متصل وRTL (الخيار الأساسي للموبايل) */
function mobile_mpdf_stream_pdf(string $html, string $downloadFilename): void
{
    require_once app_path('vendor/autoload.php');

    $html = mobile_mpdf_prepare_arabic_html($html);
    $fontCfg = mobile_mpdf_arabic_font_config();

    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 12,
        'margin_right' => 12,
        'margin_top' => 12,
        'margin_bottom' => 12,
        'directionality' => 'rtl',
        'autoScriptToLang' => true,
        'autoLangToFont' => false,
        'useOTL' => 0xFF,
        'useKashida' => 75,
        'tempDir' => mobile_mpdf_temp_dir(),
        'fontDir' => $fontCfg['fontDir'],
        'fontdata' => $fontCfg['fontdata'],
        'default_font' => $fontCfg['default_font'],
        'allowRemoteFiles' => true,
    ]);

    $mpdf->SetDirectionality('rtl');
    $mpdf->WriteHTML($html);
    mobile_pdf_send_headers($downloadFilename);
    echo $mpdf->Output('', Destination::STRING_RETURN);
    exit;
}

/** Dompdf — احتياطي أخير فقط (لا يربط الحروف العربية جيداً). */
function mobile_dompdf_stream_pdf_fallback(string $html, string $downloadFilename): void
{
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('chroot', APP_ROOT);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isFontSubsettingEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml(mobile_pdf_prepare_html($html), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    mobile_pdf_send_headers($downloadFilename);
    echo $dompdf->output();
    exit;
}

/**
 * إخراج PDF للتنزيل المباشر — يفضّل mPDF للعربية ولا يسقط إلى Dompdf إلا عند الفشل الكامل.
 */
function mobile_dompdf_stream_pdf(string $html, string $downloadFilename): void
{
    if (!mobile_dompdf_available()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'PDF library not installed';
        exit;
    }

    require_once app_path('vendor/autoload.php');

    if (mobile_mpdf_available()) {
        try {
            mobile_mpdf_stream_pdf($html, $downloadFilename);
        } catch (Throwable $e) {
            error_log('mobile_mpdf_stream_pdf failed: ' . $e->getMessage());
            // محاولة ثانية بإعداد أبسط (DejaVu فقط).
            try {
                $html2 = mobile_mpdf_prepare_arabic_html($html);
                $defaultConfig = (new ConfigVariables())->getDefaults();
                $fontData = (new FontVariables())->getDefaults()['fontdata'];
                if (isset($fontData['dejavusans'])) {
                    $fontData['dejavusans']['useOTL'] = 0xFF;
                    $fontData['dejavusans']['useKashida'] = 75;
                }
                $mpdf = new Mpdf([
                    'mode' => 'utf-8',
                    'format' => 'A4',
                    'margin_left' => 12,
                    'margin_right' => 12,
                    'margin_top' => 12,
                    'margin_bottom' => 12,
                    'directionality' => 'rtl',
                    'autoScriptToLang' => true,
                    'autoLangToFont' => false,
                    'useOTL' => 0xFF,
                    'tempDir' => mobile_mpdf_temp_dir(),
                    'fontDir' => $defaultConfig['fontDir'],
                    'fontdata' => $fontData,
                    'default_font' => 'dejavusans',
                    'allowRemoteFiles' => true,
                ]);
                $mpdf->SetDirectionality('rtl');
                $mpdf->WriteHTML($html2);
                mobile_pdf_send_headers($downloadFilename);
                echo $mpdf->Output('', Destination::STRING_RETURN);
                exit;
            } catch (Throwable $e2) {
                error_log('mobile_mpdf_stream_pdf dejavu retry failed: ' . $e2->getMessage());
            }
        }
    }

    try {
        mobile_dompdf_stream_pdf_fallback($html, $downloadFilename);
    } catch (Throwable $e) {
        error_log('mobile_dompdf_stream_pdf_fallback failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'pdf_error';
        exit;
    }
}
