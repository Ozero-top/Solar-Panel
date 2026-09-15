<?php
/**
 * 会话鉴权
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            // HTTPS 访问时仅允许加密通道回传会话 Cookie，防中间人窃取会话；
            // 兼容反向代理 SSL 卸载（宝塔/Nginx 转发 X-Forwarded-Proto）。
            // 伪造该头只会影响攻击者自身的请求，不会波及其它用户。
            'secure'   => sp_request_is_https(),
        ]);
        session_start();
    }

    // ===== CSRF 令牌（同步令牌模式）=====
    // 令牌存于服务端 session，通过非 HttpOnly cookie 下发给前端 JS 读取，
    // 前端在所有写请求中以 X-CSRF-Token 头回传，服务端按 session 中的值比对。
    // （cookie 仅作交付通道，真正校验以 session 为准；跨域攻击者无法读取该 cookie，
    //  故无法伪造合法令牌；SameSite=Lax 会话 cookie 已对跨站 POST 构成第二层防护）
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    if (!isset($_COOKIE['csrf_token']) || $_COOKIE['csrf_token'] !== $_SESSION['csrf_token']) {
        setcookie('csrf_token', $_SESSION['csrf_token'], [
            'path'     => '/',
            'samesite' => 'Lax',
            'secure'   => sp_request_is_https(),
            'httponly' => false, // 前端 JS 需通过 document.cookie 读取
        ]);
    }

    // 对所有非安全方法（POST/PUT/DELETE/PATCH）强制校验令牌
    sp_csrf_check();
}

/**
 * CSRF 令牌校验：仅对会改变状态的方法生效；GET/HEAD/OPTIONS 放行。
 * 校验失败返回 403 JSON。
 * 特殊放行：action=login/logout/login_2fa/guest_login —— 登录前没有 CSRF 令牌，
 * 这些敏感操作已经有防爆破限制 + 密码/2FA 双重校验，不再叠加 CSRF。
 */
function sp_csrf_check(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    // 放行的登录类 action（还没有 session）
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
    $skipActions = ['login', 'login_2fa', 'guest_login', 'logout', 'install', 'create', 'register'];
    if ($action !== '' && in_array($action, $skipActions, true)) {
        return;
    }

    $header  = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $session = (string)($_SESSION['csrf_token'] ?? '');
    if ($session === '' || $header === '' || !hash_equals($session, $header)) {
        fail('CSRF 令牌无效或已过期，请刷新页面后重试', 403);
    }
}

/** 当前会话的 CSRF 令牌（供需要在响应体中返回的场景使用） */
function sp_csrf_token(): string
{
    return (string)($_SESSION['csrf_token'] ?? '');
}

/** 当前请求是否经 HTTPS 到达（兼容反向代理 SSL 卸载） */
function sp_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    // 多级代理时取逗号分隔的第一个（最靠近客户端的协议）
    return strpos($proto, 'https') === 0;
}

function current_user(): ?array
{
    auth_session_start();
    if (empty($_SESSION['uid'])) {
        // uid=0 代表 guest 访客用户
        if (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
            static $guest = null;
            if ($guest === null) {
                $guest = ['id' => 0, 'username' => 'guest', 'name' => '访客', 'role' => 'guest', 'status' => 1];
            }
            return $guest;
        }
        return null;
    }
    static $user = null;
    if ($user === null || (int)$user['id'] !== (int)$_SESSION['uid']) {
        ensure_user_role_column();
        try {
            $st = db()->prepare('SELECT id, username, name, status, role FROM users WHERE id = ? LIMIT 1');
            $st->execute([(int)$_SESSION['uid']]);
            $row = $st->fetch();
            $user = $row && (int)$row['status'] === 1 ? $row : null;
        } catch (Throwable $e) {
            $user = null;
        }
    }
    return $user;
}

/**
 * 权限组：admin 全部权限 / editor 内容编辑 / viewer 只读
 * 旧库若无 role 字段则自动补齐（ALTER TABLE），每请求仅检测一次
 */
function ensure_user_role_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $st = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'"
        );
        $st->execute();
        if ((int)$st->fetchColumn() === 0) {
            db()->exec(
                "ALTER TABLE `users` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER `status`"
            );
        }
    } catch (Throwable $e) {
        // 表不存在（未安装）等情况静默跳过
    }
}

/** 当前用户是否拥有指定角色之一 */
function user_has_role(?array $u, string ...$roles): bool
{
    if (!$u) return false;
    $role = $u['role'] ?? 'admin';
    return in_array($role, $roles, true);
}

/** 要求当前用户具有指定角色之一，否则返回 403 JSON（admin 恒通过可显式传入） */
function require_roles(string ...$roles): void
{
    $u = require_login();
    if (!user_has_role($u, ...$roles)) {
        fail('当前账号权限不足（' . role_label($u['role'] ?? 'admin') . '），此操作需要「' . role_label($roles[0]) . '」及以上权限', 403);
    }
}

/** 角色的中文名 */
function role_label(string $role): string
{
    return ['admin' => '管理员', 'editor' => '编辑者', 'viewer' => '只读', 'guest' => '访客（只读）'][$role] ?? $role;
}

/** 角色白名单校验，非法值回退 admin（guest 为访客只读，不回退） */
function normalize_role(string $role): string
{
    return in_array($role, ['admin', 'editor', 'viewer', 'guest'], true) ? $role : 'admin';
}

/** 要求已登录，否则返回 401 JSON */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        fail('未登录或登录已过期', 401);
    }
    return $u;
}


/** users.totp_secret 列：旧库补齐 */
function ensure_totp_secret_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $st = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_secret'"
        );
        $st->execute();
        if ((int)$st->fetchColumn() === 0) {
            db()->exec(
                "ALTER TABLE `users` ADD COLUMN `totp_secret` VARCHAR(64) NOT NULL DEFAULT '' AFTER `role`"
            );
        }
    } catch (Throwable $e) {}
}

/** 反向代理感知真实客户端 IP */
function sp_client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

/** 审计日志写入（静默不抛出，避免审计本身影响主流程） */
function sp_log(string $action, string $target = '', string $result = 'success', string $actor = '', string $actorRole = '', string $detail = ''): void
{
    try {
        require_once __DIR__ . '/db.php';
        ensure_audit_logs_table();
        $ip = sp_client_ip();
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        db()->prepare(
            "INSERT INTO audit_logs (action, target, result, actor, actor_role, ip, user_agent, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$action, mb_substr($target, 0, 255), $result, mb_substr($actor, 0, 50), mb_substr($actorRole, 0, 20), $ip, $ua, mb_substr($detail, 0, 255)]);
    } catch (Throwable $e) { /* 审计静默失败 */ }
}

/**
 * IP 限流（MySQL 表持久化，重启不丢）
 * @return array { ok: bool, remaining: int, retry_after: int }
 */
function sp_rate_check(string $endpoint, int $maxRequests, int $windowSeconds): array
{
    try {
        require_once __DIR__ . '/db.php';
        ensure_rate_limits_table();
        $ip = sp_client_ip();
        $now = time();
        $windowStart = $now - $windowSeconds;

        $pdo = db();
        $st = $pdo->prepare('SELECT count, window_start FROM rate_limits WHERE ip = ? AND endpoint = ?');
        $st->execute([$ip, $endpoint]);
        $row = $st->fetch();

        $count = 0;
        if ($row && (int)$row['window_start'] >= $windowStart) {
            $count = (int)$row['count'];
        }
        if ($count >= $maxRequests) {
            return ['ok' => false, 'remaining' => 0, 'retry_after' => $windowSeconds - ($now - (int)$row['window_start'])];
        }
        // 更新
        $newStart = $row ? (int)$row['window_start'] : $now;
        if ($newStart < $windowStart) $newStart = $now;
        $newCount = $count + 1;
        $pdo->prepare(
            'INSERT INTO rate_limits (ip, endpoint, count, window_start) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE count = ?, window_start = ?'
        )->execute([$ip, $endpoint, $newCount, $newStart, $newCount, $newStart]);

        return ['ok' => true, 'remaining' => $maxRequests - $newCount, 'retry_after' => 0];
    } catch (Throwable $e) {
        // 限流失败时放行（不阻断主业务）
        return ['ok' => true, 'remaining' => $maxRequests, 'retry_after' => 0];
    }
}

/** 429 + Retry-After 响应 */
function sp_rate_limit_respond(array $rate): void
{
    header('Retry-After: ' . max(1, (int)$rate['retry_after']));
    http_response_code(429);
    fail('请求过于频繁，请 ' . max(1, (int)$rate['retry_after']) . ' 秒后再试', 429);
}
