<?php
require_once __DIR__ . '/modules/personal/tx-form.php';
$editId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
personal_tx_page('income', $editId);
