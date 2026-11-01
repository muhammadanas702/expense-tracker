<?php
// Budgets: a monthly limit per expense category, progress bars and alerts at 80% and 100%.
// Personal only calls budgets_* helpers guarded with function_exists(), so this module can be switched off without breaking it.
return [
    'id' => 'budgets',
    'mode' => 'p',
    'enabled' => true,
    'order' => 45,
    'nav' => [['budgets.php', 'tgt', 'Budgets', 'bud']],
];
