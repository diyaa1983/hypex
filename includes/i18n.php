<?php
declare(strict_types=1);

/**
 * ثنائية اللغة عربي / إنجليزي.
 *
 * الاستخدام: t('نص عربي') أو t('common.save')
 * المفتاح الافتراضي هو النص العربي نفسه → عند lang=ar يُعاد كما هو.
 */

const APP_LANG_AR = 'ar';
const APP_LANG_EN = 'en';

function app_set_lang(string $lang): void
{
    $lang = strtolower(trim($lang));
    if (!in_array($lang, [APP_LANG_AR, APP_LANG_EN], true)) {
        return;
    }
    $_SESSION['app_lang'] = $lang;
    if (!headers_sent()) {
        setcookie('app_lang', $lang, [
            'expires' => time() + 86400 * 365,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
    // إعادة ضبط الكاش الثابت عبر طلب وهمي — نستخدم متغير طلب
    $GLOBALS['__app_lang_force'] = $lang;
}

function app_lang(): string
{
    if (isset($GLOBALS['__app_lang_force']) && is_string($GLOBALS['__app_lang_force'])) {
        $forced = strtolower(trim($GLOBALS['__app_lang_force']));
        if (in_array($forced, [APP_LANG_AR, APP_LANG_EN], true)) {
            return $forced;
        }
    }

    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $allowed = [APP_LANG_AR, APP_LANG_EN];

    // اللغة من إعدادات النظام فقط (بدون ?lang= أو كوكي مستقل)
    try {
        if (function_exists('company_ui_lang')) {
            $companyLang = company_ui_lang();
            if (in_array($companyLang, $allowed, true)) {
                $_SESSION['app_lang'] = $companyLang;

                return $resolved = $companyLang;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $sess = strtolower(trim((string) ($_SESSION['app_lang'] ?? '')));
    if (in_array($sess, $allowed, true)) {
        return $resolved = $sess;
    }

    return $resolved = APP_LANG_AR;
}

function app_dir(): string
{
    return app_lang() === APP_LANG_EN ? 'ltr' : 'rtl';
}

function app_is_rtl(): bool
{
    return app_dir() === 'rtl';
}

/** @return array<string, string> */
function i18n_dictionary(string $lang): array
{
    static $cache = [];
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }
    $file = app_path('lang/' . $lang . '.php');
    if (!is_file($file)) {
        return $cache[$lang] = [];
    }
    $dict = require $file;
    return $cache[$lang] = is_array($dict) ? $dict : [];
}

/**
 * ترجمة نص واجهة.
 * - إن كان المفتاح نصاً عربياً: ar → نفسه، en → من القاموس.
 * - إن كان مفتاحاً منطقياً (common.save): يُبحث في قاموس اللغة الحالية ثم العكس.
 *
 * @param array<string, string|int|float> $replace بدائل مثل :name
 */
function t(string $key, array $replace = []): string
{
    $key = (string) $key;
    if ($key === '') {
        return '';
    }

    $lang = app_lang();
    $out = $key;

    if ($lang === APP_LANG_AR) {
        // مفاتيح منطقية → قاموس عربي؛ النص العربي يبقى كما هو
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $key)) {
            $ar = i18n_dictionary(APP_LANG_AR);
            $out = $ar[$key] ?? $key;
        }
    } else {
        $en = i18n_dictionary(APP_LANG_EN);
        if (isset($en[$key])) {
            $out = $en[$key];
        } elseif (!preg_match('/[\x{0600}-\x{06FF}]/u', $key)) {
            // مفتاح منطقي غير موجود في en: جرّب عبر العربي ثم en
            $ar = i18n_dictionary(APP_LANG_AR);
            $arText = $ar[$key] ?? '';
            if ($arText !== '' && isset($en[$arText])) {
                $out = $en[$arText];
            }
        }
    }

    if ($replace !== []) {
        foreach ($replace as $k => $v) {
            $token = str_starts_with((string) $k, ':') ? (string) $k : (':' . $k);
            $out = str_replace($token, (string) $v, $out);
        }
    }

    return $out;
}

/** اختصار للعرض الآمن HTML */
function te(string $key, array $replace = []): string
{
    return esc(t($key, $replace));
}

/** اسم حقل من صف قاعدة بيانات حسب اللغة (name_ar / name_en …) */
function i18n_field(array $row, string $base, string $fallback = ''): string
{
    $lang = app_lang();
    $preferred = $base . '_' . $lang;
    $alt = $base . (_ar_suffix_for_alt($lang));

    $val = trim((string) ($row[$preferred] ?? ''));
    if ($val !== '') {
        return $val;
    }
    $val = trim((string) ($row[$alt] ?? ''));
    if ($val !== '') {
        return $val;
    }
    if ($base !== '' && isset($row[$base])) {
        $val = trim((string) $row[$base]);
        if ($val !== '') {
            return $val;
        }
    }

    return $fallback;
}

function _ar_suffix_for_alt(string $lang): string
{
    return $lang === APP_LANG_EN ? 'ar' : 'en';
}

/** ترجمة عناوين/تسميات شجرة القائمة */
function i18n_translate_nav_tree(array $menu): array
{
    if (!isset($menu['domains']) || !is_array($menu['domains'])) {
        return $menu;
    }
    $menu['domains'] = array_map('i18n_translate_nav_node', $menu['domains']);
    return $menu;
}

/** @param array<string, mixed> $node */
function i18n_translate_nav_node(array $node): array
{
    if (isset($node['title']) && is_string($node['title'])) {
        $node['title'] = t($node['title']);
    }
    if (isset($node['label']) && is_string($node['label'])) {
        $node['label'] = t($node['label']);
    }
    if (isset($node['subgroups']) && is_array($node['subgroups'])) {
        $node['subgroups'] = array_map('i18n_translate_nav_node', $node['subgroups']);
    }
    if (isset($node['items']) && is_array($node['items'])) {
        $node['items'] = array_map(static function ($item) {
            if (!is_array($item)) {
                return $item;
            }
            if (isset($item['label']) && is_string($item['label'])) {
                $item['label'] = t($item['label']);
            }
            if (isset($item['title']) && is_string($item['title'])) {
                $item['title'] = t($item['title']);
            }
            return $item;
        }, $node['items']);
    }
    return $node;
}

/** رابط تبديل اللغة مع الحفاظ على الاستعلام الحالي */
function app_lang_switch_url(string $targetLang): string
{
    $targetLang = strtolower(trim($targetLang));
    if (!in_array($targetLang, [APP_LANG_AR, APP_LANG_EN], true)) {
        $targetLang = APP_LANG_AR;
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $parts = parse_url($uri);
    $path = (string) ($parts['path'] ?? '/');
    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }
    $query['lang'] = $targetLang;
    $qs = http_build_query($query);

    // مسارات نسبية عبر app_url إن أمكن
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    if ($script !== '' && str_contains($path, $script)) {
        $base = $script;
        return app_url($base . ($qs !== '' ? '?' . $qs : ''));
    }

    return $path . ($qs !== '' ? '?' . $qs : '');
}

function render_lang_switcher(string $extraClass = ''): void
{
    // التبديل من الإعدادات فقط — لا يُعرض في الترويسة/الدخول
}

/** قاموس JS للواجهة الحالية */
function i18n_js_payload(): array
{
    return [
        'lang' => app_lang(),
        'dir' => app_dir(),
        'dict' => [],
    ];
}

function render_i18n_js(): void
{
    $payload = i18n_js_payload();
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"lang":"ar","dir":"rtl","dict":{}}';
    }
    echo '<script>window.APP_I18N=' . $json . ';';
    echo 'window.t=function(k,r){k=String(k||"");if(!k)return"";';
    echo 'var d=(window.APP_I18N_DICT)||((window.APP_I18N&&window.APP_I18N.dict)||{});';
    echo 'var o=(window.APP_I18N&&window.APP_I18N.lang==="en"&&d[k])?d[k]:k;';
    echo 'if(r&&typeof r==="object"){Object.keys(r).forEach(function(x){o=o.split(":"+x).join(String(r[x]));});}';
    echo 'return o;};</script>' . "\n";
    if (app_lang() === APP_LANG_EN) {
        $jsPath = app_path('assets/js/i18n-dict-en.js');
        $v = is_file($jsPath) ? (string) filemtime($jsPath) : '';
        echo '<script src="' . esc(app_url('assets/js/i18n-dict-en.js')) . ($v !== '' ? '?v=' . esc($v) : '') . '"></script>' . "\n";
        $scrubPath = app_path('assets/js/i18n-scrub.js');
        $sv = is_file($scrubPath) ? (string) filemtime($scrubPath) : '';
        echo '<script src="' . esc(app_url('assets/js/i18n-scrub.js')) . ($sv !== '' ? '?v=' . esc($sv) : '') . '"></script>' . "\n";
    }
}

/**
 * ترجمة مقاطع عربية داخل نص عادي باستخدام القاموس.
 * أي عربي غير معروف يُزال حتى لا يبقى ظاهرًا في واجهة الإنجليزية.
 *
 * @param array<string, string> $dict
 */
function i18n_translate_arabic_text(string $text, array $dict): string
{
    static $sortedKeys = null;
    if ($sortedKeys === null) {
        $sortedKeys = array_keys($dict);
        usort($sortedKeys, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $sortedKeys = array_values(array_filter(
            $sortedKeys,
            static fn (string $k): bool => mb_strlen($k) >= 2 && !str_contains($k, '<') && !str_contains($k, '>')
        ));
    }

    foreach ($sortedKeys as $k) {
        if (str_contains($text, $k)) {
            $text = str_replace($k, (string) $dict[$k], $text);
        }
    }

    if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
        $text = (string) preg_replace_callback(
            '/[\x{0600}-\x{06FF}](?:[\x{0600}-\x{06FF}\x{064B}-\x{065F}]|\s+)*/u',
            static function (array $m) use ($dict): string {
                $chunk = trim(preg_replace('/\s+/u', ' ', $m[0]) ?? $m[0]);
                if ($chunk === '') {
                    return '';
                }
                if (isset($dict[$chunk])) {
                    return (string) $dict[$chunk];
                }
                $words = preg_split('/\s+/u', $chunk) ?: [];
                $parts = [];
                foreach ($words as $w) {
                    $w = trim($w);
                    if ($w === '') {
                        continue;
                    }
                    if (isset($dict[$w])) {
                        $parts[] = (string) $dict[$w];
                    }
                    // كلمات عربية غير معروفة تُحذف من واجهة الإنجليزية
                }
                return trim(implode(' ', $parts));
            },
            $text
        );
    }

    return (string) preg_replace('/[ \t]{2,}/u', ' ', $text);
}

/**
 * تنظيف أي عربي متبقٍ داخل النصوص الظاهرة بين وسوم HTML.
 */
function i18n_scrub_remaining_arabic(string $html): string
{
    if ($html === '' || app_lang() !== APP_LANG_EN) {
        return $html;
    }
    $dict = i18n_dictionary(APP_LANG_EN);

    $scrubbed = preg_replace_callback(
        '/>([^<]*)</u',
        static function (array $m) use ($dict): string {
            $text = $m[1];
            if ($text === '' || !preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
                return $m[0];
            }
            return '>' . i18n_translate_arabic_text($text, $dict) . '<';
        },
        $html
    );

    return is_string($scrubbed) ? $scrubbed : $html;
}

/**
 * ترجمة نص/HTML كامل عبر قاموس العربية→الإنجليزية (للمحتوى الذي لم يُلف بـ t()).
 * يُطبَّق فقط عند lang=en.
 * قيم حقول النماذج (value / textarea) تُحفظ كما هي ولا تُترجم حتى لا يُمسح اسم الشركة ونحوه.
 */
function i18n_translate_blob(string $html): string
{
    if ($html === '' || app_lang() !== APP_LANG_EN) {
        return $html;
    }

    /** @var array<string, string> $vault */
    $vault = [];
    $store = static function (string $original) use (&$vault): string {
        $token = '@@I18N_KEEP_' . count($vault) . '@@';
        $vault[$token] = $original;

        return $token;
    };

    // حماية value="..." من الاستبدال/المسح
    $html = (string) preg_replace_callback(
        '/\bvalue=("|\')([^"\']*)\1/iu',
        static function (array $m) use ($store): string {
            return 'value=' . $m[1] . $store($m[2]) . $m[1];
        },
        $html
    );

    // حماية محتوى textarea
    $html = (string) preg_replace_callback(
        '/(<textarea\b[^>]*>)(.*?)(<\/textarea>)/isu',
        static function (array $m) use ($store): string {
            return $m[1] . $store($m[2]) . $m[3];
        },
        $html
    );

    static $pairs = null;
    if ($pairs === null) {
        $dict = i18n_dictionary(APP_LANG_EN);
        $keys = array_keys($dict);
        usort($keys, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pairs = [];
        foreach ($keys as $k) {
            if ($k === '' || !isset($dict[$k])) {
                continue;
            }
            if (mb_strlen($k) < 2) {
                continue;
            }
            if (str_contains($k, '<') || str_contains($k, '>')) {
                continue;
            }
            $pairs[] = [$k, (string) $dict[$k]];
        }
    }

    foreach ($pairs as [$from, $to]) {
        if ($from === $to) {
            continue;
        }
        if (str_contains($html, $from)) {
            $html = str_replace($from, $to, $html);
        }
    }

    // خصائص HTML الظاهرة فقط — بدون value (بيانات المستخدم)
    $html = (string) preg_replace_callback(
        '/\b(title|aria-label|placeholder|alt)=("|\')([^"\']*)\2/iu',
        static function (array $m): string {
            $val = $m[3];
            if ($val === '' || !preg_match('/[\x{0600}-\x{06FF}]/u', $val)) {
                return $m[0];
            }
            // لا تلمس الرموز المحمية
            if (str_contains($val, '@@I18N_KEEP_')) {
                return $m[0];
            }
            $dict = i18n_dictionary(APP_LANG_EN);
            $tr = i18n_translate_arabic_text($val, $dict);

            return $m[1] . '=' . $m[2] . $tr . $m[2];
        },
        $html
    );

    $html = i18n_scrub_remaining_arabic($html);

    // استرجاع قيم النماذج الأصلية
    if ($vault !== []) {
        $html = str_replace(array_keys($vault), array_values($vault), $html);
    }

    return $html;
}

/** توافق مع الاستدعاءات القديمة في المشروع */
function __(string $key, array $replace = []): string
{
    return t($key, $replace);
}

function __e(string $key, array $replace = []): string
{
    return te($key, $replace);
}

function app_html_lang(): string
{
    return app_lang();
}

/** @return array{lang:string,dir:string,catalog:array<string,string>} */
function app_i18n_js_payload(): array
{
    return [
        'lang' => app_lang(),
        'dir' => app_dir(),
        'catalog' => app_lang() === APP_LANG_EN ? i18n_dictionary(APP_LANG_EN) : [],
    ];
}

