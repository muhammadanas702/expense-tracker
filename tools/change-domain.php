<?php
/**
 * Moves the whole project to a new site address (for example a domain you bought instead of the free expenseflow.rf.gd).
 *   php tools/change-domain.php new.example.com            change the files below
 *   php tools/change-domain.php new.example.com --dry      only list what would change
 *   php tools/change-domain.php new.example.com --from=old.example.com --root=/path   (both optional)
 * The new address must be a bare host name (no https://, no path). After running it: run the tests, deploy, build a new Android app
 * and Windows app (they carry the address), add the new redirect address in the Google sign-in settings. See docs/SAFE_BROWSING.md.
 */
$args = array_slice($argv, 1);
$to = '';
$from = 'expenseflow.rf.gd';
$root = dirname(__DIR__);
$dry = false;
foreach ($args as $a) {
    if ($a === '--dry') {
        $dry = true;
    } elseif (str_starts_with($a, '--from=')) {
        $from = substr($a, 7);
    } elseif (str_starts_with($a, '--root=')) {
        $root = rtrim(substr($a, 7), '/');
    } elseif ($a !== '' && $a[0] !== '-') {
        $to = strtolower($a);
    }
}
$hostRe = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/';
if (!preg_match($hostRe, $to) || !preg_match($hostRe, strtolower($from))) {
    fwrite(STDERR, "Usage: php tools/change-domain.php new.example.com [--dry] [--from=old.host] [--root=dir]\nThe address must be a plain host name such as expenseflow.example.com.\n");
    exit(1);
}
$files = [
    'config/app.php', '.well-known/security.txt', 'sitemap.xml', 'robots.txt', 'README.md', '.htaccess',
    'apps/desktop/main.js', 'apps/android/app/src/main/java/pk/expenseflow/app/MainActivity.java',
    '.github/workflows/release.yml', 'modules/settings/lib.php', 'docs/DEPLOYMENT.md', 'docs/OPERATIONS.md',
];
$changed = 0;
foreach ($files as $f) {
    $p = $root . '/' . $f;
    if (!is_file($p)) {
        continue;
    }
    $src = (string) file_get_contents($p);
    $n = substr_count($src, $from);
    if ($n === 0) {
        continue;
    }
    echo ($dry ? 'would change ' : 'changed ') . "$f ($n)\n";
    $changed += $n;
    if (!$dry) {
        file_put_contents($p, str_replace($from, $to, $src));
    }
}
echo $changed === 0 ? "Nothing to change: '$from' was not found.\n" : "Done: $changed place(s).\n";
