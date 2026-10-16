<?php
/** URL helpers shared by every page shell (signed-in layout and auth layout). */
if (!function_exists('ef_url')) {
    function ef_url(string $path): string
    {
        global $base_url;
        return rtrim((string) ($base_url ?? ''), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('ef_asset')) {
    /** URL of a static file with its last-change time, so browsers fetch a new copy after every deploy. */
    function ef_asset(string $path): string
    {
        $file = __DIR__ . '/../' . ltrim($path, '/');
        $v = is_file($file) ? (int) filemtime($file) : 0;
        return ef_url($path) . ($v ? '?v=' . $v : '');
    }
}
