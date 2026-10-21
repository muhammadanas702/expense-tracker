<?php
require_once __DIR__ . '/../../includes/schema.php';
/**
 * Categories module. Touches only the app_categories table.
 * Expenses keep the category NAME as text, so turning a category off or deleting it never changes
 * old expenses; it only changes what new expenses can pick. "Other" always stays available.
 */
const CATEGORY_NAME_MAX = 30;
const CATEGORY_MAX = 30;
const CATEGORY_KEEP = 'Other';

/** Icon keys staff can choose (all exist in includes/icons.php). */
function categories_icons(): array
{
    return ['food' => 'Food', 'car' => 'Transport', 'bag' => 'Shopping', 'tag' => 'Tag', 'zap' => 'Bills', 'heart' => 'Health', 'book' => 'Education',
        'gift' => 'Gift', 'home' => 'Home', 'phone' => 'Phone', 'lap' => 'Laptop', 'case' => 'Work', 'mtn' => 'Travel', 'more' => 'Other'];
}

function categories_defaults(): array
{
    return ['Food' => 'food', 'Transport' => 'car', 'Shopping' => 'bag', 'Entertainment' => 'tag', 'Bills' => 'zap', 'Healthcare' => 'heart', 'Education' => 'book', 'Other' => 'more'];
}

function categories_ensure_schema(PDO $conn): void
{
    if (ef_schema_ok(__FUNCTION__, __FILE__)) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `app_categories` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(30) NOT NULL,
              `icon` VARCHAR(8) NOT NULL DEFAULT 'tag',
              `sort_order` INT NOT NULL DEFAULT 0,
              `active` TINYINT(1) NOT NULL DEFAULT 1,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_app_categories_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if ((int) $conn->query('SELECT COUNT(*) FROM app_categories')->fetchColumn() === 0) {
            $ins = $conn->prepare('INSERT IGNORE INTO app_categories (name, icon, sort_order, active) VALUES (?,?,?,1)');
            $i = 0;
            foreach (categories_defaults() as $n => $ic) {
                $ins->execute([$n, $ic, ++$i * 10]);
            }
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow categories schema check failed: ' . $e->getMessage());
    }
}

function categories_all(PDO $conn): array
{
    categories_ensure_schema($conn);
    return $conn->query('SELECT id, name, icon, sort_order, active FROM app_categories ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
}

/** name => icon of the categories new expenses may use. Falls back to the built-in list on any problem. */
function categories_active(PDO $conn): array
{
    try {
        categories_ensure_schema($conn);
        $out = [];
        foreach ($conn->query('SELECT name, icon FROM app_categories WHERE active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['name']] = isset(categories_icons()[$r['icon']]) ? $r['icon'] : 'tag';
        }
        unset($out[CATEGORY_KEEP]);
        $out[CATEGORY_KEEP] = 'more'; // always last and always available
        return count($out) > 1 ? $out : categories_defaults();
    } catch (Throwable $e) {
        return categories_defaults();
    }
}

/** [true|null, error|null] */
function categories_add(PDO $conn, string $name, string $icon): array
{
    categories_ensure_schema($conn);
    $n = clean_text($name, CATEGORY_NAME_MAX);
    if ($n === null) {
        return [null, 'Write a category name (up to ' . CATEGORY_NAME_MAX . ' characters).'];
    }
    if (!isset(categories_icons()[$icon])) {
        $icon = 'tag';
    }
    if ((int) $conn->query('SELECT COUNT(*) FROM app_categories')->fetchColumn() >= CATEGORY_MAX) {
        return [null, 'You can have up to ' . CATEGORY_MAX . ' categories.'];
    }
    $e = $conn->prepare('SELECT 1 FROM app_categories WHERE name = ?');
    $e->execute([$n]);
    if ($e->fetchColumn()) {
        return [null, 'That category already exists.'];
    }
    $max = (int) $conn->query('SELECT COALESCE(MAX(sort_order),0) FROM app_categories')->fetchColumn();
    $conn->prepare('INSERT INTO app_categories (name, icon, sort_order, active) VALUES (?,?,?,1)')->execute([$n, $icon, $max + 10]);
    return [true, null];
}

function categories_get(PDO $conn, int $id): ?array
{
    $s = $conn->prepare('SELECT id, name, icon, sort_order, active FROM app_categories WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function categories_set_active(PDO $conn, int $id, bool $on): void
{
    $conn->prepare('UPDATE app_categories SET active = ? WHERE id = ? AND name <> ?')->execute([$on ? 1 : 0, $id, CATEGORY_KEEP]);
}

/** Moves a category one step up or down in the list. */
function categories_move(PDO $conn, int $id, string $dir): void
{
    $rows = categories_all($conn);
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $i = array_search($id, $ids, true);
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($i === false || $j < 0 || $j >= count($ids)) {
        return;
    }
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $u = $conn->prepare('UPDATE app_categories SET sort_order = ? WHERE id = ?');
    foreach ($ids as $k => $cid) {
        $u->execute([($k + 1) * 10, $cid]);
    }
}

function categories_delete(PDO $conn, int $id): bool
{
    $c = categories_get($conn, $id);
    if (!$c || $c['name'] === CATEGORY_KEEP) {
        return false;
    }
    $conn->prepare('DELETE FROM app_categories WHERE id = ?')->execute([$id]);
    return true;
}

/** How many of a user's own expenses use a category (shown to staff only as a total). */
function categories_usage(PDO $conn): array
{
    try {
        return $conn->query('SELECT category, COUNT(*) AS n FROM expenses GROUP BY category')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        return [];
    }
}
