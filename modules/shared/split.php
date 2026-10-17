<?php
/**
 * Pure split maths for shared expenses (no database, easy to test).
 * All money is whole minor units (paisa/cents), so shares always add up to the total exactly.
 */
const SHARED_SPLIT_TYPES = ['equal' => 'Equally', 'exact' => 'Exact amounts', 'percent' => 'Percent', 'shares' => 'Shares'];

/** "12.5" -> 1250. Returns null for anything that is not a plain non-negative number with at most 2 decimals. */
function shared_to_minor($value): ?int
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }
    $s = trim((string) $value);
    if (!preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $s, $m)) {
        return null;
    }
    return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
}

function shared_from_minor(int $minor): string
{
    return number_format($minor / 100, 2, '.', '');
}

/** Splits $total by integer weights using the largest-remainder method. Result sums to $total. */
function shared_apportion(int $total, array $weights): array
{
    $sum = array_sum($weights);
    $out = [];
    $rem = [];
    $given = 0;
    foreach ($weights as $uid => $w) {
        $num = $total * $w;
        $out[$uid] = intdiv($num, $sum);
        $rem[$uid] = $num % $sum;
        $given += $out[$uid];
    }
    $left = $total - $given;
    // Biggest remainder first; ties go to the lower user id so the result is stable.
    uksort($rem, function ($a, $b) use ($rem) {
        return $rem[$b] <=> $rem[$a] ?: $a <=> $b;
    });
    foreach (array_keys($rem) as $uid) {
        if ($left-- <= 0) {
            break;
        }
        $out[$uid]++;
    }
    ksort($out);
    return $out;
}

/**
 * @param int    $total        total in minor units (> 0)
 * @param string $type         equal | exact | percent | shares
 * @param array  $participants user ids taking part
 * @param array  $inputs       user id => raw text (exact amount / percent / share count); ignored for equal
 * @return array{shares:array<int,int>,weights:array<int,?float>}|string  string = error message
 */
function shared_compute_split(int $total, string $type, array $participants, array $inputs)
{
    $participants = array_values(array_unique(array_map('intval', $participants)));
    sort($participants);
    $n = count($participants);
    if ($n < 1) {
        return 'Choose at least one person to split with.';
    }
    if ($total < 1) {
        return 'Enter an amount greater than zero.';
    }
    if ($type === 'equal') {
        $weights = array_fill_keys($participants, 1);
        return ['shares' => shared_apportion($total, $weights), 'weights' => array_fill_keys($participants, null)];
    }
    $minor = [];
    foreach ($participants as $uid) {
        $raw = $inputs[$uid] ?? '';
        $v = is_string($raw) ? shared_to_minor($raw === '' ? '0' : $raw) : null;
        if ($v === null) {
            return 'Check the numbers: use plain numbers with up to 2 decimals.';
        }
        $minor[$uid] = $v;
    }
    if ($type === 'exact') {
        if (array_sum($minor) !== $total) {
            $diff = $total - array_sum($minor);
            return 'The amounts must add up to the total. ' . ($diff > 0 ? shared_from_minor($diff) . ' is still left.' : shared_from_minor(-$diff) . ' is too much.');
        }
        return ['shares' => $minor, 'weights' => array_map(fn($m) => $m / 100, $minor)];
    }
    if ($type === 'percent') {
        if (array_sum($minor) !== 10000) {
            return 'The percentages must add up to exactly 100.';
        }
        $w = array_filter($minor, fn($m) => $m > 0);
        return ['shares' => array_replace(array_fill_keys($participants, 0), shared_apportion($total, $w)), 'weights' => array_map(fn($m) => $m / 100, $minor)];
    }
    if ($type === 'shares') {
        if (max($minor) > 100000) {
            return 'Share counts are too large (1000 at most).';
        }
        $w = array_filter($minor, fn($m) => $m > 0);
        if (!$w) {
            return 'Give at least one person a share greater than zero.';
        }
        return ['shares' => array_replace(array_fill_keys($participants, 0), shared_apportion($total, $w)), 'weights' => array_map(fn($m) => $m / 100, $minor)];
    }
    return 'Unknown split type.';
}
