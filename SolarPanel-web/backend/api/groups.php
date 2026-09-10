<?php
/**
 * 分组管理（需登录）
 * GET  ?action=list    分组列表（含卡片数）
 * POST action=edit     新增/编辑 {id?, title, description}
 * POST action=delete   删除（连同组内卡片）{id}
 * POST action=sort     上移/下移 {id, direction: up|down}
 * POST action=visible  前端显示/隐藏 {id, visible: 0|1}
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/sort.php';

$u = require_login();
$pdo = db();
ensure_group_visible_column();
$action = str_param('action', 'list');

// 写操作需要管理员或编辑者（viewer 只读）
if (in_array($action, ['edit', 'delete', 'sort', 'sort_batch', 'visible'], true)) {
    require_roles('admin', 'editor');
}

if ($action === 'list') {
    $rows = $pdo->query(
        'SELECT g.*, (SELECT COUNT(*) FROM items i WHERE i.group_id = g.id) AS item_count
         FROM item_groups g ORDER BY g.sort ASC, g.id ASC'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['sort'] = (int)$r['sort'];
        $r['is_visible'] = (int)($r['is_visible'] ?? 1);
        $r['item_count'] = (int)$r['item_count'];
    }
    unset($r);
    ok($rows);
}

if ($action === 'visible') {
    $id = int_param('id');
    if ($id <= 0) fail('参数错误');
    $vis = (int)param('visible', 1) ? 1 : 0;
    $st = $pdo->prepare('UPDATE item_groups SET is_visible = ? WHERE id = ?');
    $st->execute([$vis, $id]);
    ok(['id' => $id, 'is_visible' => $vis]);
}

if ($action === 'edit') {
    $id = int_param('id', 0);
    $title = str_param('title');
    if ($title === '') fail('分组名称不能为空');
    if (mb_strlen($title) > 50) fail('分组名称过长');
    $desc = mb_substr(str_param('description'), 0, 1000);

    if ($id > 0) {
        $st = $pdo->prepare('UPDATE item_groups SET title = ?, description = ? WHERE id = ?');
        $st->execute([$title, $desc, $id]);
        ok(['id' => $id]);
    }
    $max = (int)$pdo->query('SELECT COALESCE(MAX(sort), -1) FROM item_groups')->fetchColumn();
    $st = $pdo->prepare('INSERT INTO item_groups (title, description, sort) VALUES (?, ?, ?)');
    $st->execute([$title, $desc, $max + 1]);
    ok(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'delete') {
    $id = int_param('id');
    if ($id <= 0) fail('参数错误');
    $st = $pdo->prepare('DELETE FROM items WHERE group_id = ?');
    $st->execute([$id]);
    $st = $pdo->prepare('DELETE FROM item_groups WHERE id = ?');
    $st->execute([$id]);
    ok();
}

if ($action === 'sort') {
    move_sort($pdo, 'item_groups', int_param('id'), str_param('direction', 'up'));
    ok();
}

if ($action === 'sort_batch') {
    // 拖拽排序保存：{ids:[id,...]}——按提交顺序重写全部分组的 sort
    $batchIds = param('ids');
    if (!is_array($batchIds)) {
        fail('参数错误');
    }
    save_order($pdo, 'item_groups', $batchIds);
    ok();
}

fail('未知操作');
