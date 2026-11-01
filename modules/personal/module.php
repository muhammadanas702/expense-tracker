<?php
// Personal module: dashboard, income, expenses, reports.
return [
    'id' => 'personal',
    'mode' => 'p',
    'enabled' => true,
    'nav' => [
        ['dashboard.php', 'home', 'Dashboard', 'dash', true],
        ['income/add-income.php', 'inc', 'Add Income', 'inc', true],
        ['expense/add-expense.php', 'exp', 'Add Expense', 'exp', true],
        ['reports/view-report.php', 'doc', 'Full Report', 'rep', true],
    ],
];
