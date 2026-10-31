<?php
/**
 * Friendly error pages. Self-contained on purpose (no database, no session, no layout) so it still works when
 * those are the thing that broke. Used by error.php (Apache ErrorDocument), router.php and the global handlers.
 */
if (defined('EF_ERRORS_LOADED')) {
    return;
}
define('EF_ERRORS_LOADED', true);

/** @return array{0:string,1:string} [title, message] for a status code. */
function ef_error_text(int $code): array
{
    return match ($code) {
        400 => ['That request did not look right', 'Please go back, refresh the page and try again.'],
        403 => ['You do not have access to this page', 'If you think this is a mistake, sign in with the right account or contact support.'],
        404 => ['We could not find that page', 'The link may be old or mistyped. Use the buttons below to get back on track.'],
        405 => ['That action is not allowed here', 'Please go back and use the buttons on the page.'],
        429 => ['Too many tries', 'Please wait a few minutes and try again.'],
        503 => ['We will be right back', 'ExpenseFlow is being updated or is very busy. Please try again in a few minutes.'],
        default => ['Something went wrong on our side', 'It is not your fault. Your data is safe. Please try again in a moment, and tell us if it keeps happening.'],
    };
}

/** Sends a complete HTML error page and stops. Safe to call from a shutdown or exception handler. */
function ef_error_page(int $code = 500, ?string $title = null, ?string $message = null): void
{
    [$t, $m] = ef_error_text($code);
    $title = $title ?? $t;
    $message = $message ?? $m;
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
    }
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $home = '/';
    if (!empty($GLOBALS['base_url'])) {
        $home = rtrim((string) $GLOBALS['base_url'], '/') . '/';
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<meta name="theme-color" content="#0B2545"><link rel="icon" href="' . $h($home) . 'favicon.ico"><title>' . $h($title) . ' · ExpenseFlow</title>'
        . '<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:linear-gradient(135deg,#d9f7ef,#e6eefc 55%,#fde6cf);color:#0b2545}'
        . 'main{width:100%;max-width:440px;background:rgba(255,255,255,.82);border-radius:22px;padding:30px 26px;box-shadow:0 20px 50px rgba(11,37,69,.12);text-align:center}'
        . '.c{display:inline-block;font-weight:800;font-size:.8rem;letter-spacing:.12em;color:#0f766e;background:rgba(15,118,110,.1);padding:4px 12px;border-radius:99px;margin-bottom:12px}'
        . 'h1{margin:0 0 10px;font-size:1.35rem;line-height:1.3}p{margin:0 0 20px;color:#4a5b73;line-height:1.55}'
        . '.b{display:inline-block;margin:4px;padding:11px 20px;border-radius:12px;font-weight:700;text-decoration:none;background:#0f766e;color:#fff}.b.o{background:#fff;color:#0b2545;border:1px solid rgba(91,107,128,.35)}</style></head>'
        . '<body><main><span class="c">' . ($code >= 400 ? 'ERROR ' . (int) $code : '') . '</span><h1>' . $h($title) . '</h1><p>' . $h($message) . '</p>'
        . '<a class="b" href="' . $h($home) . '">Go to home</a><a class="b o" href="javascript:history.back()">Go back</a></main></body></html>';
    exit();
}

/** True when the response being built is JSON (then a JSON error is sent instead of a page). */
function ef_error_wants_json(): bool
{
    foreach (headers_list() as $line) {
        if (stripos($line, 'content-type: application/json') === 0) {
            return true;
        }
    }
    return false;
}

/** Installs handlers so an uncaught exception or a PHP fatal error shows a friendly page instead of a blank screen. */
function ef_install_error_handlers(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    @ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $e) {
        error_log('ExpenseFlow uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (ef_error_wants_json()) {
            if (!headers_sent()) {
                http_response_code(500);
            }
            echo json_encode(['state' => 'error', 'message' => 'Something went wrong. Please try again.']);
            exit();
        }
        ef_error_page(500);
    });
    register_shutdown_function(function () {
        $err = error_get_last();
        if (defined('EF_CUSTOM_FATAL')) {
            return; // the page shows its own message
        }
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
            error_log('ExpenseFlow fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
            if (ef_error_wants_json()) {
                http_response_code(500);
                echo json_encode(['state' => 'error', 'message' => 'Something went wrong. Please try again.']);
                return;
            }
            ef_error_page(500);
        }
    });
}
