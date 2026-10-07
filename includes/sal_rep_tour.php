<?php
declare(strict_types=1);

require_once app_path('includes/sal_rep_route.php');

/** @return array{from:string,to:string} */
function sal_rep_tour_month_bounds(?string $refDate = null): array
{
    if ($refDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate)) {
        $ts = strtotime($refDate . ' 12:00:00');
    } else {
        $ts = time();
    }
    $y = (int) date('Y', $ts);
    $m = (int) date('n', $ts);
    $from = sprintf('%04d-%02d-01', $y, $m);
    $last = (int) date('t', $ts);
    $to = sprintf('%04d-%02d-%02d', $y, $m, $last);

    return ['from' => $from, 'to' => $to];
}

function sal_rep_tour_snap_full_month(string $iso): array
{
    if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $iso, $m)) {
        return sal_rep_tour_month_bounds();
    }

    return sal_rep_tour_month_bounds($iso);
}

/** @return list<string> */
function sal_rep_tour_days_between(string $from, string $to): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
        return [];
    }
    $out = [];
    $cur = $from;
    $guard = 0;
    while ($cur <= $to && $guard < 400) {
        $out[] = $cur;
        $cur = date('Y-m-d', strtotime($cur . ' 12:00:00 +1 day'));
        $guard++;
    }

    return $out;
}

function sal_rep_tour_weekday_of_iso(string $iso): int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
        return 0;
    }

    return (int) date('w', strtotime($iso . ' 12:00:00'));
}

function sal_rep_tour_has_weekday_column(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT weekday FROM sal_rep_tour_line LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

/** @return array<string,mixed>|null */
function sal_rep_tour_fetch(PDO $pdo, int $tourId): ?array
{
    if ($tourId < 1 || !sal_rep_route_ensure_schema($pdo)) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT t.*, COALESCE(sr.name_ar, \'\') AS sales_rep_name
         FROM sal_rep_tour t
         INNER JOIN crm_sales_rep sr ON sr.id = t.sales_rep_id
         WHERE t.id = ? LIMIT 1'
    );
    $st->execute([$tourId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $hasWd = sal_rep_tour_has_weekday_column($pdo);
    $sql = $hasWd
        ? 'SELECT l.*, c.code AS customer_code, c.name_ar AS customer_name
           FROM sal_rep_tour_line l
           INNER JOIN crm_customer c ON c.id = l.customer_id
           WHERE l.tour_id = ?
           ORDER BY l.weekday, l.sort_order, l.id'
        : 'SELECT l.*, c.code AS customer_code, c.name_ar AS customer_name, 0 AS weekday
           FROM sal_rep_tour_line l
           INNER JOIN crm_customer c ON c.id = l.customer_id
           WHERE l.tour_id = ?
           ORDER BY l.sort_order, l.id';
    $ln = $pdo->prepare($sql);
    $ln->execute([$tourId]);
    $row['lines'] = $ln->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $row;
}

/** @return array<string,mixed>|null */
function sal_rep_tour_covering_month(PDO $pdo, int $salesRepId, string $monthFrom, string $monthTo): ?array
{
    $st = $pdo->prepare(
        'SELECT id, status, date_from, date_to
         FROM sal_rep_tour
         WHERE sales_rep_id = ? AND IFNULL(is_active, 1) = 1
           AND date_from <= ? AND date_to >= ?
         LIMIT 1'
    );
    $st->execute([$salesRepId, $monthTo, $monthFrom]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return array<string,mixed>|null */
function sal_rep_tour_previous_template(PDO $pdo, int $salesRepId, string $beforeDate): ?array
{
    $st = $pdo->prepare(
        'SELECT t.id, t.date_from, t.date_to, t.status
         FROM sal_rep_tour t
         WHERE t.sales_rep_id = ? AND IFNULL(t.is_active, 1) = 1
           AND t.date_to < ?
           AND EXISTS (SELECT 1 FROM sal_rep_tour_line l WHERE l.tour_id = t.id)
         ORDER BY t.date_to DESC, t.id DESC
         LIMIT 1'
    );
    $st->execute([$salesRepId, $beforeDate]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function sal_rep_tour_rebuild_daily_routes(PDO $pdo, int $salesRepId, string $from, string $to): void
{
    if ($salesRepId < 1 || !sal_rep_route_ensure_schema($pdo)) {
        return;
    }
    $hasWd = sal_rep_tour_has_weekday_column($pdo);
    $hasInPlan = false;
    try {
        $pdo->query('SELECT in_plan FROM sal_rep_route_line LIMIT 1');
        $hasInPlan = true;
    } catch (Throwable $e) {
        $hasInPlan = false;
    }
    $hasTourCol = false;
    try {
        $pdo->query('SELECT tour_id FROM sal_rep_route LIMIT 1');
        $hasTourCol = true;
    } catch (Throwable $e) {
        $hasTourCol = false;
    }

    foreach (sal_rep_tour_days_between($from, $to) as $day) {
        $jsWd = sal_rep_tour_weekday_of_iso($day);
        if ($hasWd) {
            $st = $pdo->prepare(
                'SELECT DISTINCT tl.customer_id, MIN(tl.sort_order) AS sort_order, MIN(t.id) AS tour_id
                 FROM sal_rep_tour t
                 INNER JOIN sal_rep_tour_line tl ON tl.tour_id = t.id
                 WHERE t.sales_rep_id = ?
                   AND t.status = ?
                   AND IFNULL(t.is_active, 1) = 1
                   AND tl.date_from <= ?
                   AND tl.date_to >= ?
                   AND tl.weekday = ?
                 GROUP BY tl.customer_id
                 ORDER BY sort_order, tl.customer_id'
            );
            $st->execute([$salesRepId, 'posted', $day, $day, $jsWd]);
        } else {
            $st = $pdo->prepare(
                'SELECT DISTINCT tl.customer_id, MIN(tl.sort_order) AS sort_order, MIN(t.id) AS tour_id
                 FROM sal_rep_tour t
                 INNER JOIN sal_rep_tour_line tl ON tl.tour_id = t.id
                 WHERE t.sales_rep_id = ?
                   AND t.status = ?
                   AND IFNULL(t.is_active, 1) = 1
                   AND tl.date_from <= ?
                   AND tl.date_to >= ?
                 GROUP BY tl.customer_id
                 ORDER BY sort_order, tl.customer_id'
            );
            $st->execute([$salesRepId, 'posted', $day, $day]);
        }
        $custRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stR = $pdo->prepare('SELECT id, tour_id FROM sal_rep_route WHERE sales_rep_id = ? AND route_date = ? LIMIT 1');
        $stR->execute([$salesRepId, $day]);
        $existing = $stR->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($custRows === []) {
            if ($existing) {
                $rid = (int) ($existing['id'] ?? 0);
                $keep = false;
                try {
                    $v = $pdo->prepare(
                        'SELECT COUNT(*) FROM sal_rep_route_line WHERE route_id = ? AND visit_checkin_at IS NOT NULL'
                    );
                    $v->execute([$rid]);
                    $keep = (int) $v->fetchColumn() > 0;
                } catch (Throwable $e) {
                    $keep = false;
                }
                if ($keep) {
                    $pdo->prepare(
                        'DELETE FROM sal_rep_route_line WHERE route_id = ? AND visit_checkin_at IS NULL'
                    )->execute([$rid]);
                } else {
                    $pdo->prepare('DELETE FROM sal_rep_route_line WHERE route_id = ?')->execute([$rid]);
                    $pdo->prepare('DELETE FROM sal_rep_route WHERE id = ?')->execute([$rid]);
                }
            }
            continue;
        }

        $routeId = $existing ? (int) ($existing['id'] ?? 0) : 0;
        $tourId = (int) ($custRows[0]['tour_id'] ?? 0) ?: null;
        if ($routeId > 0) {
            if ($hasTourCol) {
                $pdo->prepare(
                    'UPDATE sal_rep_route SET is_active=1, tour_id=?, notes=COALESCE(notes, ?), updated_at=NOW() WHERE id=?'
                )->execute([$tourId, 'جولة مندوب', $routeId]);
            } else {
                $pdo->prepare('UPDATE sal_rep_route SET is_active=1 WHERE id=?')->execute([$routeId]);
            }
            $pdo->prepare(
                'DELETE FROM sal_rep_route_line WHERE route_id = ? AND visit_checkin_at IS NULL'
            )->execute([$routeId]);
        } else {
            if ($hasTourCol) {
                $pdo->prepare(
                    'INSERT INTO sal_rep_route (sales_rep_id, route_date, notes, tour_id, is_active) VALUES (?,?,?,?,1)'
                )->execute([$salesRepId, $day, 'جولة مندوب', $tourId]);
            } else {
                $pdo->prepare(
                    'INSERT INTO sal_rep_route (sales_rep_id, route_date, notes, is_active) VALUES (?,?,?,1)'
                )->execute([$salesRepId, $day, 'جولة مندوب']);
            }
            $routeId = (int) $pdo->lastInsertId();
        }

        $stH = $pdo->prepare('SELECT customer_id FROM sal_rep_route_line WHERE route_id = ?');
        $stH->execute([$routeId]);
        $haveCust = [];
        foreach ($stH->fetchAll(PDO::FETCH_ASSOC) ?: [] as $hr) {
            $cid = (int) ($hr['customer_id'] ?? 0);
            if ($cid > 0) {
                $haveCust[$cid] = true;
            }
        }
        $sort = count($haveCust);
        foreach ($custRows as $c) {
            $cid = (int) ($c['customer_id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            if (isset($haveCust[$cid])) {
                if ($hasInPlan) {
                    $pdo->prepare('UPDATE sal_rep_route_line SET in_plan=1 WHERE route_id=? AND customer_id=?')
                        ->execute([$routeId, $cid]);
                }
                continue;
            }
            if ($hasInPlan) {
                $pdo->prepare(
                    'INSERT INTO sal_rep_route_line (route_id, customer_id, sort_order, in_plan) VALUES (?,?,?,1)'
                )->execute([$routeId, $cid, $sort++]);
            } else {
                $pdo->prepare(
                    'INSERT INTO sal_rep_route_line (route_id, customer_id, sort_order) VALUES (?,?,?)'
                )->execute([$routeId, $cid, $sort++]);
            }
            $haveCust[$cid] = true;
        }
    }
}

/** @return array{ok:bool,message?:string,error?:string,id?:int} */
function sal_rep_tour_post(PDO $pdo, int $tourId, ?int $userId = null): array
{
    $tour = sal_rep_tour_fetch($pdo, $tourId);
    if (!$tour) {
        return ['ok' => false, 'error' => 'الجولة غير موجودة.'];
    }
    if ((string) ($tour['status'] ?? '') === 'posted') {
        return ['ok' => false, 'error' => 'الجولة مرحّلة مسبقاً.'];
    }
    if (empty($tour['lines'])) {
        return ['ok' => false, 'error' => 'لا يمكن ترحيل جولة بلا عملاء.'];
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'UPDATE sal_rep_tour SET status=?, posted_at=NOW(), posted_by=?, updated_by=? WHERE id=?'
        );
        $st->execute(['posted', $userId, $userId, $tourId]);
        $from = substr((string) ($tour['date_from'] ?? ''), 0, 10);
        $to = substr((string) ($tour['date_to'] ?? ''), 0, 10);
        sal_rep_tour_rebuild_daily_routes($pdo, (int) ($tour['sales_rep_id'] ?? 0), $from, $to);
        $pdo->commit();

        return ['ok' => true, 'id' => $tourId, 'message' => 'تم ترحيل الجولة.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('sal_rep_tour_post: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'تعذر ترحيل الجولة.'];
    }
}

function sal_rep_tour_clone_for_month(
    PDO $pdo,
    int $salesRepId,
    int $sourceTourId,
    string $monthFrom,
    string $monthTo,
    ?int $userId = null
): ?int {
    $source = sal_rep_tour_fetch($pdo, $sourceTourId);
    if (!$source || empty($source['lines'])) {
        return null;
    }
    $hasWd = sal_rep_tour_has_weekday_column($pdo);
    $note = 'فتح تلقائي للشهر · من جولة #' . $sourceTourId;
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO sal_rep_tour (sales_rep_id, date_from, date_to, notes, status, is_active, created_by, updated_by)
             VALUES (?,?,?,?,?,1,?,?)'
        )->execute([$salesRepId, $monthFrom, $monthTo, $note, 'draft', $userId, $userId]);
        $newId = (int) $pdo->lastInsertId();
        $sort = 0;
        foreach ($source['lines'] as $ln) {
            $cid = (int) ($ln['customer_id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            $wd = (int) ($ln['weekday'] ?? 0);
            $rid = (int) ($ln['region_id'] ?? 0) ?: null;
            $raid = (int) ($ln['region_address_id'] ?? 0) ?: null;
            if ($hasWd) {
                $pdo->prepare(
                    'INSERT INTO sal_rep_tour_line
                     (tour_id, customer_id, date_from, date_to, region_id, region_address_id, sort_order, weekday)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([$newId, $cid, $monthFrom, $monthTo, $rid, $raid, $sort++, $wd]);
            } else {
                $pdo->prepare(
                    'INSERT INTO sal_rep_tour_line
                     (tour_id, customer_id, date_from, date_to, region_id, region_address_id, sort_order)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([$newId, $cid, $monthFrom, $monthTo, $rid, $raid, $sort++]);
            }
        }
        $pdo->commit();

        return $newId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('sal_rep_tour_clone_for_month: ' . $e->getMessage());

        return null;
    }
}

/** فتح جولة الشهر الحالي تلقائياً من الشهر السابق (مرحّلة) لكل مندوب نشط. */
function sal_rep_tour_ensure_monthly_rollover(PDO $pdo, ?string $refDate = null): void
{
    static $ranYm = '';
    if (!sal_rep_route_ensure_schema($pdo)) {
        return;
    }
    $bounds = sal_rep_tour_month_bounds($refDate);
    $ym = substr($bounds['from'], 0, 7);
    if ($ranYm === $ym) {
        return;
    }
    $ranYm = $ym;

    try {
        $reps = $pdo->query('SELECT id FROM crm_sales_rep WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('sal_rep_tour_ensure_monthly_rollover reps: ' . $e->getMessage());

        return;
    }

    foreach ($reps as $rep) {
        $repId = (int) ($rep['id'] ?? 0);
        if ($repId < 1) {
            continue;
        }
        if (sal_rep_tour_covering_month($pdo, $repId, $bounds['from'], $bounds['to'])) {
            continue;
        }
        $prev = sal_rep_tour_previous_template($pdo, $repId, $bounds['from']);
        if (!$prev) {
            continue;
        }
        $newId = sal_rep_tour_clone_for_month(
            $pdo,
            $repId,
            (int) ($prev['id'] ?? 0),
            $bounds['from'],
            $bounds['to'],
            null
        );
        if ($newId > 0) {
            sal_rep_tour_post($pdo, $newId, null);
        }
    }
}
