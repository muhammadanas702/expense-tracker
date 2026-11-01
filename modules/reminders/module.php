<?php
// Reminders: bills and payments with a due date (once or every month), "Paid" button, bell notifications.
// Nothing else depends on this module; switch it off with 'enabled' => false.
return [
    'id' => 'reminders',
    'mode' => 'p',
    'enabled' => true,
    'order' => 57,
    'nav' => [['reminders.php', 'bell', 'Reminders', 'rem']],
];
