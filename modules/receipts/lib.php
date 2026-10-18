<?php
/**
 * Receipts module helpers: validates, re-encodes and stores one photo per shared expense.
 * Files live in storage/receipts/ (blocked from direct web access) and are served by receipts/view.php
 * only to members of the expense's group. Touches only receipts_files and that folder.
 */
const RECEIPTS_MAX_BYTES = 3145728;   // 3 MB upload
const RECEIPTS_MAX_SIDE = 1600;       // stored picture is scaled down to this
const RECEIPTS_MAX_PIXELS = 36000000; // refuse huge images (memory)

function receipts_dir(): string
{
    return dirname(__DIR__, 2) . '/storage/receipts';
}

function receipts_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `receipts_files` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `group_id` INT UNSIGNED NOT NULL,
              `expense_id` INT UNSIGNED NOT NULL,
              `stored` CHAR(32) NOT NULL,
              `mime` VARCHAR(20) NOT NULL,
              `size_bytes` INT UNSIGNED NOT NULL,
              `created_by` INT UNSIGNED NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_receipts_expense` (`expense_id`),
              KEY `idx_receipts_group` (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow receipts schema check failed: ' . $e->getMessage());
    }
}

/** Creates the storage folder (with a rule that blocks direct downloads) when needed. */
function receipts_ensure_dir(): bool
{
    $dir = receipts_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
    }
    $ix = $dir . '/index.html';
    if (!is_file($ix)) {
        @file_put_contents($ix, '');
    }
    return is_writable($dir);
}

/**
 * Checks an uploaded file from $_FILES without saving it.
 * Returns null when there is no file, true when it is fine, or an error message.
 * @return null|true|string
 */
function receipts_check_upload($file)
{
    if (!is_array($file) || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $err = (int) $file['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return 'The receipt is too big (3 MB at most).';
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return 'The receipt could not be uploaded. Please try again.';
    }
    if ((int) $file['size'] > RECEIPTS_MAX_BYTES) {
        return 'The receipt is too big (3 MB at most).';
    }
    $info = @getimagesize($file['tmp_name']);
    $mime = function_exists('finfo_open') ? (string) @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) : ($info['mime'] ?? '');
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $mime !== $info['mime']) {
        return 'The receipt must be a JPG, PNG or WebP picture.';
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > RECEIPTS_MAX_PIXELS) {
        return 'This picture is too large. Please use a smaller one.';
    }
    if (!receipts_ensure_dir()) {
        return 'Receipts cannot be saved on this server right now.';
    }
    return true;
}

/**
 * Re-draws the picture (drops metadata and anything hidden in the file) as a JPEG of at most 1600 px.
 * Returns [binary, mime]; when the GD library is missing the original, already verified, bytes are kept.
 */
function receipts_normalise(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    if (!function_exists('imagecreatefromstring')) {
        $i = @getimagesize($path);
        return $i ? [$raw, $i['mime']] : null;
    }
    $img = @imagecreatefromstring($raw);
    if (!$img) {
        return null;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, RECEIPTS_MAX_SIDE / max($w, $h));
    if ($scale < 1) {
        $n = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
        imagefill($n, 0, 0, imagecolorallocate($n, 255, 255, 255));
        imagecopyresampled($n, $img, 0, 0, 0, 0, imagesx($n), imagesy($n), $w, $h);
        imagedestroy($img);
        $img = $n;
    } else {
        $n = imagecreatetruecolor($w, $h);
        imagefill($n, 0, 0, imagecolorallocate($n, 255, 255, 255)); // transparent PNG -> white
        imagecopy($n, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        $img = $n;
    }
    ob_start();
    imagejpeg($img, null, 82);
    $out = (string) ob_get_clean();
    imagedestroy($img);
    return $out === '' ? null : [$out, 'image/jpeg'];
}

/** Saves (or replaces) the receipt of an expense from a checked upload. Returns true on success. */
function receipts_save(PDO $conn, int $groupId, int $expenseId, int $userId, array $file): bool
{
    try {
        receipts_ensure_schema($conn);
        $res = receipts_normalise($file['tmp_name']);
        if (!$res || !receipts_ensure_dir()) {
            return false;
        }
        $name = bin2hex(random_bytes(16));
        if (@file_put_contents(receipts_dir() . '/' . $name, $res[0]) === false) {
            return false;
        }
        receipts_remove($conn, $expenseId); // one receipt per expense: replace
        $conn->prepare('INSERT INTO receipts_files (group_id, expense_id, stored, mime, size_bytes, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$groupId, $expenseId, $name, $res[1], strlen($res[0]), $userId]);
        return true;
    } catch (Throwable $e) {
        error_log('ExpenseFlow receipt save failed: ' . $e->getMessage());
        return false;
    }
}

/** Deletes the receipt file and row of an expense (if any). */
function receipts_remove(PDO $conn, int $expenseId): void
{
    try {
        receipts_ensure_schema($conn);
        $q = $conn->prepare('SELECT stored FROM receipts_files WHERE expense_id = ?');
        $q->execute([$expenseId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $stored) {
            if (preg_match('/^[0-9a-f]{32}$/', $stored)) {
                @unlink(receipts_dir() . '/' . $stored);
            }
        }
        $conn->prepare('DELETE FROM receipts_files WHERE expense_id = ?')->execute([$expenseId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow receipt remove failed: ' . $e->getMessage());
    }
}

/** expense_id => receipt id, for the given expenses. */
function receipts_for_expenses(PDO $conn, array $expenseIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $expenseIds))));
    if (!$ids) {
        return [];
    }
    try {
        receipts_ensure_schema($conn);
        return $conn->query('SELECT expense_id, id FROM receipts_files WHERE expense_id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        return [];
    }
}

function receipts_get(PDO $conn, int $id): ?array
{
    receipts_ensure_schema($conn);
    $q = $conn->prepare('SELECT * FROM receipts_files WHERE id = ?');
    $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Cleanup hooks (called by Shared). */
function receipts_on_expense_deleted(PDO $conn, int $expenseId): void
{
    receipts_remove($conn, $expenseId);
}

function receipts_on_group_deleted(PDO $conn, int $groupId): void
{
    try {
        receipts_ensure_schema($conn);
        $q = $conn->prepare('SELECT expense_id FROM receipts_files WHERE group_id = ?');
        $q->execute([$groupId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            receipts_remove($conn, (int) $eid);
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow receipts cleanup failed: ' . $e->getMessage());
    }
}
