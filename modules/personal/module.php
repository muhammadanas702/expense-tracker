<?php
// Personal module: dashboard, income, expenses, reports.
return [
    'id' => 'personal',
    'mode' => 'p',
    'enabled' => true,
    'nav' => [
        ['dashboard.php', 'home', 'Dashboard', 'dash'],
        ['income/add-income.php', 'inc', 'Add Income', 'inc'],
        ['expense/add-expense.php', 'exp', 'Add Expense', 'exp'],
        ['reports/view-report.php', 'doc', 'Full Report', 'rep'],
    ],
];
