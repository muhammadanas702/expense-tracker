<?php
/** Pure balance maths (no database). Money is whole minor units. */

/**
 * Turns net balances into the fewest simple payments.
 * @param array<int,int> $net user id => net (positive = is owed, negative = owes); must sum to 0
 * @return array<int,array{from:int,to:int,amount:int}>
 */
function shared_simplify(array $net): array
{
    $cred = [];
    $debt = [];
    foreach ($net as $uid => $n) {
        if ($n > 0) {
            $cred[$uid] = $n;
        } elseif ($n < 0) {
            $debt[$uid] = -$n;
        }
    }
    $out = [];
    $order = function (array &$a) {
        uksort($a, fn($x, $y) => $a[$y] <=> $a[$x] ?: $x <=> $y);
    };
    while ($cred && $debt) {
        $order($cred);
        $order($debt);
        $c = array_key_first($cred);
        $d = array_key_first($debt);
        $amt = min($cred[$c], $debt[$d]);
        $out[] = ['from' => $d, 'to' => $c, 'amount' => $amt];
        $cred[$c] -= $amt;
        $debt[$d] -= $amt;
        if ($cred[$c] === 0) {
            unset($cred[$c]);
        }
        if ($debt[$d] === 0) {
            unset($debt[$d]);
        }
    }
    return $out;
}


/**
 * Person-to-person debts (no re-routing through other people).
 * @param array<int,array<int,int>> $debt  $debt[from][to] = amount `from` owes `to` (may be negative after payments)
 * @return array<int,array{from:int,to:int,amount:int}>  one entry per pair with a non-zero balance, sorted by from, to
 */
function shared_pair_debts(array $debt): array
{
    $users = [];
    foreach ($debt as $f => $row) {
        $users[$f] = true;
        foreach ($row as $t => $v) {
            $users[$t] = true;
        }
    }
    $ids = array_keys($users);
    sort($ids);
    $out = [];
    foreach ($ids as $i => $a) {
        foreach (array_slice($ids, $i + 1) as $b) {
            $x = ($debt[$a][$b] ?? 0) - ($debt[$b][$a] ?? 0);
            if ($x > 0) {
                $out[] = ['from' => $a, 'to' => $b, 'amount' => $x];
            } elseif ($x < 0) {
                $out[] = ['from' => $b, 'to' => $a, 'amount' => -$x];
            }
        }
    }
    return $out;
}
