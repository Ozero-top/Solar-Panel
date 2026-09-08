<?php
/**
 * 数据库连接
 */
function db_config(): array
{
    static $conf = null;
    if ($conf === null) {
        $conf = require __DIR__ . '/../config.php';
    }
    return $conf;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = db_config();
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int)$c['port'], $c['dbname'], $c['charset']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** 不选择数据库的连接（安装时用于创建库） */
function db_server(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = db_config();
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], (int)$c['port'], $c['charset']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
    return $pdo;
}

/** 判断是否已安装（users 表存在且有数据） */
function db_installed(): bool
{
    try {
        return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 分组前端显示/隐藏：item_groups 若无 is_visible 字段则自动补齐（ALTER TABLE）
 * 每请求仅检测一次；表不存在（未安装）等情况静默跳过
 */
function ensure_group_visible_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $st = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_groups' AND COLUMN_NAME = 'is_visible'"
        );
        $st->execute();
        if ((int)$st->fetchColumn() === 0) {
            db()->exec(
                "ALTER TABLE `item_groups` ADD COLUMN `is_visible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `sort`"
            );
        }
    } catch (Throwable $e) {
        // 静默跳过
    }
}

/**
 * items / item_groups 的 user_id 字段：仅备份导入的 INSERT 显式引用，
 * 旧库（手工精简建表）若无则自动补齐（ALTER TABLE），与 role / is_visible 同模式。
 * 每请求仅检测一次；表不存在（未安装）等情况静默跳过。
 * 不加 AFTER 定位：避免依赖 is_visible 等后加列已存在，列顺序对功能无影响。
 */
function ensure_user_id_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['items', 'item_groups'] as $table) {
        try {
            $st = db()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'user_id'"
            );
            $st->execute();
            if ((int)$st->fetchColumn() === 0) {
                db()->exec(
                    "ALTER TABLE `{$table}` ADD COLUMN `user_id` INT UNSIGNED NOT NULL DEFAULT 1"
                );
            }
        } catch (Throwable $e) {
            // 静默跳过
        }
    }
}
