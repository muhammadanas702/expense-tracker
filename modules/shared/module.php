<?php
// Shared module: groups, split expenses, settle up. Pages are added part by part.
return [
    'id' => 'shared',
    'mode' => 's',
    'enabled' => true,
    'order' => 10,
    'nav' => [
        ['shared/index.php', 'grid', 'Overview', 'shome'],
        ['shared/groups.php', 'users', 'Groups', 'grp'],
        ['shared/add.php', 'pl', 'Add Shared', 'sadd'],
    ],
];
