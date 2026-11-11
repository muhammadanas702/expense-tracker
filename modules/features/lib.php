<?php
/**
 * Feature switches (Admin > Features). The owner can switch an option off when it is misbehaving and back on later.
 * Stored in a small file (storage/features.json), not the database, so the switches still work when the database is the problem.
 * An "off" feature disappears from menus and its pages show a friendly "switched off for now" message.
 */
const FEATURES_FILE = __DIR__ . '/../../storage/features.json';

/**
 * The switchable features. id = the module id; paths = pages (relative to the site root; a trailing / means a whole folder) that show the
 * "switched off" message; needs = the feature this one cannot work without (switching that one off switches this one off too).
 */
function features_catalog(): array
{
    return [
        'Personal tools' => [
            'budgets' => ['label' => 'Budgets', 'desc' => 'People set a monthly spending limit for a category and see a progress bar. Warns them when they get close.', 'off' => 'The Budgets page and budget warnings disappear.', 'paths' => ['budgets.php']],
            'goals' => ['label' => 'Savings goals', 'desc' => 'People set a target (like "new phone") and track how much they have saved towards it.', 'off' => 'The Goals page disappears.', 'paths' => ['goals.php']],
            'reminders' => ['label' => 'Reminders', 'desc' => 'People note bills and repeating payments and get reminded before they are due.', 'off' => 'The Reminders page and the reminder box on the dashboard disappear.', 'paths' => ['reminders.php']],
            'quickadd' => ['label' => 'Quick add', 'desc' => 'A fast way to add an expense by typing one line, like "lunch 450", or by pasting from a bank message.', 'off' => 'The Quick add page and button disappear. Normal Add Expense still works.', 'paths' => ['quick-add.php', 'quick-add-import.php']],
            'search' => ['label' => 'Search', 'desc' => 'The search box that finds old expenses and income by word, amount or date.', 'off' => 'The search box on the dashboard disappears.', 'paths' => ['search.php']],
            'gettingstarted' => ['label' => 'Getting started tips', 'desc' => 'The small "first steps" checklist new users see on their dashboard.', 'off' => 'The checklist no longer shows.', 'paths' => []],
            'reports' => ['label' => 'Full report and downloads', 'desc' => 'The Full Report page where people see everything in one list and download it as CSV, Excel or PDF.', 'off' => 'The Full Report page, its menu button and the download links disappear. The dashboard still works.', 'paths' => ['reports/']],
            'rates' => ['label' => 'Live exchange rates', 'desc' => 'Fetches fresh currency exchange rates from the internet once in a while. Switch it off if the rates look wrong or the page is slow because the rate service is down.', 'off' => 'The app stops asking the internet and uses the last saved rates. Money amounts still show.', 'paths' => []],
            'push' => ['label' => 'Phone notifications', 'desc' => 'Lets people allow alerts on their phone or browser (for example when a friend adds a shared expense).', 'off' => 'People can no longer switch these alerts on. The in-app bell still works.', 'paths' => ['push-subscribe.php']],
        ],
        'Shared expenses (split bills)' => [
            'shared' => ['label' => 'Groups and split bills', 'desc' => 'The whole Shared side: create a group (trip, flat, office), add shared expenses and see who owes whom. Turns off friends, comments, receipts and activity too, because they live inside groups.', 'off' => 'The Shared side disappears and the app shows only personal tools.', 'paths' => ['shared/']],
            'friends' => ['label' => 'Friends', 'desc' => 'People add each other by e-mail, then split bills with friends easily and see who owes what.', 'off' => 'The Friends page disappears. Groups still work.', 'paths' => ['friends/'], 'needs' => 'shared'],
            'activity' => ['label' => 'Activity and notifications list', 'desc' => 'The bell and the list of what happened in groups (new expense, someone paid, someone joined).', 'off' => 'The bell and the Activity page disappear.', 'paths' => ['activity/'], 'needs' => 'shared'],
            'comments' => ['label' => 'Comments on expenses', 'desc' => 'Group members can write a short comment under a shared expense.', 'off' => 'The comment boxes disappear.', 'paths' => ['comments/'], 'needs' => 'shared'],
            'receipts' => ['label' => 'Receipt photos', 'desc' => 'Group members can attach a photo of the bill to a shared expense.', 'off' => 'Nobody can add or open receipt photos.', 'paths' => ['receipts/'], 'needs' => 'shared'],
        ],
        'Sign-in and accounts' => [
            'signup' => ['label' => 'New sign-ups', 'desc' => 'Lets new people create an account (they confirm with an e-mailed code). Switch it off if e-mail is not working or you do not want new users for now.', 'off' => 'The Create account page says sign-ups are paused. Existing users can still log in.', 'paths' => ['auth/register.php', 'auth/verify-email.php']],
            'oauth' => ['label' => 'Sign in with Google', 'desc' => 'The "Continue with Google" button on the login and create-account pages.', 'off' => 'The Google button disappears. E-mail and password login still works.', 'paths' => ['auth/google.php', 'auth/google-callback.php', 'auth/google-app.php', 'auth/google-app-return.php', 'auth/google-password.php']],
            'twofactor' => ['label' => 'E-mailed login code', 'desc' => 'After the password, some users get a 6-digit code by e-mail. Switch it off if e-mails are not arriving and people cannot log in. It makes sign-in a little less safe while it is off.', 'off' => 'Everyone logs in with just e-mail and password.', 'paths' => []],
        ],
        'Website pages and extras' => [
            'language' => ['label' => 'Urdu language', 'desc' => 'The small "اردو" / "English" switch that lets people read the whole site in Urdu (right to left). Switch it off if the Urdu pages look broken.', 'off' => 'The language switch disappears and everyone sees English.', 'paths' => []],
            'downloads' => ['label' => 'App download buttons', 'desc' => 'The "Download APK" and "Download for Windows" buttons on the home page and in Settings. Switch them off while the new app file is not ready or the download link is broken.', 'off' => 'The Android and Windows download buttons disappear. Using the site in a browser still works.', 'paths' => []],
            'guides' => ['label' => 'Guides (help articles)', 'desc' => 'The public articles such as "Track monthly expenses" and "The 50/30/20 rule". They help people find the site on Google.', 'off' => 'The Guides pages and the "Learn" links in the footer disappear.', 'paths' => ['guides/']],
            'contact' => ['label' => 'Contact page', 'desc' => 'The public "Contact & support" page where visitors who are not signed in can write to you.', 'off' => 'The Contact page and the links to it disappear.', 'paths' => ['contact.php']],
        ],
        'Help and messages' => [
            'support' => ['label' => 'Help and support requests', 'desc' => 'Users can send a question or problem to your team, and you answer it from Admin → Support.', 'off' => 'The users\' Help page closes. Old requests stay safe.', 'paths' => ['support/']],
            'announce' => ['label' => 'Announcements', 'desc' => 'The notice bar you send from Admin → Announcements that users see at the top of the app.', 'off' => 'Users stop seeing announcement bars.', 'paths' => ['announce/']],
        ],
    ];
}

/** id => definition, flat. */
function features_flat(): array
{
    $out = [];
    foreach (features_catalog() as $list) {
        foreach ($list as $id => $f) {
            $out[$id] = $f;
        }
    }
    return $out;
}

/** Ids switched off in the file (not counting "needs"). */
function features_off_list(bool $fresh = false): array
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $j = is_file(FEATURES_FILE) ? json_decode((string) @file_get_contents(FEATURES_FILE), true) : null;
        $all = features_flat();
        $cache = array_values(array_filter(is_array($j['off'] ?? null) ? $j['off'] : [], fn($x) => is_string($x) && isset($all[$x])));
    }
    return $cache;
}

/** Is this feature working right now? Unknown ids (core parts) are always on. */
function ef_feature_on(string $id): bool
{
    $all = features_flat();
    if (!isset($all[$id])) {
        return true;
    }
    $off = features_off_list();
    if (in_array($id, $off, true)) {
        return false;
    }
    $need = $all[$id]['needs'] ?? null;
    return $need === null || ef_feature_on($need);
}

/** Switches one feature on or off. Returns false when the file cannot be written. */
function features_set(string $id, bool $on): bool
{
    if (!isset(features_flat()[$id])) {
        return false;
    }
    $off = features_off_list(true);
    $off = $on ? array_values(array_diff($off, [$id])) : array_values(array_unique(array_merge($off, [$id])));
    return features_write($off);
}

function features_write(array $off): bool
{
    $dir = dirname(FEATURES_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!$off) {
        $ok = !is_file(FEATURES_FILE) || @unlink(FEATURES_FILE);
    } else {
        $ok = @file_put_contents(FEATURES_FILE, json_encode(['off' => array_values($off)]), LOCK_EX) !== false;
    }
    features_off_list(true);
    return $ok;
}

/** Path of the running script relative to the site root ("budgets.php", "shared/index.php"). */
function features_script(): string
{
    $f = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    $root = realpath(__DIR__ . '/../..');
    $real = $f !== '' ? realpath($f) : false;
    if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
        return '';
    }
    return str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
}

/** Which switched-off feature owns this script path, or null. */
function features_blocking(string $script): ?string
{
    if ($script === '' || strpos($script, 'admin/') === 0) {
        return null;
    }
    foreach (features_flat() as $id => $f) {
        if (ef_feature_on($id)) {
            continue;
        }
        foreach ($f['paths'] as $p) {
            if ($script === $p || (substr($p, -1) === '/' && strpos($script, $p) === 0)) {
                return $id;
            }
        }
    }
    return null;
}

/** Called once from includes/security.php: a request for a page of a switched-off feature gets a friendly page. */
function ef_feature_gate(): void
{
    if (PHP_SAPI === 'cli' || !features_off_list()) {
        return;
    }
    $id = features_blocking(features_script());
    if ($id === null) {
        return;
    }
    if (function_exists('ef_error_page')) {
        ef_error_page(503, 'This part is switched off for now', 'This part of ExpenseFlow has been paused by the team while they fix something. Everything else works as usual. Please try again later.');
    }
    http_response_code(503);
    exit('Switched off for now.');
}


/** Does an address (a link target) point at one of the pages of a switched-off feature? Only checks the path part. */
function features_href_blocked(string $href, array $rules): bool
{
    $path = (string) parse_url($href, PHP_URL_PATH);
    if ($path === '' || strpos($path, '/admin/') !== false || strpos($path, 'admin/') === 0) {
        return false;
    }
    $path = '/' . ltrim($path, '/');
    foreach ($rules as $p) {
        $want = '/' . $p;
        if (substr($p, -1) === '/') {
            if (strpos($path, $want) !== false) {
                return true;
            }
        } elseif (substr($path, -strlen($want)) === $want) {
            return true;
        }
    }
    return false;
}

/** Output filter: removes links to pages of switched-off features (menu buttons, footer links, "open the report" links). */
function ef_feature_hide_links(string $html): string
{
    if (defined('EF_ADMIN_PAGE') || stripos($html, '<a ') === false) {
        return $html;
    }
    $rules = [];
    foreach (features_flat() as $id => $f) {
        if (!ef_feature_on($id)) {
            foreach ($f['paths'] as $p) {
                $rules[] = $p;
            }
        }
    }
    if (!$rules) {
        return $html;
    }
    return preg_replace_callback('~<a\b[^>]*?\shref=(["\'])(.*?)\1[^>]*>.*?</a>~is', function ($m) use ($rules) {
        return features_href_blocked(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $rules) ? '' : $m[0];
    }, $html) ?? $html;
}
