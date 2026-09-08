<?php
/**
 * 账号管理（需登录）
 * 权限：me 所有登录用户可用；update / change_password 管理员与编辑者可用（只读账号禁止）；
 *       list / create / set_password / delete / set_role 仅管理员。
 * GET  ?action=me                当前用户（含 role）
 * GET  ?action=list              用户列表（管理员）
 * POST action=update             修改自己名称 {name}
 * POST action=change_password    修改自己密码 {old_password, new_password}
 * POST action=create             新增用户 {username, password, name, role=admin|editor|viewer}（管理员）
 * POST action=set_password       重置指定用户密码 {id, new_password}（管理员）
 * POST action=set_role           设置指定用户权限组 {id, role}（管理员）
 * POST action=delete             删除用户 {id}（管理员；不能删除自己，至少保留一个管理员）
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$u = require_login();
$pdo = db();
$action = str_param('action', 'me');

if ($action === 'me') {
    ok(['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'] ?? 'admin']);
}

if ($action === 'list') {
    require_roles('admin');
    $rows = $pdo->query('SELECT id, username, name, status, role, created_at FROM users ORDER BY id ASC')->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['status'] = (int)$r['status'];
        $r['role'] = normalize_role((string)($r['role'] ?? 'admin'));
    }
    unset($r);
    ok(['users' => $rows, 'current_id' => (int)$u['id']]);
}

if ($action === 'create') {
    require_roles('admin');
    $username = str_param('username');
    $password = (string)param('password', '');
    $name = mb_substr(str_param('name'), 0, 50);
    $role = (string)param('role', 'admin');
    if (!in_array($role, ['admin', 'editor', 'viewer'], true)) fail('权限组不合法');
    if ($username === '' || !preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,50}$/u', $username)) {
        fail('用户名需为 2~50 位字母、数字、下划线或中文');
    }
    if (strlen($password) < 6 || strlen($password) > 64) fail('密码至少 6 位，至多 64 位');

    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $st->execute([$username]);
    if ((int)$st->fetchColumn() > 0) fail('用户名已存在');

    $st = $pdo->prepare('INSERT INTO users (username, password, name, status, role) VALUES (?, ?, ?, 1, ?)');
    $st->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name !== '' ? $name : $username, $role]);
    ok(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'set_password') {
    require_roles('admin');
    $id = int_param('id');
    $new = (string)param('new_password', '');
    if ($id <= 0) fail('参数错误');
    if (strlen($new) < 6 || strlen($new) > 64) fail('新密码至少 6 位，至多 64 位');

    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() === 0) fail('用户不存在');

    $st = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $st->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
    ok();
}

if ($action === 'set_role') {
    require_roles('admin');
    $id = int_param('id');
    $role = (string)param('role', 'admin');
    if (!in_array($role, ['admin', 'editor', 'viewer'], true)) fail('权限组不合法');
    if ($id <= 0) fail('参数错误');
    if ($id === (int)$u['id'] && $role !== 'admin') {
        fail('不能修改当前登录账号的权限组');
    }

    $st = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) fail('用户不存在');

    // 至少保留一个管理员
    if (($row['role'] ?? '') === 'admin' && $role !== 'admin') {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($cnt <= 1) fail('至少需要保留一个管理员');
    }

    $st = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
    $st->execute([$role, $id]);
    ok(['role' => $role]);
}

if ($action === 'delete') {
    require_roles('admin');
    $id = int_param('id');
    if ($id <= 0) fail('参数错误');
    if ($id === (int)$u['id']) fail('不能删除当前登录的账号');
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() <= 1) {
        fail('至少需要保留一个用户');
    }
    // 被删用户若是最后一个管理员则阻止
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row && ($row['role'] ?? '') === 'admin') {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($cnt <= 1) fail('至少需要保留一个管理员');
    }
    $st = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $st->execute([$id]);
    ok();
}

if ($action === 'update') {
    // 只读账号无权修改任何自身信息（前端已禁用，后端强拦截）
    if (user_has_role($u, 'viewer')) fail('只读账号无权修改信息', 403);
    $name = mb_substr(str_param('name'), 0, 50);
    $st = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
    $st->execute([$name, (int)$u['id']]);
    ok(['name' => $name]);
}

if ($action === 'change_password') {
    // 只读账号不可修改密码（前端已禁用，后端强拦截）
    if (user_has_role($u, 'viewer')) fail('只读账号无权修改密码', 403);
    $old = (string)param('old_password', '');
    $new = (string)param('new_password', '');
    if ($old === '' || $new === '') fail('请填写完整');
    if (mb_strlen($new) < 6) fail('新密码至少 6 位');
    if (mb_strlen($new) > 64) fail('新密码过长');

    $st = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int)$u['id']]);
    $row = $st->fetch();
    if (!$row || !password_verify($old, $row['password'])) {
        fail('原密码错误');
    }
    $st = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $st->execute([password_hash($new, PASSWORD_DEFAULT), (int)$u['id']]);
    ok();
}

fail('未知操作');
