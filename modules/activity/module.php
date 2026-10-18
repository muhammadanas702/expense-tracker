<?php
// Activity module: activity feed and notifications for Shared groups. Other modules only call
// activity_record() (guarded with function_exists), so this module can be switched off safely.
return [
    'id' => 'activity',
    'mode' => 's',
    'enabled' => true,
    'order' => 20,
    'nav' => [
        ['activity/index.php', 'act', 'Activity', 'act'],
    ],
];
