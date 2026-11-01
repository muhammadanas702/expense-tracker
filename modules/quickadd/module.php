<?php
// Quick add: paste a bank / wallet SMS, the app reads it and opens the normal Add form already filled in.
// No tables, nothing is stored. Switch off with 'enabled' => false.
return [
    'id' => 'quickadd',
    'mode' => 'p',
    'enabled' => true,
    'order' => 59,
    'nav' => [['quick-add.php', 'spark', 'Quick add', 'qadd']],
];
