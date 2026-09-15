<?php
/**
 * 审计日志（仅登录；清空需 admin）
 * GET  ?action=list&page=1&pagesize=50   分页查询
 * POST action=clear                       清空全部（admin）
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

auth_session_start();
require_login();
ensure_audit_logs_table();

$action = str_param('action', 'list');

if ($action === 'list') {
    require_roles('admin');
    $page = max(1, int_param('page', 1));
    $size = max(1, min(200, int_param('pagesize', 50)));
    $type = str_param('type', '');
    $offset = ($page - 1) * $size;

    $where = '1=1';
    $params = [];
    if ($type !== '') {
        $where = 'action = ?';
        $params[] = $type;
    }

    $count = (int)db()->prepare("SELECT COUNT(*) FROM audit_logs WHERE $where")->execute($params) ? (int)db()->prepare("SELECT COUNT(*) FROM audit_logs WHERE $where")->fetchColumn() : 0;
    // 上面那行 PDO::execute 返回 bool 不是 count，简化处理
    $st = db()->prepare("SELECT COUNT(*) FROM audit_logs WHERE $where");
    $st->execute($params);
    $count = (int)$st->fetchColumn();

    $st = db()->prepare("SELECT * FROM audit_logs WHERE $where ORDER BY id DESC LIMIT $size OFFSET $offset");
    $st->execute($params);
    $list = [];
    foreach ($st->fetchAll() as $r) {
        $list[] = [
            'id'         => (int)$r['id'],
            'action'     => $r['action'],
            'target'     => $r['target'],
            'result'     => $r['result'],
            'actor'      => $r['actor'],
            'actor_role' => $r['actor_role'],
            'ip'         => $r['ip'],
            'detail'     => $r['detail'],
            'created_at' => $r['created_at'],
        ];
    }
    ok(['total' => $count, 'list' => $list, 'page' => $page, 'pagesize' => $size]);
}

if ($action === 'clear') {
    require_roles('admin');
    db()->exec('TRUNCATE TABLE audit_logs');
    ok();
}

fail('未知操作');
