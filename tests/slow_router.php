<?php
// Router for tests/resilience_ui_test.py: behaves like router.php, but the dashboard answers EF_SLOW seconds late (a slow server).
if (getenv('EF_SLOW') && preg_match('#/dashboard\.php#', (string) ($_SERVER['REQUEST_URI'] ?? ''))) {
    sleep((int) getenv('EF_SLOW'));
}
return require __DIR__ . '/../router.php';
