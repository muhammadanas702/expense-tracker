<?php
// Search: find old income and expenses by text, tag, category, type, date and amount. Read-only: it never changes data.
return [
    'id' => 'search',
    'mode' => 'p',
    'enabled' => true,
    'order' => 58,
    'nav' => [], // no menu entry: the search box lives on the Dashboard (the page search.php is still the full search)
];
