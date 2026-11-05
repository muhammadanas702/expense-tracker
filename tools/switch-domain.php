<?php
/**
 * Moves the project to another domain in one go (Part 61): the live address in the config, sitemap, robots, security.txt,
 * the Android and Windows shells and the docs links.
 *   php tools/switch-domain.php old.example new.example           (shows what would change)
 *   php tools/switch-domain.php old.example new.example --apply   (writes the change)
 * Google sign-in redirect URIs, DNS and the Search Console property are NOT files: see docs/SAFE_BROWSING.md.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$root = dirname(__DIR__);
foreach ($args as $a) {
    if (str_starts_with($a, '--root=')) {
        $root = rtrim(substr($a, 7), '/');
    }
}
$pos = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$re = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/';
if (count($pos) !== 2 || !preg_match($re, $pos[0]) || !preg_match($re, $pos[1])) {
    fwrite(STDERR, "Usage: php tools/switch-domain.php old.domain new.domain [--apply]\n");
    exit(2);
}
[$old, $new] = $pos;
$files = ['config/app.php', '.well-known/security.txt', 'sitemap.xml', 'robots.txt', 'apps/desktop/main.js',
    'apps/android/app/src/main/java/pk/expenseflow/app/MainActivity.java', 'README.md', '.github/workflows/release.yml',
    'docs/SECURITY.md', 'docs/APPS.md', 'docs/OPERATIONS.md', 'docs/DEPLOYMENT.md', 'docs/ARCHITECTURE.md', 'docs/ONBOARDING.md', 'docs/MICROSOFT_STORE.md'];
$total = 0;
foreach ($files as $f) {
    $p = $root . '/' . $f;
    if (!is_file($p)) {
        continue;
    }
    $s = (string) file_get_contents($p);
    $n = substr_count($s, $old);
    if ($n === 0) {
        continue;
    }
    $total += $n;
    echo ($apply ? 'changed ' : 'would change ') . "$f ($n)\n";
    if ($apply) {
        file_put_contents($p, str_replace($old, $new, $s));
    }
}
echo $total === 0 ? "Nothing to change.\n" : ($apply ? "Done: $total places.\n" : "$total places. Add --apply to write them.\n");
echo "Remember: DNS + the host's domain setting, the Google sign-in redirect URI, Search Console, and a new Android/Windows release.\n";
