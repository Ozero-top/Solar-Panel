<?php
/**
 * 站点设置（读取需登录，前端公开数据由 public.php 提供）
 * GET  ?action=list   全部设置
 * POST action=save    保存 {key1: value1, ...} 仅允许白名单键
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/news_sources.php';

$u = require_login();
$pdo = db();
$action = str_param('action', 'list');

if ($action === 'list') {
    $settings = [];
    foreach ($pdo->query('SELECT config_name, config_value FROM settings') as $row) {
        $settings[$row['config_name']] = (string)$row['config_value'];
    }
    ok($settings);
}

if ($action === 'save') {
    require_roles('admin');
    $body = json_body();
    $allowed = sp_allowed_settings();
    $st = $pdo->prepare(
        'INSERT INTO settings (config_name, config_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
    );

    $saved = 0;
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $body)) continue;
        $value = $body[$key];
        if (!is_scalar($value)) continue;
        $value = trim((string)$value);

        // 按键校验
        if ($key === 'site_title' && mb_strlen($value) > 50) fail('标题过长');
        if ($key === 'mask_opacity') {
            if ($value !== '' && !preg_match('/^(0(\.\d+)?|1(\.0+)?)$/', $value)) fail('遮罩透明度需为 0~1 的数字');
        }
        if ($key === 'announcement_show' && !in_array($value, ['0', '1'], true)) $value = '0';
        if ($key === 'default_lan_mode' && !in_array($value, ['public', 'lan'], true)) $value = 'public';
        if ($key === 'site_url' && $value !== '') {
            if (!preg_match('#^https?://#i', $value)) fail('站点地址需以 http:// 或 https:// 开头');
            if (mb_strlen($value) > 500) fail('站点地址过长');
            $value = rtrim($value, '/');
        }
        if (strpos($key, 'content_') === 0) {
            $n = (int)$value;
            if ((string)$n !== $value) fail('内容区域参数需为整数（像素）');
            if ($key === 'content_maxwidth') {
                if ($n < 300 || $n > 4000) fail('内容区最大宽度需在 300~4000 之间');
            } elseif ($n < 0 || $n > 300) {
                fail('内容区边距需在 0~300 之间');
            }
            $value = (string)$n;
        }
        if ($key === 'wallpaper_blur') {
            $n = (int)$value;
            if ((string)$n !== $value) fail('壁纸模糊需为整数（像素）');
            if ($n < 0 || $n > 30) fail('壁纸模糊需在 0~30 之间');
            $value = (string)$n;
        }
        if ($key === 'clock_show' && !in_array($value, ['0', '1'], true)) $value = '1';
        if ($key === 'default_theme' && !in_array($value, ['light', 'dark', 'system'], true)) $value = 'dark';
        if ($key === 'theme_style' && !in_array($value, ['soft', 'nature', 'natural', 'holo', 'gradient', 'material', 'fabric', 'aurora', 'scandi', 'clay', 'spotlight', 'neumorphism', 'skeuomorphism', 'immersive-photo', 'ghibli', 'fluent'], true)) $value = 'soft';
        if ($key === 'card_style' && !in_array($value, ['detail', 'app'], true)) $value = 'detail';
        if ($key === 'search_engines' && $value !== '') {
            $engines = json_decode($value, true);
            if (!is_array($engines)) fail('搜索引擎数据格式错误');
            $clean = [];
            foreach ($engines as $eng) {
                if (!is_array($eng)) continue;
                $n = isset($eng['name']) && is_scalar($eng['name']) ? trim((string)$eng['name']) : '';
                $l = isset($eng['url']) && is_scalar($eng['url']) ? trim((string)$eng['url']) : '';
                if ($n === '' || $l === '') continue;
                // 协议白名单：拦截 javascript: / data: 等可在搜索跳转时执行的协议
                if (!sp_url_is_http($l)) {
                    fail('搜索引擎「' . mb_substr($n, 0, 20) . '」的地址需以 http:// 或 https:// 开头');
                }
                $clean[] = ['name' => mb_substr($n, 0, 20), 'url' => mb_substr($l, 0, 500)];
            }
            $value = json_encode($clean, JSON_UNESCAPED_UNICODE);
        }
        if ($key === 'home_view' && !in_array($value, ['both', 'nav', 'news'], true)) $value = 'both';
        if ($key === 'news_sources') {
            // 空字符串 = 全部启用；非空必须是合法数据源 id 的 JSON 数组（按内置定义白名单过滤）
            if ($value !== '') {
                $arr = json_decode($value, true);
                if (!is_array($arr)) fail('新闻数据源数据格式错误');
                $known = array_column(news_source_defs(), 'id');
                $clean = [];
                foreach ($arr as $sid) {
                    if (is_string($sid) && in_array($sid, $known, true) && !in_array($sid, $clean, true)) {
                        $clean[] = $sid;
                    }
                }
                $value = json_encode($clean, JSON_UNESCAPED_UNICODE);
            }
        }
        if ($key === 'news_order') {
            // 数据源显示顺序：合法 id 的 JSON 数组；未知 id 过滤、去重，未包含的内置源自动追加到末尾（保证新版本加源后自动出现）
            $arr = $value === '' ? [] : json_decode($value, true);
            if (!is_array($arr)) fail('新闻源顺序数据格式错误');
            $known = array_column(news_source_defs(), 'id');
            $clean = [];
            foreach ($arr as $sid) {
                if (is_string($sid) && in_array($sid, $known, true) && !in_array($sid, $clean, true)) {
                    $clean[] = $sid;
                }
            }
            foreach ($known as $kid) {
                if (!in_array($kid, $clean, true)) $clean[] = $kid;
            }
            $value = json_encode($clean, JSON_UNESCAPED_UNICODE);
        }

        $st->execute([$key, mb_substr($value, 0, 65535)]);
        $saved++;
    }
    ok(['saved' => $saved]);
}

fail('未知操作');
