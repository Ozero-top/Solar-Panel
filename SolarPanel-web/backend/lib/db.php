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

function ensure_custom_feeds_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `custom_feeds` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `title` VARCHAR(100) NOT NULL,
                `url` VARCHAR(1000) NOT NULL,
                `sort` INT NOT NULL DEFAULT 0,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {}
}

function ensure_audit_logs_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `audit_logs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `action` VARCHAR(50) NOT NULL,
                `target` VARCHAR(255) NOT NULL DEFAULT '',
                `result` VARCHAR(20) NOT NULL DEFAULT 'success',
                `actor` VARCHAR(50) NOT NULL DEFAULT '',
                `actor_role` VARCHAR(20) NOT NULL DEFAULT '',
                `ip` VARCHAR(64) NOT NULL DEFAULT '',
                `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
                `detail` VARCHAR(255) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_action` (`action`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {}
}

function ensure_rate_limits_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `rate_limits` (
                `ip` VARCHAR(64) NOT NULL,
                `endpoint` VARCHAR(50) NOT NULL,
                `count` INT UNSIGNED NOT NULL DEFAULT 0,
                `window_start` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`ip`, `endpoint`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {}
}

/** —— 受信任设备（2FA 勾信任 30 天免二次验证）—— */
function ensure_trusted_devices_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `trusted_devices` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `token_hash` CHAR(64) NOT NULL COMMENT 'SHA-256(token)',
                `device_name` VARCHAR(100) NOT NULL DEFAULT '',
                `ip_snippet` VARCHAR(45) NOT NULL DEFAULT '',
                `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_used_at` DATETIME DEFAULT NULL,
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_hash` (`token_hash`),
                KEY `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {}
}

function ensure_new_settings_keys(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $newKeys = [
        'wallpaper_source'       => '',
        'guest_access_enabled'   => '0',
        'guest_password_hash'    => '',
    ];
    $pdo = db();
    foreach ($newKeys as $k => $v) {
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE config_name = ?');
            $st->execute([$k]);
            if ((int)$st->fetchColumn() === 0) {
                $pdo->prepare('INSERT INTO settings (config_name, config_value) VALUES (?, ?)')->execute([$k, $v]);
            }
        } catch (Throwable $e) {}
    }

    // —— 防御性：settings.config_value 升级成 TEXT
    // （极老版本可能是 VARCHAR(255)，search_engines JSON 约 2.5KB 会被截断）
    try {
        $st = db()->prepare(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'config_value'"
        );
        $st->execute();
        $type = strtolower((string)$st->fetchColumn());
        if ($type !== 'text' && $type !== 'longtext' && $type !== 'mediumtext') {
            db()->exec('ALTER TABLE `settings` MODIFY `config_value` TEXT');
        }
    } catch (Throwable $e) {}
}
