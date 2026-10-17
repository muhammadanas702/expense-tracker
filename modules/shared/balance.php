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
