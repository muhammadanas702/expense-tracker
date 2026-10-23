<?php
// Single source for the site icon links (transparent logo). Used by every page layout.
$efFavBase = rtrim((string) ($GLOBALS['base_url'] ?? ''), '/');
if ($efFavBase === '' && function_exists('ef_url')) { $efFavBase = rtrim(ef_url(''), '/'); }
$efFavV = (int) @filemtime(__DIR__ . '/../favicon.ico');
?>
<link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($efFavBase, ENT_QUOTES) ?>/assets/icons/favicon-32.png?v=<?= $efFavV ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($efFavBase, ENT_QUOTES) ?>/assets/icons/favicon-192.png?v=<?= $efFavV ?>">
<link rel="shortcut icon" href="<?= htmlspecialchars($efFavBase, ENT_QUOTES) ?>/favicon.ico?v=<?= $efFavV ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($efFavBase, ENT_QUOTES) ?>/assets/icons/apple-touch-icon.png">
