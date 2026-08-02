<?php
declare(strict_types=1);

require_once app_path('includes/company_settings.php');

/** @return list<int> */
function company_rows_per_page_allowed(): array
{
    return [10, 15, 20];
}

function company_rows_per_page(?PDO $pdo = null): int
{
    $allowed = company_rows_per_page_allowed();
    $n = (int) (company_settings($pdo)['rows_per_page'] ?? 10);

    return in_array($n, $allowed, true) ? $n : 10;
}

/** @return array{page:int, per_page:int, limit:int, offset:int} */
function list_pager_from_request(?PDO $pdo = null): array
{
    $perPage = company_rows_per_page($pdo);
    $page = max(1, (int) ($_GET['page'] ?? 1));

    return [
        'page' => $page,
        'per_page' => $perPage,
        'limit' => $perPage,
        'offset' => ($page - 1) * $perPage,
    ];
}

/**
 * @param array{page:int, per_page:int, limit:int, offset:int} $pager
 * @return array{page:int, per_page:int, limit:int, offset:int, total:int, total_pages:int}
 */
function list_pager_with_total(array $pager, int $total): array
{
    $total = max(0, $total);
    $totalPages = max(1, (int) ceil($total / max(1, $pager['per_page'])));
    $page = min($pager['page'], $totalPages);
    if ($page !== $pager['page']) {
        $pager['page'] = $page;
        $pager['offset'] = ($page - 1) * $pager['per_page'];
    }
    $pager['total'] = $total;
    $pager['total_pages'] = $totalPages;

    return $pager;
}

function list_pager_sql_limit(array $pager): string
{
    return ' LIMIT ' . (int) $pager['limit'] . ' OFFSET ' . (int) $pager['offset'];
}

/**
 * ترقيم قوائم تطبيق الهاتف حسب إعداد «أسطر الصفحة».
 *
 * @return array{page:int, per_page:int, limit:int, offset:int, total:int, total_pages:int}
 */
function mobile_list_pager_from_request(?PDO $pdo = null, ?int $total = null): array
{
    $pager = list_pager_from_request($pdo);
    if ($total !== null) {
        return list_pager_with_total($pager, $total);
    }

    $pager['total'] = 0;
    $pager['total_pages'] = 1;

    return $pager;
}

/**
 * @param array{page:int, per_page:int, total?:int, total_pages?:int} $pager
 * @return array{page:int, per_page:int, total:int, total_pages:int}
 */
function mobile_list_pager_meta(array $pager): array
{
    return [
        'page' => (int) ($pager['page'] ?? 1),
        'per_page' => (int) ($pager['per_page'] ?? company_rows_per_page()),
        'total' => (int) ($pager['total'] ?? 0),
        'total_pages' => (int) ($pager['total_pages'] ?? 1),
    ];
}

/** @param array<string, scalar|null> $query */
function list_pager_base_url(string $route, array $query = []): string
{
    unset($query['page'], $query['r']);
    $query['r'] = $route;

    return app_url('index.php?' . http_build_query($query));
}

/**
 * @param array{page:int, per_page:int, total?:int, total_pages?:int} $pager
 */
function list_pager_render(array $pager, string $baseUrl): void
{
    $total = (int) ($pager['total'] ?? 0);
    if ($total < 1) {
        return;
    }

    $page = (int) $pager['page'];
    $perPage = (int) $pager['per_page'];
    $totalPages = (int) ($pager['total_pages'] ?? 1);
    $from = ($page - 1) * $perPage + 1;
    $to = min($total, $page * $perPage);

    $glue = str_contains($baseUrl, '?') ? '&' : '?';

    echo '<nav class="list-pager" aria-label="ترقيم الصفحات">';
    echo '<span class="list-pager-info">عرض ' . (int) $from . '–' . (int) $to . ' من ' . (int) $total . '</span>';

    if ($totalPages > 1) {
        echo '<div class="list-pager-links">';
        if ($page > 1) {
            echo '<a class="list-pager-btn" href="' . esc($baseUrl . $glue . 'page=' . ($page - 1)) . '">السابق</a>';
        }

        for ($p = 1; $p <= $totalPages; $p++) {
            if ($totalPages > 9 && $p > 2 && $p < $totalPages - 1 && abs($p - $page) > 2) {
                if ($p === 3 || $p === $totalPages - 2) {
                    echo '<span class="list-pager-ellipsis" aria-hidden="true">…</span>';
                }
                continue;
            }
            $cls = 'list-pager-num' . ($p === $page ? ' is-active' : '');
            echo '<a class="' . $cls . '" href="' . esc($baseUrl . $glue . 'page=' . $p) . '">' . $p . '</a>';
        }

        if ($page < $totalPages) {
            echo '<a class="list-pager-btn" href="' . esc($baseUrl . $glue . 'page=' . ($page + 1)) . '">التالي</a>';
        }
        echo '</div>';
    }

    echo '</nav>';
}
