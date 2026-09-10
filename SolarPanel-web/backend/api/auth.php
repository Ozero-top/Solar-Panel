<?php
/**
 * 登录认证
 * GET  ?action=me     当前登录用户（未登录返回 data:null）
 * POST action=login   登录 {username, password}
 * POST action=logout  退出登录
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$action = str_param('action', $_SERVER['REQUEST_METHOD'] === 'GET' ? 'me' : '');

if ($action === 'login') {
    auth_session_start();

    // 简单防爆破：同一会话 60 秒内最多尝试 8 次
    $_SESSION['login_try'] = $_SESSION['login_try'] ?? [];
    $_SESSION['login_try'] = array_values(array_filter($_SESSION['login_try'], function ($t) {
        return $t > time() - 60;
    }));
    if (count($_SESSION['login_try']) >= 8) {
        fail('尝试过于频繁，请 1 分钟后再试');
    }

    $username = str_param('username');
    $password = (string)param('password', '');
    if ($username === '' || $password === '') {
        fail('请输入账号和密码');
    }

    ensure_user_role_column();
    $st = db()->prepare('SELECT id, username, name, password, status, role FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $u = $st->fetch();

    if (!$u || (int)$u['status'] !== 1 || !password_verify($password, $u['password'])) {
        $_SESSION['login_try'][] = time();
        fail('账号或密码错误');
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    // 登录成功后轮换 CSRF 令牌（防会话固定：旧令牌可能在登录前被外部获取）
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    setcookie('csrf_token', $_SESSION['csrf_token'], [
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => sp_request_is_https(),
        'httponly' => false,
    ]);
    unset($_SESSION['login_try']);
    ok(['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => normalize_role((string)($u['role'] ?? 'admin'))]);
}

if ($action === 'logout') {
    auth_session_start();
    $_SESSION = [];
    if (session_id() !== '') {
        session_destroy();
    }
    // 登出时清除前端持有的 CSRF 令牌 cookie
    setcookie('csrf_token', '', [
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => sp_request_is_https(),
        'httponly' => false,
        'expires'  => 1,
    ]);
    ok();
}

if ($action === 'me') {
    $u = current_user();
    ok($u ? ['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => normalize_role((string)($u['role'] ?? 'admin'))] : null);
}

fail('未知操作');
