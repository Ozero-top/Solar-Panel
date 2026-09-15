<?php
/**
 * 登录认证
 * GET  ?action=me                 当前登录用户（含 has_2fa 状态）
 * POST action=login                登录 {username, password} 若启用 2FA 返回 need_2fa + uid
 * POST action=login_2fa            第二步提交 {uid, code} 6 位动态码
 * POST action=logout               退出登录
 * POST action=guest_login          访客密码登录 {password}（60s 8 次防爆破）
 * POST action=2fa_setup            生成密钥（返回 secret + otpauth url）
 * POST action=2fa_enable           启用 {secret, code}
 * POST action=2fa_disable          关闭 {password, code}
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

/* ==================== 受信任设备 helper（必须在 action 分发前定义） ==================== */

/** 验证 trusted_device cookie → 命中则更新 last_used_at 并返回 true */
function sp_trusted_device_verify(int $uid): bool
{
    $raw = (string)($_COOKIE['trusted_device'] ?? '');
    if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/', $raw)) return false;
    $hash = hash('sha256', $raw);
    try {
        $st = db()->prepare('SELECT id FROM trusted_devices WHERE token_hash = ? AND user_id = ? AND expires_at > NOW() LIMIT 1');
        $st->execute([$hash, $uid]);
        $row = $st->fetch();
        if (!$row) return false;
        db()->prepare('UPDATE trusted_devices SET last_used_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 保存受信任设备：生成 token → 存 DB（哈希）→ setcookie */
function sp_trusted_device_save(int $uid): void
{
    // 清已过期的
    db()->prepare('DELETE FROM trusted_devices WHERE user_id = ? AND expires_at < NOW()')->execute([$uid]);
    // 每用户保留上限 10 台，超上限删最早过期的
    $cnt = (int)db()->prepare('SELECT COUNT(*) FROM trusted_devices WHERE user_id = ?')->fetchColumn();
    if ($cnt >= 10) {
        $del = $cnt - 9;
        $st = db()->prepare('SELECT id FROM trusted_devices WHERE user_id = ? ORDER BY expires_at ASC LIMIT ?');
        $st->execute([$uid, $del]);
        while ($row = $st->fetch()) {
            db()->prepare('DELETE FROM trusted_devices WHERE id = ? AND user_id = ?')->execute([(int)$row['id'], $uid]);
        }
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $ip = sp_client_ip();
    $ipShort = $ip;
    if (str_contains($ip, ':')) {
        $parts = explode(':', $ip);
        $ipShort = implode(':', array_slice($parts, 0, 2)) . ':****';
    }
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $deviceName = sp_parse_device_name($ua);
    $expires = (new DateTime('+30 days'))->format('Y-m-d H:i:s');

    db()->prepare(
        'INSERT INTO trusted_devices (user_id, token_hash, device_name, ip_snippet, user_agent, expires_at, last_used_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    )->execute([$uid, $hash, $deviceName, $ipShort, mb_substr($ua, 0, 255), $expires]);

    setcookie('trusted_device', $token, [
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => sp_request_is_https(),
        'expires'  => time() + 86400 * 30,
    ]);
}

/** 从 UA 粗解析可读的设备名 */
function sp_parse_device_name(string $ua): string
{
    if ($ua === '') return '未知设备';
    if (preg_match('/iPhone OS ([\d_]+)/', $ua, $m)) return 'iPhone iOS ' . str_replace('_', '.', $m[1]);
    if (preg_match('/Android ([\d.]+)/', $ua, $m)) return 'Android ' . $m[1];
    if (preg_match('/iPad.*OS ([\d_]+)/', $ua, $m)) return 'iPad iOS ' . str_replace('_', '.', $m[1]);
    if (preg_match('/Windows NT 10\.0/', $ua)) $os = 'Windows 10/11';
    elseif (preg_match('/Windows NT 6\.3/', $ua)) $os = 'Windows 8.1';
    elseif (preg_match('/Mac OS X ([\d_]+)/', $ua, $m)) $os = 'macOS ' . str_replace('_', '.', $m[1]);
    elseif (preg_match('/X11.*Linux/', $ua)) $os = 'Linux';
    else $os = '桌面';
    if (str_contains($ua, 'Chrome') && !str_contains($ua, 'Edg')) $browser = 'Chrome';
    elseif (str_contains($ua, 'Edg/')) $browser = 'Edge';
    elseif (str_contains($ua, 'Firefox')) $browser = 'Firefox';
    elseif (str_contains($ua, 'Safari') && !str_contains($ua, 'Chrome')) $browser = 'Safari';
    else $browser = '浏览器';
    return $browser . ' / ' . $os;
}

$action = str_param('action', $_SERVER['REQUEST_METHOD'] === 'GET' ? 'me' : '');

if ($action === 'me') {
    $u = current_user();
    if (!$u) ok(null);
    ensure_totp_secret_column();
    $has2fa = false;
    try {
        $st = db()->prepare('SELECT totp_secret FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)$u['id']]);
        $has2fa = trim((string)$st->fetchColumn()) !== '';
    } catch (Throwable $e) {}
    ok([
        'id'     => (int)$u['id'],
        'username' => $u['username'],
        'name'     => $u['name'],
        'role'     => normalize_role((string)($u['role'] ?? 'admin')),
        'has_2fa'  => $has2fa,
    ]);
}

if ($action === 'logout') {
    auth_session_start();
    $_SESSION = [];
    if (session_id() !== '') session_destroy();
    setcookie('csrf_token', '', ['path' => '/', 'samesite' => 'Lax', 'secure' => sp_request_is_https(), 'httponly' => false, 'expires' => 1]);
    ok();
}

/* ==================== 主登录流程 ==================== */
if ($action === 'login') {
    auth_session_start();

    // 防爆破（会话级）
    $_SESSION['login_try'] = array_values(array_filter($_SESSION['login_try'] ?? [], fn($t) => $t > time() - 60));
    if (count($_SESSION['login_try']) >= 8) fail('尝试过于频繁，请 1 分钟后再试', 429);

    $username = str_param('username');
    $password = (string)param('password', '');
    if ($username === '' || $password === '') fail('请输入账号和密码');

    ensure_user_role_column();
    ensure_totp_secret_column();
    $st = db()->prepare('SELECT id, username, name, password, status, role, totp_secret FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $u = $st->fetch();

    if (!$u || (int)($u['status'] ?? 0) !== 1 || !password_verify($password, $u['password'])) {
        $_SESSION['login_try'][] = time();
        sp_log('auth.login', $username, 'fail', $username);
        fail('账号或密码错误');
    }

    $has2fa = trim((string)($u['totp_secret'] ?? '')) !== '' && normalize_role((string)($u['role'] ?? 'admin')) === 'admin';

    if ($has2fa) {
        // —— 先查 trusted_device cookie：命中则跳过 2FA 直接登录 ——
        ensure_trusted_devices_table();
        $bypass = sp_trusted_device_verify((int)$u['id']);
        if ($bypass) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            setcookie('csrf_token', $_SESSION['csrf_token'], ['path' => '/', 'samesite' => 'Lax', 'secure' => sp_request_is_https(), 'httponly' => false]);
            unset($_SESSION['login_try']);
            sp_log('auth.login', $u['username'], 'success', $u['username'], normalize_role((string)($u['role'] ?? 'admin')), 'trusted_device_bypass');
            ok(['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => normalize_role((string)($u['role'] ?? 'admin'))]);
        }
        // 密码正确但启用了 2FA：不创建会话，返回 need_2fa 让前端显示动态码输入
        sp_log('auth.login_need_2fa', $username, 'success', $username, normalize_role((string)($u['role'] ?? 'admin')));
        ok(['need_2fa' => true, 'uid' => (int)$u['id']]);
    }

    // 无 2FA 直接登录
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    setcookie('csrf_token', $_SESSION['csrf_token'], ['path' => '/', 'samesite' => 'Lax', 'secure' => sp_request_is_https(), 'httponly' => false]);
    unset($_SESSION['login_try']);
    sp_log('auth.login', $username, 'success', $username, normalize_role((string)($u['role'] ?? 'admin')));
    ok(['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => normalize_role((string)($u['role'] ?? 'admin'))]);
}

/* ==================== 2FA 第二步 ==================== */
if ($action === 'login_2fa') {
    auth_session_start();
    require_once __DIR__ . '/../lib/totp.php';

    // 会话级防爆破
    $_SESSION['login_2fa_try'] = array_values(array_filter($_SESSION['login_2fa_try'] ?? [], fn($t) => $t > time() - 120));
    if (count($_SESSION['login_2fa_try']) >= 5) fail('2FA 尝试过于频繁，请 2 分钟后再试', 429);

    $uid = int_param('uid');
    $code = str_param('code');
    if ($uid <= 0 || !preg_match('/^\d{6}$/', $code)) fail('参数错误');

    ensure_totp_secret_column();
    $st = db()->prepare('SELECT id, username, name, status, role, totp_secret FROM users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $u = $st->fetch();

    if (!$u || (int)$u['status'] !== 1) {
        $_SESSION['login_2fa_try'][] = time();
        fail('账号不存在或已禁用');
    }
    $secret = trim((string)$u['totp_secret']);
    if ($secret === '') {
        $_SESSION['login_2fa_try'][] = time();
        fail('该账号未开启 2FA');
    }

    if (!totp_verify($secret, $code)) {
        $_SESSION['login_2fa_try'][] = time();
        sp_log('auth.login_2fa', $u['username'], 'fail', $u['username']);
        fail('动态码错误，请重试');
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    unset($_SESSION['login_2fa_try']);

    // —— 信任此设备 30 天 ——
    $trust = (int)param('trust_device', 0);
    if ($trust === 1) {
        ensure_trusted_devices_table();
        try {
            sp_trusted_device_save((int)$u['id']);
            sp_log('trusted_device.save', $u['username'], 'success', $u['username'], normalize_role((string)($u['role'] ?? 'admin')));
        } catch (Throwable $e) {
            sp_log('trusted_device.save', $u['username'], 'fail', $u['username'], normalize_role((string)($u['role'] ?? 'admin')), $e->getMessage());
        }
    }

    sp_log('auth.login', $u['username'], 'success', $u['username'], normalize_role((string)($u['role'] ?? 'admin')));
    ok(['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => normalize_role((string)($u['role'] ?? 'admin'))]);
}

/* ==================== 访客密码登录 ==================== */
if ($action === 'guest_login') {
    auth_session_start();

    // 会话级 60s 8 次防爆破
    $_SESSION['guest_try'] = array_values(array_filter($_SESSION['guest_try'] ?? [], fn($t) => $t > time() - 60));
    if (count($_SESSION['guest_try']) >= 8) fail('访客密码尝试过于频繁，请 1 分钟后再试', 429);

    // DB 级限流（public 60/min 已覆盖；这里再加 endpoint 级别：guest_login 30/min）
    $rate = sp_rate_check('guest_login', 30, 60);
    if (!$rate['ok']) sp_rate_limit_respond($rate);

    // 读 settings：访客功能开关 + 密码哈希
    $st = db()->prepare('SELECT config_value FROM settings WHERE config_name = ?');
    $st->execute(['guest_access_enabled']);
    $enabled = (int)$st->fetchColumn();
    $st->execute(['guest_password_hash']);
    $hash = (string)$st->fetchColumn();

    if ($enabled !== 1) fail('访客访问未启用');
    if ($hash === '') fail('管理员尚未设置访客密码');

    $pwd = (string)param('password', '');
    if ($pwd === '') fail('请输入访客密码');

    if (!password_verify($pwd, $hash)) {
        $_SESSION['guest_try'][] = time();
        sp_log('guest.login', '', 'fail', 'guest');
        fail('访客密码错误');
    }

    session_regenerate_id(true);
    // guest 用户用 uid=0 标识，前端会根据 role 显示不同 UI
    $_SESSION['uid'] = 0;
    $_SESSION['is_guest'] = true;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    setcookie('csrf_token', $_SESSION['csrf_token'], ['path' => '/', 'samesite' => 'Lax', 'secure' => sp_request_is_https(), 'httponly' => false]);
    unset($_SESSION['guest_try']);
    sp_log('guest.login', '', 'success', 'guest');
    ok(['id' => 0, 'username' => 'guest', 'name' => '访客', 'role' => 'guest']);
}

/* ==================== 2FA 设置 ==================== */
if ($action === '2fa_setup') {
    $u = require_login();
    if (normalize_role((string)($u['role'] ?? '')) !== 'admin') fail('仅 admin 角色可修改 2FA 设置', 403);
    require_once __DIR__ . '/../lib/totp.php';
    ensure_totp_secret_column();

    $secret = totp_generate_secret();
    $label = $u['username'] . '@SolarPanel';
    $url = totp_provisioning_url($secret, $label);

    // 临时保存到 session（等 enable 提交时校验 code 后再落库）
    $_SESSION['pending_2fa_secret'] = $secret;
    sp_log('2fa.setup', $u['username'], 'success', $u['username'], $u['role'] ?? 'admin');

    ok(['secret' => $secret, 'url' => $url]);
}

if ($action === '2fa_enable') {
    $u = require_login();
    if (normalize_role((string)($u['role'] ?? '')) !== 'admin') fail('仅 admin 角色可修改 2FA 设置', 403);
    require_once __DIR__ . '/../lib/totp.php';
    ensure_totp_secret_column();

    $secret = str_param('secret');
    $code = str_param('code');
    if ($secret === '' || !preg_match('/^\d{6}$/', $code)) fail('参数错误');

    // code 必须和 secret 匹配
    if (!totp_verify($secret, $code)) fail('动态码校验失败，请重新输入');

    // 检查当前账号是否已有 2FA（禁止覆盖，必须先 disable）
    $st = db()->prepare('SELECT totp_secret FROM users WHERE id = ?');
    $st->execute([(int)$u['id']]);
    $exist = trim((string)$st->fetchColumn());
    if ($exist !== '') fail('该账号已开启 2FA，请先关闭再重新设置');

    db()->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')->execute([$secret, (int)$u['id']]);
    unset($_SESSION['pending_2fa_secret']);
    sp_log('2fa.enable', $u['username'], 'success', $u['username'], $u['role'] ?? 'admin');
    ok();
}

if ($action === '2fa_disable') {
    $u = require_login();
    if (normalize_role((string)($u['role'] ?? '')) !== 'admin') fail('仅 admin 角色可修改 2FA 设置', 403);
    require_once __DIR__ . '/../lib/totp.php';
    ensure_totp_secret_column();

    $password = (string)param('password', '');
    $code = str_param('code');
    if ($password === '' || !preg_match('/^\d{6}$/', $code)) fail('请填写密码和 6 位动态码');

    // 密码校验
    $st = db()->prepare('SELECT password, totp_secret FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int)$u['id']]);
    $row = $st->fetch();
    if (!$row || !password_verify($password, $row['password'])) fail('密码错误');

    $secret = trim((string)$row['totp_secret']);
    if ($secret === '') fail('该账号未开启 2FA');

    if (!totp_verify($secret, $code)) fail('动态码错误');

    db()->prepare('UPDATE users SET totp_secret = \'\' WHERE id = ?')->execute([(int)$u['id']]);

    // 关闭 2FA → 清所有受信任设备（没 2FA 了信任设备没意义）
    try {
        ensure_trusted_devices_table();
        db()->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([(int)$u['id']]);
        // 顺带清浏览器 cookie（让这个设备下次也不用带着旧 cookie）
        setcookie('trusted_device', '', ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => sp_request_is_https(), 'expires' => 1]);
    } catch (Throwable $e) {}

    sp_log('2fa.disable', $u['username'], 'success', $u['username'], $u['role'] ?? 'admin');
    ok();
}

/* ==================== 受信任设备（3 个新端点） ==================== */

if ($action === 'list_trusted_devices') {
    $u = require_login();
    ensure_trusted_devices_table();
    // 顺带清过期记录
    try { db()->prepare('DELETE FROM trusted_devices WHERE user_id = ? AND expires_at < NOW()')->execute([(int)$u['id']]); } catch (Throwable $e) {}
    $st = db()->prepare('SELECT id, token_hash, device_name, ip_snippet, user_agent, last_used_at, expires_at FROM trusted_devices WHERE user_id = ? ORDER BY last_used_at DESC, created_at DESC');
    $st->execute([(int)$u['id']]);
    $rows = $st->fetchAll();
    $currentHash = isset($_COOKIE['trusted_device']) ? hash('sha256', $_COOKIE['trusted_device']) : '';
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'device_name'  => $r['device_name'],
            'ip_snippet'   => $r['ip_snippet'],
            'last_used_at' => $r['last_used_at'],
            'expires_at'   => $r['expires_at'],
            'is_current'   => $currentHash !== '' && hash_equals($currentHash, $r['token_hash']),
        ];
    }
    ok($out);
}

if ($action === 'delete_trusted_device') {
    $u = require_login();
    ensure_trusted_devices_table();
    $id = int_param('id');
    if ($id <= 0) fail('参数错误');
    // 先查是不是当前设备
    $currentHash = isset($_COOKIE['trusted_device']) ? hash('sha256', $_COOKIE['trusted_device']) : '';
    $st = db()->prepare('SELECT token_hash FROM trusted_devices WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([$id, (int)$u['id']]);
    $row = $st->fetch();
    if (!$row) fail('设备不存在', 404);
    db()->prepare('DELETE FROM trusted_devices WHERE id = ? AND user_id = ?')->execute([$id, (int)$u['id']]);
    // 删的是当前设备 → 清 cookie
    if ($currentHash !== '' && hash_equals($currentHash, $row['token_hash'])) {
        setcookie('trusted_device', '', ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => sp_request_is_https(), 'expires' => 1]);
    }
    sp_log('trusted_device.delete', $u['username'], 'success', $u['username'], normalize_role((string)($u['role'] ?? 'admin')), "id={$id}");
    ok();
}

if ($action === 'revoke_all_trusted_devices') {
    $u = require_login();
    ensure_trusted_devices_table();
    db()->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([(int)$u['id']]);
    setcookie('trusted_device', '', ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => sp_request_is_https(), 'expires' => 1]);
    sp_log('trusted_device.revoke_all', $u['username'], 'success', $u['username'], normalize_role((string)($u['role'] ?? 'admin')));
    ok();
}

fail('未知操作');
