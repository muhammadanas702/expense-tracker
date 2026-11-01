<?php
// Savings goals: named targets with an optional deadline, money added by hand, progress and "per month" hint.
// Nothing else depends on this module; switch it off with 'enabled' => false.
return [
    'id' => 'goals',
    'mode' => 'p',
    'enabled' => true,
    'order' => 56,
    'nav' => [['goals.php', 'wal', 'Goals', 'goal']],
];
