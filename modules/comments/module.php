<?php
// Comments module: short comments under a shared expense. Shared only calls comments_* helpers
// guarded with function_exists(), so this module can be switched off without breaking Shared.
return [
    'id' => 'comments',
    'mode' => 's',
    'enabled' => true,
    'order' => 60,
    'nav' => [],
];
