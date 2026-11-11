<?php
/**
 * Feature switches: staff can turn an option off when it misbehaves (Admin > Features) and on again later.
 * Turning an option off hides it from the menus and blocks its pages. Nobody's data is touched.
 *
 * The list of switched-off options is one small file (storage/features-off.json), not a table, so the switches
 * keep working even when the database is the thing that is broken. Everything here fails open: if the file cannot
 * be read, every option simply stays on.
 */
const FEATURES_FILE = __DIR__ . '/../../storage/features-off.json';

/**
 * id (same as the module folder name) => what staff see on Admin > Features.
 *   section  group heading on the page
 *   label    short name
 *   desc     what it is, in plain words
 *   off      what people notice while it is switched off
 *   pages    pages that are blocked while it is off (a path from the site root; "dir/" blocks every page in that folder)
 *   needs    another option this one cannot work without
 */
function features_catalog(): array
{
    return [
        'budgets' => ['section' => 'Money tools', 'label' => 'Budgets', 'desc' => 'Monthly spending limit for each category, with progress bars and a warning at 80% and 100%.', 'off' => 'The Budgets page, the budget bars on the Dashboard and the budget warnings disappear.', 'pages' => ['budgets.php']],
        'goals' => ['section' => 'Money tools', 'label' => 'Savings goals', 'desc' => 'Savings targets (for example "new phone") with a deadline and progress.', 'off' => 'The Goals page disappears from the menu.', 'pages' => ['goals.php']],
        'reminders' => ['section' => 'Money tools', 'label' => 'Reminders', 'desc' => 'Bills and payments with a due date, a Paid button and bell alerts.', 'off' => 'The Reminders page, the due-soon box on the Dashboard and the reminder alerts disappear.', 'pages' => ['reminders.php']],
        'quickadd' => ['section' => 'Money tools', 'label' => 'Quick add (paste an SMS)', 'desc' => 'Paste a bank or wallet SMS and the Add form is filled in automatically.', 'off' => 'The Quick add button and page disappear. The normal Add forms keep working.', 'pages' => ['quick-add.php', 'quick-add-import.php']],
        'search' => ['section' => 'Money tools', 'label' => 'Search', 'desc' => 'Find old income and expenses by text, tag, category, date or amount.', 'off' => 'The search box on the Dashboard and the Search page disappear.', 'pages' => ['search.php']],
        'gettingstarted' => ['section' => 'Money tools', 'label' => 'Getting-started checklist', 'desc' => 'A short "first steps" checklist on the Dashboard for new accounts.', 'off' => 'New accounts no longer see the checklist.', 'pages' => []],
        'shared' => ['section' => 'Shared (split bills)', 'label' => 'Shared / split bills', 'desc' => 'Groups, split expenses and settle-up. The whole "Shared" side of the app.', 'off' => 'The Shared side disappears completely (Overview, Groups, Add Shared, invite links). Friends, Activity, Comments and Receipts go with it. Old groups and their data stay safe.', 'pages' => ['shared/']],
        'friends' => ['section' => 'Shared (split bills)', 'label' => 'Friends', 'desc' => 'The list of people you split bills with, with the balance for each person.', 'off' => 'The Friends page disappears.', 'pages' => ['friends/'], 'needs' => 'shared'],
        'activity' => ['section' => 'Shared (split bills)', 'label' => 'Activity feed and bell', 'desc' => 'The activity feed ("Ali added Dinner") and the bell with notifications.', 'off' => 'The Activity page and the bell icon disappear.', 'pages' => ['activity/index.php'], 'needs' => 'shared'],
        'comments' => ['section' => 'Shared (split bills)', 'label' => 'Comments on expenses', 'desc' => 'Short comments under a shared expense.', 'off' => 'Comment boxes disappear from shared expenses. Existing comments stay saved.', 'pages' => ['comments/'], 'needs' => 'shared'],
        'receipts' => ['section' => 'Shared (split bills)', 'label' => 'Receipt photos', 'desc' => 'Attach one receipt photo to a shared expense.', 'off' => 'The photo upload and the "view receipt" links disappear. Saved photos stay on the server.', 'pages' => ['receipts/'], 'needs' => 'shared'],
        'oauth' => ['section' => 'Sign-in and help', 'label' => 'Google sign-in', 'desc' => 'The "Continue with Google" button on the Log in and Create account pages.', 'off' => 'The Google button disappears. Everyone can still sign in with e-mail and password.', 'pages' => ['auth/google.php', 'auth/google-app.php', 'auth/google-callback.php', 'auth/google-password.php']],
        'support' => ['section' => 'Sign-in and help', 'label' => 'Help & support (tickets)', 'desc' => 'Users send a question or problem to your team from their Profile; your team answers in Admin → Support.', 'off' => 'The "Help & support" link and page disappear for users. Staff can still read old tickets.', 'pages' => ['support/']],
        'push' => ['section' => 'Sign-in and help', 'label' => 'Phone alerts (push)', 'desc' => 'Notifications on the phone for bills, settle-ups and reminders, even when the app is closed.', 'off' => 'Nobody receives phone alerts and the "turn on alerts" switch disappears. The bell list still works.', 'pages' => ['push-subscribe.php']],
    ];
}

/** Ids switched off by staff (just what was saved; see features_off() for the effective list). Never throws. */
function features_saved_off(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $cache = [];
    $raw = @file_get_contents(FEATURES_FILE);
    $list = $raw ? json_decode($raw, true) : null;
    if (is_array($list)) {
        $cat = features_catalog();
        foreach ($list as $id) {
            if (is_string($id) && isset($cat[$id])) {
                $cache[] = $id;
            }
        }
    }
    return $cache;
}

/** Ids that are effectively off: the ones staff turned off plus the ones that need an option that is off. */
function features_off(): array
{
    $saved = features_saved_off();
    if (!$saved) {
        return [];
    }
    $off = $saved;
    foreach (features_catalog() as $id => $f) {
        if (isset($f['needs']) && in_array($f['needs'], $saved, true)) {
            $off[] = $id;
        }
    }
    return array_values(array_unique($off));
}

/** True while the option is on (also for ids that are not switchable at all). */
function ef_feature_on(string $id): bool
{
    return !in_array($id, features_off(), true);
}

/** Saves one switch. Returns false when the id is unknown or the file cannot be written. */
function features_set(string $id, bool $on): bool
{
    if (!isset(features_catalog()[$id])) {
        return false;
    }
    $off = array_values(array_diff(features_saved_off(true), [$id]));
    if (!$on) {
        $off[] = $id;
    }
    $dir = dirname(FEATURES_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ok = $off === [] ? (!is_file(FEATURES_FILE) || @unlink(FEATURES_FILE)) : (@file_put_contents(FEATURES_FILE, json_encode(array_values($off)), LOCK_EX) !== false);
    features_saved_off(true); // refresh this request's copy
    return $ok;
}

/** The option that owns this request's page, or null. $script is the path from the site root, e.g. "budgets.php". */
function features_for_page(string $script): ?string
{
    $script = ltrim($script, '/');
    foreach (features_catalog() as $id => $f) {
        foreach ($f['pages'] as $p) {
            if ($script === $p || (substr($p, -1) === '/' && strpos($script, $p) === 0)) {
                return $id;
            }
        }
    }
    return null;
}

/** Blocks the page when its option is switched off. Called for every request from config/db.php. Never runs on the command line. */
function features_enforce(): void
{
    if (PHP_SAPI === 'cli' && !defined('EF_FEATURES_TEST')) {
        return;
    }
    try {
        $off = features_off();
        if (!$off) {
            return;
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $root = str_replace('\\', '/', (string) realpath(__DIR__ . '/../..'));
        $file = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $rel = ($root !== '' && strpos($file, $root . '/') === 0) ? substr($file, strlen($root) + 1) : ltrim($script, '/');
        $id = features_for_page($rel);
        if ($id === null || !in_array($id, $off, true)) {
            return;
        }
        $label = features_catalog()[$id]['label'];
        http_response_code(503);
        header('Retry-After: 600');
        header('Cache-Control: no-store');
        $wantsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest' || strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $label . ' is switched off for now.']);
            exit();
        }
        $depth = substr_count($rel, '/');
        $home = str_repeat('../', $depth);
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><link rel="icon" href="' . $h($home) . 'favicon.ico"><title>' . $h($label) . ' is off · ExpenseFlow</title>'
            . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:linear-gradient(135deg,#d9f7ef,#e6eefc 55%,#fde6cf);color:#0b2545;padding:20px}'
            . 'main{max-width:440px;background:rgba(255,255,255,.78);border-radius:22px;padding:30px 26px;box-shadow:0 20px 50px rgba(11,37,69,.12);text-align:center}h1{margin:0 0 10px;font-size:1.35rem;line-height:1.25}p{margin:0 0 20px;color:#4a5b73;line-height:1.5}'
            . 'a{display:inline-block;background:#0f766e;color:#fff;font-weight:600;text-decoration:none;border-radius:12px;padding:11px 20px}</style></head>'
            . '<body><main><h1>' . $h($label) . ' is switched off for now</h1><p>This part of ExpenseFlow is paused while we fix something. Your data is safe. Please try again later.</p><a href="' . $h($home) . 'index.php">Go to home</a></main></body></html>';
        exit();
    } catch (Throwable $e) {
        error_log('ExpenseFlow feature gate failed: ' . $e->getMessage());
    }
}
