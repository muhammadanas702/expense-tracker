<?php
// Phone alerts (Web Push): shared bills, settle-ups, reminders and budget alerts arrive as a notification even when the page is closed.
// Nothing else depends on this module; switch it off with 'enabled' => false (the bell list keeps working).
return ['id' => 'push', 'mode' => 'p', 'enabled' => true, 'order' => 96, 'nav' => []];
