<?php
/**
 * 备份与恢复（仅管理员）
 * GET  ?action=export   导出全部配置数据 + uploads 全部文件（JSON，base64 内嵌文件）
 * POST ?action=import   导入备份（multipart: file=<备份.json>）
 *                       ⚠ 会清空现有分组 / 卡片 / 站点设置和 uploads 用户文件后恢复
 *                         （weather 等随系统分发的预置资源目录保留），用户账号不受影响。
 *                         导入先落临时目录 + 事务，失败不破坏现有数据。
 * POST ?action=reset    恢复初始状态：清空分组 / 卡片 / 设置 / uploads 用户文件，
 *                       写回默认设置 + 示例分组卡片，预置资源目录保留，用户账号不受影响。
 *
 * 导出结构：
 * {
 *   app: "SolarPanel", version: "...", exported_at: "...",
 *   data: { settings: [...], groups: [...], items: [...] },
 *   files: [ { path: "icons/xxx.png", mime: "...", size: n, data_base64: "..." } ]
 * }
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/settings.php';

// 防止 PHP 通知 / 警告混入导出文件或 JSON 响应
ini_set('display_errors', '0');

// 备份格式版本（与 api.js 的 APP_VERSION 同步维护）
const BACKUP_APP_VERSION = 'v1.0.001';

require_roles('admin');

$pdo = db();
// 旧库自动迁移：导入 INSERT 显式引用 is_visible / user_id，先确保列存在（缺失则 ALTER 补齐）
ensure_group_visible_column();
ensure_user_id_columns();
$action = str_param('action', '');

$uploadDir = realpath(__DIR__ . '/../../frontend/uploads') ?: (__DIR__ . '/../../frontend/uploads');
$tmpSuffix = '__restore_tmp';

/** 允许打包 / 恢复的文件扩展名白名单（图标 / Logo / 壁纸 / favicon / 天气图标缓存） */
function backup_allowed_ext(string $path): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'svg'], true);
}

/** 校验备份内的相对路径：禁止穿越、禁止绝对路径，返回规范化的相对路径；非法返回 null */
function backup_safe_relpath(string $path): ?string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || $path[0] === '/' || strpos($path, ':') !== false) return null;
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') return null;
        $parts[] = $seg;
    }
    if (!$parts) return null;
    return implode('/', $parts);
}

/**
 * 递归清空 uploads 目录内容（保留目录本身，以及 weather 等随系统分发的预置资源子目录），
 * 返回删除的文件数。预置壁纸等目录用户无法重新获取，备份恢复 / 恢复初始状态时必须保留。
 */
function backup_clear_uploads(string $uploadDir): int
{
    $cleared = 0;
    if (!is_dir($uploadDir)) return 0;
    $base = rtrim(str_replace('\\', '/', $uploadDir), '/');
    $preset = sp_preset_upload_subdirs();
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($base)), '/');
        $top = explode('/', $rel)[0] ?? '';
        if ($top !== '' && in_array($top, $preset, true)) continue; // 预置目录整体保留
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        } elseif (@unlink($f->getPathname())) {
            $cleared++;
        }
    }
    return $cleared;
}

/* ================= 导出 ================= */
if ($action === 'export') {
    if (is_dir($uploadDir)) $uploadDir = rtrim($uploadDir, '/\\');

    // 显式列名导出（不用 SELECT *：避免未来加列后备份结构漂移，created_at 等运行时元数据不入库备份）
    $settings = $pdo->query('SELECT config_name, config_value FROM settings ORDER BY id ASC')->fetchAll();
    $groups   = $pdo->query('SELECT id, title, description, sort, is_visible, user_id FROM item_groups ORDER BY id ASC')->fetchAll();
    $items    = $pdo->query('SELECT id, group_id, title, url, lan_url, description, icon_type, icon_value, icon_bg, open_method, sort, user_id FROM items ORDER BY id ASC')->fetchAll();

    $filename = 'solarpanel-backup-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    while (ob_get_level() > 0) { @ob_end_clean(); }

    // 流式输出：避免大体积文件（壁纸）一次性载入内存
    echo '{"app":"SolarPanel","version":' . json_encode(BACKUP_APP_VERSION)
        . ',"exported_at":' . json_encode(date('Y-m-d H:i:s'))
        . ',"data":' . json_encode(['settings' => $settings, 'groups' => $groups, 'items' => $items], JSON_UNESCAPED_UNICODE)
        . ',"files":[';

    $first = true;
    if (is_dir($uploadDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) continue;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($uploadDir) + 1));
            if (!backup_safe_relpath($rel) || !backup_allowed_ext($rel)) continue;
            $size = $file->getSize();
            if ($size <= 0 || $size > 20 * 1024 * 1024) continue; // 单文件超限跳过

            $ext = strtolower($file->getExtension());
            $mimeMap = [
                'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
                'bmp' => 'image/bmp', 'svg' => 'image/svg+xml',
            ];
            $meta = [
                'path' => $rel,
                'mime' => $mimeMap[$ext] ?? 'application/octet-stream',
                'size' => $size,
            ];
            // 截掉 meta JSON 收尾的 "}"，让 data_base64 与 path/mime/size 同处一个对象
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            echo ($first ? '' : ',') . substr($metaJson, 0, -1) . ',"data_base64":"';
            $first = false;

            // 按 3 字节倍数分块读文件做 base64，保证块间可无缝拼接且内存占用恒定
            $fp = fopen($file->getPathname(), 'rb');
            if ($fp) {
                while (!feof($fp)) {
                    echo base64_encode((string)fread($fp, 3 * 1024 * 1024));
                }
                fclose($fp);
            }
            echo '"}';
        }
    }
    echo ']}';
    exit;
}

/* ================= 导入 ================= */
if ($action === 'import') {
    // 大备份解析需要更多内存
    @ini_set('memory_limit', '512M');

    if (empty($_FILES['file'])) {
        // 请求体超过 post_max_size 时，$_POST / $_FILES 都不会被填充
        fail('未收到上传文件：备份可能超过 PHP 的 post_max_size 限制，请在面板 / php.ini 调大 upload_max_filesize 和 post_max_size（建议 ≥ 备份体积）');
    }
    $upErr = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($upErr !== UPLOAD_ERR_OK) {
        fail($upErr === UPLOAD_ERR_INI_SIZE || $upErr === UPLOAD_ERR_FORM_SIZE
            ? '备份文件超过 PHP 上传限制（upload_max_filesize），请在面板 / php.ini 调大后重试'
            : '文件上传失败（PHP 错误码 ' . $upErr . '）');
    }
    if (!is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
        fail('请选择备份文件（.json）');
    }
    if ($_FILES['file']['size'] > 256 * 1024 * 1024) {
        fail('备份文件过大（超过 256MB）');
    }

    $raw = file_get_contents($_FILES['file']['tmp_name']);
    $bak = json_decode((string)$raw, true);
    if (!is_array($bak) || ($bak['app'] ?? '') !== 'SolarPanel' || !isset($bak['data']) || !is_array($bak['data'])) {
        fail('不是有效的 SolarPanel 备份文件（或文件过大导致解析失败）');
    }
    $data = $bak['data'];
    $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
    $groups   = is_array($data['groups']   ?? null) ? $data['groups']   : [];
    $items    = is_array($data['items']    ?? null) ? $data['items']    : [];
    $files    = is_array($bak['files']     ?? null) ? $bak['files']     : [];

    /* ---- 第 1 步：把备份文件解到临时目录（不动现有 uploads）---- */
    $tmpDir = dirname($uploadDir) . '/' . basename($uploadDir) . $tmpSuffix;
    if (is_dir($tmpDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($tmpDir);
    }
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true)) {
        fail('无法创建临时目录，请检查目录写入权限');
    }

    $restoredFiles = 0;
    foreach ($files as $f) {
        if (!is_array($f)) continue;
        $rel = backup_safe_relpath((string)($f['path'] ?? ''));
        if ($rel === null || !backup_allowed_ext($rel)) continue; // 非法路径 / 非白名单扩展直接跳过
        $b64 = (string)($f['data_base64'] ?? '');
        if ($b64 === '' || strlen($b64) > 30 * 1024 * 1024) continue;
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') continue;
        // SVG 还原前净化（备份文件可能被分享 / 篡改，防存储型 XSS）
        if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'svg') {
            $clean = sp_sanitize_svg($bin);
            if ($clean === null) continue;
            $bin = $clean;
        }

        $target = $tmpDir . '/' . $rel;
        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) continue;
        if (@file_put_contents($target, $bin) !== false) {
            $restoredFiles++;
        }
    }

    /* ---- 第 2 步：事务清空并写入数据库（users 表不受影响）---- */
    $cntSettings = $cntGroups = $cntItems = $orphanItems = 0;
    try {
        $pdo->beginTransaction();

        $pdo->exec('DELETE FROM items');
        $pdo->exec('DELETE FROM item_groups');
        $pdo->exec('DELETE FROM settings');

        // 设置（仅恢复白名单内的键，防止篡改的备份注入未知配置）
        $allowedSettings = array_flip(sp_allowed_settings());
        $stS = $pdo->prepare('INSERT INTO settings (config_name, config_value) VALUES (?, ?)');
        $cntSettings = 0;
        foreach ($settings as $r) {
            if (!is_array($r) || !isset($r['config_name'])) continue;
            $name = (string)$r['config_name'];
            if ($name === '' || strlen($name) > 50 || !isset($allowedSettings[$name])) continue;
            $val = is_scalar($r['config_value'] ?? '') ? (string)$r['config_value'] : '';
            $stS->execute([$name, $val]);
            $cntSettings++;
        }

        // 分组（保留原 id，卡片 group_id 引用才一致；is_visible 控制前端显隐，必须还原）
        $stG = $pdo->prepare('INSERT INTO item_groups (id, title, description, sort, is_visible, user_id) VALUES (?, ?, ?, ?, ?, ?)');
        $groupIds = [];
        $cntGroups = 0;
        foreach ($groups as $g) {
            if (!is_array($g) || !isset($g['id'], $g['title'])) continue;
            $vis = isset($g['is_visible']) ? (int)$g['is_visible'] : 1;
            if ($vis !== 0 && $vis !== 1) $vis = 1;
            $stG->execute([
                (int)$g['id'],
                mb_substr((string)$g['title'], 0, 50),
                mb_substr((string)($g['description'] ?? ''), 0, 1000),
                (int)($g['sort'] ?? 0),
                $vis,
                (int)($g['user_id'] ?? 1),
            ]);
            $groupIds[(int)$g['id']] = true;
            $cntGroups++;
        }

        // 卡片
        $stI = $pdo->prepare(
            'INSERT INTO items (id, group_id, title, url, lan_url, description, icon_type, icon_value, icon_bg, open_method, sort, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        // URL 协议白名单清洗：备份可能被分享 / 篡改，非法协议（javascript: 等）直接置空
        $cleanUrl = static function ($v) {
            $v = mb_substr((string)($v ?? ''), 0, 1000);
            return ($v === '' || sp_url_is_http($v)) ? $v : '';
        };
        $cntItems = 0;
        $orphanItems = 0;
        foreach ($items as $i) {
            if (!is_array($i) || !isset($i['id'], $i['group_id'], $i['title'])) continue;
            // 孤儿卡片防护：group_id 必须指向本次导入的分组（items 表无外键约束，损坏 / 篡改备份可能引用不存在的分组）
            if (!isset($groupIds[(int)$i['group_id']])) { $orphanItems++; continue; }
            $iconType = (string)($i['icon_type'] ?? 'image');
            // 与 items.php 保存端白名单保持一致（image/text/favicon）；旧版 letter 归并为 text
            if ($iconType === 'letter') $iconType = 'text';
            if (!in_array($iconType, ['image', 'text', 'favicon'], true)) $iconType = 'image';
            $openMethod = (int)($i['open_method'] ?? 2);
            if (!in_array($openMethod, [1, 2, 3], true)) $openMethod = 2;
            $stI->execute([
                (int)$i['id'],
                (int)$i['group_id'],
                mb_substr((string)$i['title'], 0, 50),
                $cleanUrl($i['url'] ?? ''),
                $cleanUrl($i['lan_url'] ?? ''),
                mb_substr((string)($i['description'] ?? ''), 0, 1000),
                $iconType,
                mb_substr((string)($i['icon_value'] ?? ''), 0, 1000),
                mb_substr((string)($i['icon_bg'] ?? ''), 0, 20),
                $openMethod,
                (int)($i['sort'] ?? 0),
                (int)($i['user_id'] ?? 1),
            ]);
            $cntItems++;
        }

        $pdo->commit();
    } catch (Throwable $e2) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // 数据库失败：丢弃临时文件，现有数据无损
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($tmpDir);
        // 详细异常（可能含库名 / 表结构 / SQL 信息）仅写服务端日志，不回显给浏览器
        error_log('[SolarPanel] backup.php 导入失败（现有数据未改动）：' . $e2->getMessage());
        fail('导入数据库失败，现有数据未改动；详细原因已记录到服务器日志，请联系站点管理员排查');
    }

    /* ---- 第 3 步：数据库成功后，切换文件目录 ---- */
    // 清空现有 uploads 内容（保留目录本身与 weather 等预置资源目录）
    backup_clear_uploads($uploadDir);
    // 把临时目录内容移入 uploads
    $moved = 0;
    if (is_dir($tmpDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($tmpDir) + 1));
            $target = $uploadDir . '/' . $rel;
            if ($f->isDir()) {
                if (!is_dir($target)) @mkdir($target, 0755, true);
            } else {
                $td = dirname($target);
                if (!is_dir($td)) @mkdir($td, 0755, true);
                if (@rename($f->getPathname(), $target)) $moved++;
            }
        }
        // 移走文件后残留的空目录逐层清理，确保临时目录被移除
        $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it2 as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($tmpDir);
    }

    ok([
        'settings' => $cntSettings,
        'groups' => $cntGroups,
        'items' => $cntItems,
        'orphan_items' => $orphanItems,
        'files' => $moved,
        'files_restored' => $restoredFiles,
    ]);
}

/* ================= 清空恢复初始状态 ================= */
if ($action === 'reset') {
    // 默认设置（与 install.php 保持一致，新增设置项需同步此处）
    $defaultSettings = [
        'site_title'         => 'SolarPanel',
        'site_logo'          => '',
        'wallpaper'          => '',
        'mask_opacity'       => '0.35',
        'wallpaper_blur'     => '6',
        'announcement'       => '欢迎使用 SolarPanel！所有展示内容均可在后台设置。',
        'announcement_show'  => '0',
        'footer'             => 'Powered by SolarPanel',
        'clock_show'         => '1',
        'default_theme'      => 'dark',
        'theme_style'        => 'soft',
        'card_style'         => 'detail',
        'default_lan_mode'   => 'public',
        'site_url'           => '',
        'content_maxwidth'   => '1200',
        'content_pad_lr'     => '20',
        'content_pad_top'    => '0',
        'content_pad_bottom' => '40',
        'weather_show'       => '1',
        'weather_city'       => '',
        'search_width'       => '640',
        'home_view'          => 'both',
        'news_sources'       => '',
        'news_order'         => '',
        'search_engines'     => json_encode([
            ['name' => '百度',   'url' => 'https://www.baidu.com/s?wd=%s'],
            ['name' => 'Google', 'url' => 'https://www.google.com/search?q=%s'],
            ['name' => 'Bing',   'url' => 'https://www.bing.com/search?q=%s'],
            ['name' => 'DuckDuckGo', 'url' => 'https://duckduckgo.com/?q=%s'],
            ['name' => 'Yandex', 'url' => 'https://yandex.com/search/?text=%s'],
            ['name' => 'GitHub', 'url' => 'https://github.com/search?q=%s'],
            ['name' => '搜狗',     'url' => 'https://www.sogou.com/web?query=%s'],
            ['name' => '360搜索',  'url' => 'https://www.so.com/s?q=%s'],
            ['name' => '神马',     'url' => 'https://m.sm.cn/s?q=%s'],
            ['name' => '夸克',     'url' => 'https://www.quark.cn/s?q=%s'],
            ['name' => '头条搜索', 'url' => 'https://so.toutiao.com/search?keyword=%s'],
            ['name' => '中国搜索', 'url' => 'https://www.chinaso.com/search/all?q=%s'],
            ['name' => '抖音',     'url' => 'https://www.douyin.com/search/%s'],
        ], JSON_UNESCAPED_UNICODE),
        'search_default'     => '百度',
    ];

    // 1. 事务清空并恢复默认设置 + 示例分组 / 卡片（与安装向导一致，users 表不受影响）
    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM items');
        $pdo->exec('DELETE FROM item_groups');
        $pdo->exec('DELETE FROM settings');
        $st = $pdo->prepare('INSERT INTO settings (config_name, config_value) VALUES (?, ?)');
        foreach ($defaultSettings as $name => $value) {
            $st->execute([$name, $value]);
        }

        // 示例分组与卡片（与 install.php / init.sql 保持一致；字段显式写全含 is_visible / user_id，不依赖表默认值）
        $pdo->exec("INSERT INTO item_groups (title, description, sort, is_visible, user_id) VALUES ('常用推荐', '点击卡片即可跳转，可在后台管理', 0, 1, 1)");
        $gid = (int)$pdo->lastInsertId();
        $sampleItems = [
            ['GitHub',     'https://github.com',       '全球最大代码托管平台', 0],
            ['哔哩哔哩',    'https://www.bilibili.com', '视频弹幕网站', 1],
            ['Docker Hub', 'https://hub.docker.com',   '容器镜像仓库', 2],
        ];
        $stI = $pdo->prepare('INSERT INTO items (group_id, title, url, description, icon_type, icon_value, icon_bg, open_method, sort) VALUES (?, ?, ?, ?, ?, ?, ?, 2, ?)');
        foreach ($sampleItems as $it) {
            $stI->execute([$gid, $it[0], $it[1], $it[2], 'favicon', '', '', $it[3]]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[SolarPanel] backup.php 恢复初始状态失败（现有数据未改动）：' . $e->getMessage());
        fail('恢复初始状态失败，现有数据未改动；详细原因已记录到服务器日志，请联系站点管理员排查');
    }

    // 2. 清空 uploads 目录（保留目录本身与 weather 等预置资源目录）
    $clearedFiles = backup_clear_uploads($uploadDir);

    ok([
        'settings' => count($defaultSettings),
        'groups' => 1,
        'items' => count($sampleItems),
        'files_cleared' => $clearedFiles,
    ]);
}

fail('未知操作');
