<?php
// Friends module: the people you split bills with (anyone who shares a group with you), with
// the balance per person. It only reads Shared helpers and needs no table of its own, so it
// can be switched off without touching anything else.
return [
    'id' => 'friends',
    'mode' => 's',
    'enabled' => true,
    'order' => 15,
    'nav' => [
        ['friends/index.php', 'usr', 'Friends', 'fr'],
    ],
];
