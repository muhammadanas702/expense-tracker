<?php
// Admin module: role-based staff panel (pages live in /admin). It adds no item to the user
// navigation; the layout shows an "Admin" link to staff only.
return [
    'id' => 'admin',
    'mode' => 'a',
    'enabled' => true,
    'order' => 90,
    'nav' => [],
];
