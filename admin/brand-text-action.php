<?php
/** Edit-mode answers (JSON): save or clear one text, turn edit mode on or off. Staff with the settings permission only. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../modules/branding/lib.php';

$do = (string) ($_POST['do'] ?? $_GET['do'] ?? '');
$json = function (array $a, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit();
};
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $do === 'token') {
    $json(['ok' => true, 'csrf' => csrf_token()]);
}
require_post_csrf();
$wantsJson = isset($_SERVER['HTTP_X_REQUESTED_WITH']) || ($_POST['json'] ?? '') === '1';

if ($do === 'edit_on' || $do === 'edit_off') {
    if ($do === 'edit_on') {
        $cookie = brand_edit_cookie($admin['id']);
        if ($cookie === null) {
            $_SESSION['error'] = 'Edit mode needs the storage folder to be writable.';
            header('Location: branding.php');
            exit();
        }
        setcookie('ef_be', $cookie, ['expires' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    } else {
        setcookie('ef_be', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
    if ($wantsJson) {
        $json(['ok' => true, 'edit' => $do === 'edit_on']);
    }
    header('Location: ' . ($do === 'edit_on' ? '../index.php?lang=en' : 'branding.php'));
    exit();
}

if ($do === 'reset_all') {
    $n = count(brand_texts(true));
    if ($n) {
        @unlink(BRAND_TEXTS_FILE);
        brand_texts(true);
        admin_audit($conn, $admin['id'], 'brand_change', 'Branding: reset all ' . $n . ' edited texts');
    }
    $_SESSION['success'] = $n ? 'All edited texts were reset.' : 'Nothing changed.';
    header('Location: branding.php');
    exit();
}

if ($do === 'clear') {
    $key = (string) ($_POST['key'] ?? '');
    if (isset(brand_texts(true)[$key])) {
        foreach (['en', 'ur'] as $l) {
            brand_text_set($key, $l, '');
        }
        admin_audit($conn, $admin['id'], 'brand_change', 'Branding: text restored "' . mb_substr($key, 0, 60) . '"');
        $_SESSION['success'] = 'Text restored.';
    }
    header('Location: branding.php');
    exit();
}

if ($do !== 'set') {
    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}
$lang = (($_POST['lang'] ?? '') === 'ur') ? 'ur' : 'en';
$cur = brand_norm((string) ($_POST['cur'] ?? ''));
if ($cur === '' || mb_strlen($cur) > 1000) {
    $json(['ok' => false, 'error' => 'No text selected.'], 422);
}
$key = brand_text_key($cur, $lang);
$err = brand_text_set($key, $lang, (string) ($_POST['new'] ?? ''));
if ($err) {
    $json(['ok' => false, 'error' => $err], 422);
}
admin_audit($conn, $admin['id'], 'brand_change', 'Branding: text "' . mb_substr($key, 0, 60) . '" (' . $lang . ') ' . (isset(brand_texts()[$key][$lang]) ? 'edited' : 'restored'));
$now = brand_texts()[$key][$lang] ?? ($lang === 'ur' ? (ef_ur_dictionary()[$key] ?? $key) : $key);
$json(['ok' => true, 'key' => $key, 'value' => $now, 'custom' => isset(brand_texts()[$key][$lang])]);
