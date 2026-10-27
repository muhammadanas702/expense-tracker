<?php
/**
 * Shared shell for the public auth pages (login, register, forgot password,
 * OTP, new password, delete account). Only markup lives here; each page keeps
 * its own logic.
 *
 *   ef_auth_start(['title' => 'Login', 'split' => true]);   // split = brand panel + form
 *   ... form ...
 *   ef_auth_end();
 */
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/../config/app.php';

require_once __DIR__ . '/assets.php';

function ef_auth_brand(): string
{
    return '<span class="logo"><img src="' . e(ef_url('assets/icons/icon-192.png')) . '" alt=""><span class="wm">ExpenseFlow</span></span>';
}

function ef_check(string $text): string
{
    return '<div class="ck">' . ef_icon('ck') . '<span>' . e($text) . '</span></div>';
}

/** One labelled input with a leading icon (and an eye button for passwords). */
function ef_field(string $label, string $name, string $icon, string $type = 'text', string $value = '', array $attrs = []): string
{
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . e($k) . ($v === true ? '' : '="' . e((string) $v) . '"');
    }
    $isPw = $type === 'password';
    return '<div class="fl"><label for="f_' . e($name) . '">' . e($label) . '</label><div class="fw">' . ef_icon($icon)
        . '<input class="inp' . ($isPw ? ' hasey' : '') . '" id="f_' . e($name) . '" type="' . e($type) . '" name="' . e($name)
        . '" value="' . e($value) . '"' . $extra . '>'
        . ($isPw ? '<button type="button" class="ey" data-eye aria-label="Show or hide password">' . ef_icon('eye') . '</button>' : '')
        . '</div></div>';
}

function ef_msg(string $text, string $type = 'err'): string
{
    if ($text === '') {
        return '';
    }
    $t = in_array($type, ['ok', 'err', 'info'], true) ? $type : 'err';
    return '<div class="msg ' . $t . '" role="alert" style="margin-bottom:14px">' . e($text) . '</div>';
}

function ef_auth_start(array $o = []): void
{
    $split = $o['split'] ?? true;
    send_security_headers();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($o['title'] ?? 'ExpenseFlow') ?> · ExpenseFlow</title>
<meta name="robots" content="noindex">
<?php require __DIR__ . '/pwa-head.php'; ?>
<link rel="stylesheet" href="<?= e(ef_asset('assets/app.css')) ?>">
</head>
<body>
<div class="fr" data-mode="p">
<?php if ($split): ?>
<div class="au">
  <div class="br">
    <div style="position:relative;z-index:1"><?= ef_auth_brand() ?></div>
    <div style="position:relative;z-index:1">
      <h2>Your money,<br>clearly organised.</h2>
      <?= ef_check('Track personal income and expenses') ?>
      <?= ef_check('Reports, insights and PDF export') ?>
      <?= ef_check('Works offline as an installed app') ?>
      <p class="aboutln">ExpenseFlow is a free personal expense tracker and bill splitter, made by <b>Muhammad Anas</b> in Pakistan. It never asks for your bank or card details.</p>
    </div>
    <div></div>
  </div>
  <div class="fm"><div class="g fc">
    <div class="ml"><?= ef_auth_brand() ?></div>
<?php else: ?>
<div class="ctr2"><div class="g fc">
<?php endif;
}

function ef_auth_end(array $o = []): void
{
    $split = $o['split'] ?? true;
    ?>
    <div class="atrust">
      <p>ExpenseFlow is a free expense tracker. We will never ask for your bank or card details, and we never email you asking for your password.</p>
      <p><a href="<?= e(ef_url('index.php')) ?>">Home</a> · <a href="<?= e(ef_url('about.php')) ?>">About</a> · <a href="<?= e(ef_url('privacy.php')) ?>">Privacy</a> · <a href="<?= e(ef_url('terms.php')) ?>">Terms</a> · <a href="<?= e(ef_url('contact.php')) ?>">Contact</a></p>
    </div>
  </div><?= $split ? '</div>' : '' ?>
</div>
</div>
<script src="<?= e(ef_asset('assets/auth-ui.js')) ?>" defer></script>
<script src="<?= e(ef_asset('assets/fx.js')) ?>" defer></script>
</body>
</html>
<?php
}
