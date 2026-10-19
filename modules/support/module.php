<?php
// Support module: help requests from users and the staff inbox. It has its own tables
// (support_tickets, support_messages) and no sidebar entry; the Profile page links to it.
// Switch it off by setting 'enabled' to false: nothing else depends on it.
return ['id' => 'support', 'mode' => 'p', 'enabled' => true, 'order' => 90, 'nav' => []];
