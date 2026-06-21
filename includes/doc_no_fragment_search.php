<?php
declare(strict_types=1);

function doc_no_sql_like_pattern(string $fragment): string
{
    $fragment = trim($fragment);

    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $fragment) . '%';
}

/** @param list<int> $matchIds */
function doc_no_attach_search_matches(array $row, array $matchIds, string $fragment, int $activeId): array
{
    if (count($matchIds) <= 1) {
        return $row;
    }

    $idx = array_search($activeId, $matchIds, true);
    if ($idx === false) {
        $idx = 0;
    }

    $row['search_query'] = trim($fragment);
    $row['search_match_ids'] = array_values($matchIds);
    $row['search_match_index'] = (int) $idx;
    $row['search_match_count'] = count($matchIds);

    return $row;
}

/**
 * @param callable(string): list<int> $searchIdsFn
 * @param callable(int): ?array $fetchByIdFn
 * @return array<string, mixed>|null
 */
function doc_no_fetch_exact_or_fragment(
    PDO $pdo,
    string $no,
    string $exactSql,
    array $exactParams,
    callable $searchIdsFn,
    callable $fetchByIdFn
): ?array {
    $no = trim($no);
    if ($no === '') {
        return null;
    }

    $st = $pdo->prepare($exactSql);
    $st->execute($exactParams);
    $exactId = $st->fetchColumn();
    if ($exactId !== false) {
        return $fetchByIdFn((int) $exactId);
    }

    $ids = $searchIdsFn($no);
    if ($ids === []) {
        return null;
    }

    if (count($ids) === 1) {
        return $fetchByIdFn($ids[0]);
    }

    $row = $fetchByIdFn($ids[0]);
    if ($row === null) {
        return null;
    }

    return doc_no_attach_search_matches($row, $ids, $no, $ids[0]);
}

/** @return list<int> */
function doc_no_search_ids_like(
    PDO $pdo,
    string $sql,
    array $params,
    int $limit = 200
): array {
    $limit = max(1, min(500, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return array_map(static fn ($id) => (int) $id, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}
