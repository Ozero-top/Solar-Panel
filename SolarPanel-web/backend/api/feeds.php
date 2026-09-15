<?php
/**
 * 自定义 RSS / Atom 源（登录后可用；viewer 只读 list）
 * GET  ?action=list   全部源（按 sort）
 * POST action=save    新增或更新 {id?, title, url, sort?, enabled?}
 * POST action=toggle  切换启用 {id}
 * POST action=delete  删除 {id}
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/security.php';

auth_session_start();
$u = require_login();
$pdo = db();
ensure_custom_feeds_table();

$action = str_param('action', 'list');

if ($action === 'list') {
    $rows = $pdo->query('SELECT id, title, url, sort, enabled, created_at FROM custom_feeds ORDER BY sort ASC, id ASC')->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['sort'] = (int)$r['sort'];
        $r['enabled'] = (int)$r['enabled'];
    }
    unset($r);
    ok($rows);
}

if ($action === 'save') {
    if (user_has_role($u, 'viewer')) fail('只读账号不可新增 RSS 源', 403);
    $body = json_body();
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $title = trim((string)($body['title'] ?? ''));
    $url = trim((string)($body['url'] ?? ''));
    $sort = isset($body['sort']) ? (int)$body['sort'] : 0;
    $enabled = isset($body['enabled']) ? ((int)$body['enabled'] === 1 ? 1 : 0) : 1;

    if ($title === '' || mb_strlen($title) > 100) fail('标题不能为空且不超过 100 字');
    if ($url === '' || mb_strlen($url) > 1000) fail('URL 不能为空且不超过 1000 字符');
    if (!preg_match('#^https?://#i', $url)) fail('URL 必须以 http:// 或 https:// 开头');

    // —— SSRF 防护：保存时立即校验主机为可达公网地址
    if (!sp_url_is_public($url)) fail('URL 指向内网/保留地址，已拦截（SSRF 防护）');

    if ($id > 0) {
        $st = $pdo->prepare('UPDATE custom_feeds SET title = ?, url = ?, sort = ?, enabled = ? WHERE id = ?');
        $st->execute([$title, $url, $sort, $enabled, $id]);
        sp_log('feeds.update', $title, 'success', $u['username'] ?? '', $u['role'] ?? 'admin');
        ok(['id' => $id]);
    } else {
        $st = $pdo->prepare('INSERT INTO custom_feeds (title, url, sort, enabled) VALUES (?, ?, ?, ?)');
        $st->execute([$title, $url, $sort, $enabled]);
        sp_log('feeds.create', $title, 'success', $u['username'] ?? '', $u['role'] ?? 'admin');
        ok(['id' => (int)$pdo->lastInsertId()]);
    }
}

if ($action === 'toggle') {
    if (user_has_role($u, 'viewer')) fail('只读账号不可修改', 403);
    $id = int_param('id');
    $st = $pdo->prepare('SELECT enabled, title FROM custom_feeds WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) fail('源不存在');
    $newEnabled = ((int)$row['enabled'] === 1) ? 0 : 1;
    $pdo->prepare('UPDATE custom_feeds SET enabled = ? WHERE id = ?')->execute([$newEnabled, $id]);
    sp_log('feeds.toggle', $row['title'], 'success', $u['username'] ?? '', $u['role'] ?? 'admin', $newEnabled ? 'on' : 'off');
    ok(['enabled' => $newEnabled]);
}

if ($action === 'delete') {
    if (user_has_role($u, 'viewer')) fail('只读账号不可删除', 403);
    $id = int_param('id');
    $st = $pdo->prepare('SELECT title FROM custom_feeds WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) fail('源不存在');
    $pdo->prepare('DELETE FROM custom_feeds WHERE id = ?')->execute([$id]);
    sp_log('feeds.delete', $row['title'], 'success', $u['username'] ?? '', $u['role'] ?? 'admin');
    ok();
}

fail('未知操作');
