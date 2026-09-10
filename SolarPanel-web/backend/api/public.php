<?php
/**
 * 主页公开数据接口：前端展示内容全部由此接口提供
 * GET /backend/api/public.php
 * 返回：settings 全部设置 + groups 分组(含卡片) + user 当前登录用户(可为 null)
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

// 返回数据含当前登录用户信息，禁止中间代理 / CDN 缓存，防止串号
header('Cache-Control: no-store, max-age=0');

try {
    $settings = [];
    foreach (db()->query('SELECT config_name, config_value FROM settings') as $row) {
        $settings[$row['config_name']] = (string)$row['config_value'];
    }

    // 分组显隐：隐藏分组仅登录管理员可见，未登录 / 其他账号一律过滤
    $u = current_user();
    $isAdmin = user_has_role($u, 'admin');
    ensure_group_visible_column();

    if ($isAdmin) {
        $groups = db()->query('SELECT id, title, description, is_visible FROM item_groups ORDER BY sort ASC, id ASC')->fetchAll();
    } else {
        $groups = db()->query('SELECT id, title, description FROM item_groups WHERE is_visible = 1 ORDER BY sort ASC, id ASC')->fetchAll();
    }
    $items  = db()->query(
        'SELECT id, group_id, title, url, lan_url, description, icon_type, icon_value, icon_bg, open_method
         FROM items ORDER BY sort ASC, id ASC'
    )->fetchAll();

    $map = [];
    foreach ($groups as &$g) {
        $g['id'] = (int)$g['id'];
        $g['is_visible'] = (int)($g['is_visible'] ?? 1);
        $g['items'] = [];
        $map[$g['id']] = &$g;
    }
    unset($g);

    foreach ($items as $it) {
        $it['id'] = (int)$it['id'];
        $it['group_id'] = (int)$it['group_id'];
        $it['open_method'] = (int)$it['open_method'];
        if (isset($map[$it['group_id']])) {
            $map[$it['group_id']]['items'][] = $it;
        }
    }

    ok([
        'settings' => $settings,
        'groups'   => $groups,
        'user'     => $u ? ['username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'] ?? 'viewer'] : null,
    ]);
} catch (Throwable $e) {
    // 详细错误（含数据库账号 / 主机 / SQL 结构）只写服务端日志，不返回给访客
    error_log('[SolarPanel] public.php 数据读取失败：' . $e->getMessage());
    fail('数据读取失败，请确认站点已正确安装（访问 /backend/api/install.php），或联系管理员查看服务器日志');
}
