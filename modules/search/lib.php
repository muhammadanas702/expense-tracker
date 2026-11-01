<?php
require_once __DIR__ . '/../personal/lib.php';
/**
 * Search over the person's own income and expenses. Read-only; only ever reads rows of the given user.
 * Filters: q (title, note, category, tags), tag, type (all|expense|income), category, from, to (dates), min, max (in PKR).
 */
const SEARCH_LIMIT = 100;

/** Cleans the raw GET values; unknown or broken values are dropped (never an error). */
function search_filters(array $in): array
{
    $f = ['q' => '', 'tag' => '', 'type' => 'all', 'category' => '', 'from' => '', 'to' => '', 'min' => '', 'max' => ''];
    $f['q'] = mb_substr(trim((string) ($in['q'] ?? '')), 0, 60);
    $tag = personal_tags_store((string) ($in['tag'] ?? ''));
    $f['tag'] = is_string($tag) ? (personal_tags_list($tag)[0] ?? '') : '';
    $f['type'] = in_array($in['type'] ?? '', ['expense', 'income'], true) ? $in['type'] : 'all';
    $f['category'] = mb_substr(trim((string) ($in['category'] ?? '')), 0, 50);
    foreach (['from', 'to'] as $k) {
        $d = (string) ($in[$k] ?? '');
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        $f[$k] = $dt && $dt->format('Y-m-d') === $d ? $d : '';
    }
    foreach (['min', 'max'] as $k) {
        $v = str_replace(',', '', trim((string) ($in[$k] ?? '')));
        $f[$k] = preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v) ? $v : '';
    }
    return $f;
}

function search_active(array $f): bool
{
    return $f['q'] !== '' || $f['tag'] !== '' || $f['type'] !== 'all' || $f['category'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['min'] !== '' || $f['max'] !== '';
}

function search_like(string $s): string
{
    return '%' . addcslashes($s, '\\%_') . '%';
}

/**
 * Returns ['rows' => [...up to SEARCH_LIMIT, newest first], 'count' => total matches, 'income_pkr' => x, 'expense_pkr' => y].
 * Each row: kind, id, title, amount, currency, amount_pkr, category, transaction_date, note, tags[].
 */
function search_run(PDO $conn, int $userId, array $f): array
{
    personal_ensure_schema($conn);
    $parts = [];
    $params = [];
    foreach (['expense' => 'expenses', 'income' => 'income'] as $kind => $table) {
        if ($f['type'] !== 'all' && $f['type'] !== $kind) {
            continue;
        }
        $cat = $kind === 'expense' ? 'category' : 'NULL';
        $w = ['user_id = ?'];
        $p = [$userId];
        if ($f['q'] !== '') {
            $like = search_like($f['q']);
            $w[] = '(title LIKE ? OR note LIKE ? OR tags LIKE ?' . ($kind === 'expense' ? ' OR category LIKE ?' : '') . ')';
            array_push($p, $like, $like, $like);
            if ($kind === 'expense') {
                $p[] = $like;
            }
        }
        if ($f['tag'] !== '') {
            $w[] = 'tags LIKE ?';
            $p[] = '%,' . addcslashes($f['tag'], '\\%_') . ',%';
        }
        if ($f['category'] !== '') {
            if ($kind === 'income') {
                continue; // income has no category
            }
            $w[] = 'category = ?';
            $p[] = $f['category'];
        }
        if ($f['from'] !== '') {
            $w[] = 'transaction_date >= ?';
            $p[] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== '') {
            $w[] = 'transaction_date < ?';
            $p[] = (new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
        }
        if ($f['min'] !== '') {
            $w[] = 'amount_pkr >= ?';
            $p[] = $f['min'];
        }
        if ($f['max'] !== '') {
            $w[] = 'amount_pkr <= ?';
            $p[] = $f['max'];
        }
        $parts[] = "SELECT '$kind' AS kind, id, title, amount, currency, amount_pkr, $cat AS category, transaction_date, note, tags FROM `$table` WHERE " . implode(' AND ', $w);
        $params = array_merge($params, $p);
    }
    $out = ['rows' => [], 'count' => 0, 'income_pkr' => 0.0, 'expense_pkr' => 0.0];
    if (!$parts) {
        return $out;
    }
    $union = implode(' UNION ALL ', $parts);
    $t = $conn->prepare("SELECT kind, COUNT(*) AS n, COALESCE(SUM(amount_pkr),0) AS s FROM ($union) x GROUP BY kind");
    $t->execute($params);
    foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out['count'] += (int) $r['n'];
        $out[$r['kind'] . '_pkr'] = round((float) $r['s'], 2);
    }
    $q = $conn->prepare("$union ORDER BY transaction_date DESC, id DESC LIMIT " . SEARCH_LIMIT);
    $q->execute($params);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['tags'] = personal_tags_list($r['tags']);
        $out['rows'][] = $r;
    }
    return $out;
}

/** The person's own tags (most used first), for quick filter chips. */
function search_user_tags(PDO $conn, int $userId, int $max = 30): array
{
    personal_ensure_schema($conn);
    $count = [];
    foreach (['expenses', 'income'] as $table) {
        $q = $conn->prepare("SELECT tags FROM `$table` WHERE user_id = ? AND tags IS NOT NULL AND tags <> '' ORDER BY id DESC LIMIT 500");
        $q->execute([$userId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $s) {
            foreach (personal_tags_list($s) as $t) {
                $count[$t] = ($count[$t] ?? 0) + 1;
            }
        }
    }
    arsort($count);
    return array_slice(array_keys($count), 0, $max);
}
