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
    return '<a class="logo alogo" href="' . e(ef_url('index.php')) . '" aria-label="ExpenseFlow"><img src="' . e(ef_url('assets/icons/logo-96.webp')) . '" alt="" width="36" height="36"><span class="wm">ExpenseFlow</span></a>';
}

function ef_check(string $text): string
{
    return '<div class="ck">' . ef_icon('ck') . '<span>' . e(t($text)) . '</span></div>';
}

/** One labelled input with a leading icon (and an eye button for passwords). */
function ef_field(string $label, string $name, string $icon, string $type = 'text', string $value = '', array $attrs = []): string
{
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . e($k) . ($v === true ? '' : '="' . e($k === 'placeholder' ? t((string) $v) : (string) $v) . '"');
    }
    $isPw = $type === 'password';
    return '<div class="fl"><label for="f_' . e($name) . '">' . e(t($label)) . '</label><div class="fw">' . ef_icon($icon)
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
    return '<div class="msg ' . $t . '" role="alert" style="margin-bottom:14px">' . e(t($text)) . '</div>';
}

function ef_auth_start(array $o = []): void
{
    $split = $o['split'] ?? true;
    send_security_headers();
    ?><!DOCTYPE html>
<html<?= ef_html_attrs() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($o['title'] ?? 'ExpenseFlow') ?> · ExpenseFlow</title>
<meta name="robots" content="noindex">
<?php require __DIR__ . '/pwa-head.php'; ?>
<link rel="stylesheet" href="<?= e(ef_asset('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(ef_asset('assets/auth-site.css')) ?>">
<?php require_once __DIR__ . '/theme.php'; echo ef_theme_head(); ?>
</head>
<body>
<?= ef_lang_switch('lang-sw au-lang') ?>
<div class="fr" data-mode="p">
<?php if ($split):
    $mode = $o['mode'] ?? '';
    $reg = $mode === 'register' || ($o['side'] ?? '') === 'right'; // register side: form on the right; everything else (login, forgot password, codes): form on the left
?>
<div class="au<?= $reg ? '' : ' au-rev' ?>">
  <div class="br">
    <div style="position:relative;z-index:1"><?= ef_auth_brand() ?></div>
    <div style="position:relative;z-index:1">
<?php if ($mode === 'recover'): ?>
      <h2>Locked out?<br><span class="agr">Back in a minute.</span></h2>
      <?= ef_check('We email you a 6-digit code') ?>
      <?= ef_check('The code works once and expires soon') ?>
      <?= ef_check('We never email you asking for your password') ?>
<?php elseif ($reg): ?>
      <h2>Start in a minute.<br><span class="agr">Stay on top for good.</span></h2>
      <?= ef_check('Free, with no ads and no card needed') ?>
      <?= ef_check('Personal money and shared bills in one app') ?>
      <?= ef_check('Works offline as an installed app') ?>
<?php else: ?>
      <h2>Your money,<br><span class="agr">clearly organised.</span></h2>
      <?= ef_check('Track personal income and expenses') ?>
      <?= ef_check('Reports, insights and PDF export') ?>
      <?= ef_check('Works offline as an installed app') ?>
<?php endif; ?>
      <p class="aboutln">ExpenseFlow is a free personal expense tracker and bill splitter, made by <b>Muhammad Anas</b> in Pakistan. It never asks for your bank or card details.</p>
    </div>
    <div class="brp" aria-hidden="true"><div class="brph"><img src="<?= e(ef_url($reg ? 'assets/landing/app-group.webp' : 'assets/landing/app-dashboard.webp')) ?>" width="480" height="960" alt="" decoding="async"></div></div>
  </div>
  <div class="fm"><div class="g fc">
    <div class="ml"><?= ef_auth_brand() ?></div>
<?php if ($mode === 'login' || $mode === 'register'): ?>
    <nav class="atab<?= $reg ? ' r' : '' ?>" aria-label="<?= e(t('Account')) ?>"><a href="<?= e(ef_url('auth/login.php')) ?>"<?= $reg ? '' : ' class="on" aria-current="page"' ?>><?= e(t('Log in')) ?></a><a href="<?= e(ef_url('auth/register.php')) ?>"<?= $reg ? ' class="on" aria-current="page"' : '' ?>><?= e(t('Create account')) ?></a><i aria-hidden="true"></i></nav>
<?php endif; ?>
<?php else: ?>
<div class="ctr2"><div class="g fc">
<?php endif;
}

function ef_auth_end(array $o = []): void
{
    $split = $o['split'] ?? true;
    ?>
    <div class="atrust">
      <p><?= e(t('ExpenseFlow is a free expense tracker. We will never ask for your bank or card details, and we never email you asking for your password.')) ?></p>
      <p><a href="<?= e(ef_url('index.php')) ?>"><?= e(t('Home')) ?></a> · <a href="<?= e(ef_url('about.php')) ?>"><?= e(t('About')) ?></a> · <a href="<?= e(ef_url('privacy.php')) ?>"><?= e(t('Privacy')) ?></a> · <a href="<?= e(ef_url('terms.php')) ?>"><?= e(t('Terms')) ?></a> · <a href="<?= e(ef_url('contact.php')) ?>"><?= e(t('Contact')) ?></a></p>
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
