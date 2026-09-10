<?php
/**
 * 壁纸图库（需登录）：管理 frontend/uploads/wallpapers/ 目录下的壁纸
 * 权限：list 所有登录用户；delete 需管理员或编辑者
 * GET  ?action=list    列出全部壁纸 [{url, name, size, mtime, preset}]
 *                      预置目录（uploads/weather 等）的壁纸 preset=true，排在用户上传之后
 * POST action=delete   {url: "/frontend/uploads/wallpapers/xxx.png"} 删除指定壁纸（预置壁纸不可删除）
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/settings.php';

$u = require_login();

$dir = __DIR__ . '/../../frontend/uploads/wallpapers';
$webBase = '/frontend/uploads/wallpapers/';
// 预置壁纸目录（随系统分发，不可删除）：服务器路径 => web 前缀
$presetDirs = [];
foreach (sp_preset_upload_subdirs() as $sub) {
    $presetDirs[__DIR__ . '/../../frontend/uploads/' . $sub] = '/frontend/uploads/' . $sub . '/';
}
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

$action = str_param('action', 'list');

if ($action === 'delete') {
    require_roles('admin', 'editor');
}

/** 扫描单个壁纸目录，返回条目列表（preset 标记是否预置） */
$scan = function ($scanDir, $scanWeb, $preset) use ($allowedExt) {
    $items = [];
    if (is_dir($scanDir)) {
        foreach (scandir($scanDir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) continue;
            $path = $scanDir . '/' . $name;
            if (!is_file($path)) continue;
            $items[] = [
                'url'    => $scanWeb . $name,
                'name'   => $name,
                'size'   => filesize($path),
                'mtime'  => filemtime($path),
                'preset' => $preset,
            ];
        }
    }
    return $items;
};

if ($action === 'list') {
    $items = $scan($dir, $webBase, false);
    // 新上传的排前面
    usort($items, function ($a, $b) {
        return $b['mtime'] <=> $a['mtime'];
    });
    // 预置壁纸追加在后（按文件名排序，顺序稳定）
    foreach ($presetDirs as $pdir => $pweb) {
        $pres = $scan($pdir, $pweb, true);
        usort($pres, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        $items = array_merge($items, $pres);
    }
    ok($items);
}

if ($action === 'delete') {
    $body = json_body();
    $url = isset($body['url']) && is_scalar($body['url']) ? trim((string)$body['url']) : '';
    if ($url === '') fail('缺少壁纸地址');
    // 仅允许删除 wallpapers 目录内的文件（basename 白名单校验，防路径穿越）
    $name = basename(str_replace('\\', '/', $url));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || $name === '.' || $name === '..' || !in_array($ext, $allowedExt, true)) {
        fail('非法的壁纸地址');
    }
    // 预置壁纸（weather 等随系统分发的目录）不可删除
    foreach ($presetDirs as $pdir => $pweb) {
        if (strpos($url, $pweb) === 0) fail('该壁纸为系统预置，不可删除');
    }
    $path = $dir . '/' . $name;
    if (!is_file($path)) fail('壁纸不存在或已删除');
    if (!@unlink($path)) fail('删除失败，请检查目录权限');
    ok(['deleted' => $name]);
}

fail('未知操作');
