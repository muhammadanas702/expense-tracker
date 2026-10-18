<?php
// Receipts module: one receipt photo per shared expense, stored privately and shown only to group members.
// Shared only calls receipts_* helpers guarded with function_exists().
return [
    'id' => 'receipts',
    'mode' => 's',
    'enabled' => true,
    'order' => 70,
    'nav' => [],
];
