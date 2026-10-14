<?php
// Shared module (groups, split expenses, settle up). Switched on in Part 5.
return [
    'id' => 'shared',
    'mode' => 's',
    'enabled' => false,
    'nav' => [
        ['shared/index.php', 'grid', 'Overview', 'shome'],
        ['shared/groups.php', 'users', 'Groups', 'grp'],
        ['shared/add.php', 'pl', 'Add Shared', 'sadd'],
        ['shared/friends.php', 'usr', 'Friends', 'fr'],
        ['shared/activity.php', 'act', 'Activity', 'act'],
    ],
];
