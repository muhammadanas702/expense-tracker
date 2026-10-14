<?php
/**
 * Module registry.
 *
 * Every feature lives in its own folder under /modules/<name>/ and describes
 * itself in a small module.php manifest. The layout builds the sidebar and the
 * bottom bar only from these manifests, so adding, disabling or changing one
 * module never touches another one.
 *
 * Manifest format (returned array):
 *   'id'      => 'personal'            unique name
 *   'mode'    => 'p' | 's'             p = Personal (teal), s = Shared (blue)
 *   'enabled' => true|false            false hides it everywhere
 *   'nav'     => [[url, icon, label, key], ...]   key marks the active item
 */
function ef_modules(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (glob(__DIR__ . '/../modules/*/module.php') ?: [] as $file) {
        $m = include $file;
        if (is_array($m) && !empty($m['id']) && !empty($m['enabled'])) {
            $cache[$m['id']] = $m;
        }
    }
    return $cache;
}

/** Navigation items for one mode ('p' or 's'). */
function ef_nav(string $mode): array
{
    $items = [];
    foreach (ef_modules() as $m) {
        if (($m['mode'] ?? 'p') === $mode) {
            foreach ($m['nav'] ?? [] as $n) {
                $items[] = $n;
            }
        }
    }
    return $items;
}

function ef_mode_enabled(string $mode): bool
{
    return ef_nav($mode) !== [];
}
