<?php
/**
 * 卡片（导航项）管理（需登录）
 * 权限：list 所有登录用户；edit / delete / sort / favicon / fetch_meta 需管理员或编辑者
 * GET  ?action=list&group_id=1   卡片列表
 * POST action=edit               新增/编辑
 * POST action=delete             删除 {id}
 * POST action=sort               上移/下移 {id, direction}
 * GET  ?action=favicon&url=...   服务端抓取站点图标并缓存到 uploads/favicon
 * GET  ?action=fetch_meta&url=... 抓取网页标题/描述/图标（部分成功也返回）
 */
// 输出缓冲：兜底拦截任何意外输出（PHP 通知/警告、BOM 等），保证 JSON 响应纯净
// （json_out 输出前会丢弃缓冲内容；若无此行，display_errors=On 时通知会混入 JSON 导致前端解析失败）
ob_start();

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/sort.php';
require_once __DIR__ . '/../lib/security.php';
// HTTP 抓取公共库：sp_http_fetch() / sp_http_last_error() / sp_curl_hint()
// （TLS 证书严格校验；public_only 模式 SSRF 防护 + 重定向逐跳校验）
require_once __DIR__ . '/../lib/http.php';

$u = require_login();
$pdo = db();
$action = str_param('action', 'list');

// 写操作与联网抓取需要管理员或编辑者（viewer 只读）
if (in_array($action, ['edit', 'delete', 'sort', 'sort_batch', 'favicon', 'fetch_meta'], true)) {
    require_roles('admin', 'editor');
}

// HTTP 抓取（sp_http_fetch / sp_http_last_error / sp_curl_hint）已抽取到 lib/http.php，
// 与 news.php / weather.php 共用：TLS 证书严格校验，SSRF 防护逐跳重定向校验。

/** 兼容无 mbstring 扩展的环境 */
function sp_substr(string $s, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $len, 'UTF-8') : substr($s, 0, $len);
}

function sp_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/** 从 HTML 中提取 meta 内容（兼容 property/name 与 content 先后顺序） */
function sp_meta_content(string $html, string $attr, string $key): string
{
    if (preg_match('/<meta[^>]+' . $attr . '=["\']' . preg_quote($key, '/') . '["\'][^>]*>/i', $html, $m)) {
        if (preg_match('/content=["\']([^"\']*)["\']/i', $m[0], $m2)) {
            return trim(html_entity_decode($m2[1], ENT_QUOTES));
        }
    }
    if (preg_match('/<meta[^>]+content=["\'][^"\']*["\'][^>]*' . $attr . '=["\']' . preg_quote($key, '/') . '["\'][^>]*>/i', $html, $m)) {
        if (preg_match('/content=["\']([^"\']*)["\']/i', $m[0], $m2)) {
            return trim(html_entity_decode($m2[1], ENT_QUOTES));
        }
    }
    return '';
}

/** 将网页中的相对图标地址转为绝对地址 */
function sp_absolutize(string $href, string $scheme, string $host): string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES));
    if ($href === '') return '';
    if (strpos($href, '//') === 0) return $scheme . ':' . $href;
    if (preg_match('#^https?://#i', $href)) return $href;
    if ($href[0] === '/') return $scheme . '://' . $host . $href;
    return $scheme . '://' . $host . '/' . ltrim($href, './');
}

/** 公共图标服务候选（国内可达源优先） */
function sp_favicon_services(string $host): array
{
    return [
        'https://api.iowen.cn/favicon/' . $host . '.png',
        'https://favicon.cccyun.cc/' . $host,
        'https://favicon.im/' . $host . '?larger=true',
        'https://icon.horse/icon/' . $host,
        'https://www.google.com/s2/favicons?domain=' . $host . '&sz=64',
    ];
}

/**
 * 尝试服务端抓取并缓存图标。
 * 返回本地缓存路径（/frontend/uploads/favicon/xxx.ext）；全部失败返回 ''
 */
function sp_try_cache_icon(string $host, string $scheme, array $pageIcons, float $budget = 10.0): string
{
    $dir = __DIR__ . '/../../frontend/uploads/favicon';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // 上传根目录保护（禁 PHP 执行 + SVG 沙箱）
    sp_ensure_upload_protected(dirname($dir));

    $key = md5($host);
    foreach (['png', 'ico', 'svg', 'jpg', 'gif', 'webp'] as $ext) {
        $f = $dir . '/' . $key . '.' . $ext;
        if (is_file($f) && filemtime($f) > time() - 30 * 86400) {
            return '/frontend/uploads/favicon/' . $key . '.' . $ext;
        }
    }

    // 候选顺序：页面声明的图标 → 站点 /favicon.ico → 国内公共源 → 国外公共源
    $candidates = $pageIcons;
    $candidates[] = $scheme . '://' . $host . '/favicon.ico';
    foreach (sp_favicon_services($host) as $svc) $candidates[] = $svc;

    $extMap = [
        'image/png' => 'png', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico',
        'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg',
    ];
    $started = microtime(true);
    foreach ($candidates as $cu) {
        if ($cu === '') continue;
        if (microtime(true) - $started > $budget) break; // 总预算，避免国外源拖垮请求
        // 国外公共源给更短超时，避免连接挂起；全部走公网校验（SSRF 防护，重定向逐跳检查）
        $to = (strpos($cu, $host) !== false) ? 6 : 4;
        $bin = sp_http_fetch($cu, $to, true);
        if (strlen($bin) < 50) continue;

        $info = @getimagesizefromstring($bin);
        $mime = $info['mime'] ?? '';
        if ($mime === '' && stripos($bin, '<svg') !== false) $mime = 'image/svg+xml';
        if (!isset($extMap[$mime])) continue;
        // SVG：净化脚本/事件属性，不合法直接跳过该候选
        if ($mime === 'image/svg+xml') {
            $clean = sp_sanitize_svg($bin);
            if ($clean === null) continue;
            $bin = $clean;
        }

        @file_put_contents($dir . '/' . $key . '.' . $extMap[$mime], $bin);
        if (!is_file($dir . '/' . $key . '.' . $extMap[$mime]) || filesize($dir . '/' . $key . '.' . $extMap[$mime]) < 50) {
            $GLOBALS['sp_http_err'] = '图标缓存目录不可写：frontend/uploads/favicon（请检查目录权限）';
            continue; // 写入失败：尝试下一候选，而不是返回会 404 的本地路径
        }
        return '/frontend/uploads/favicon/' . $key . '.' . $extMap[$mime];
    }
    return '';
}

if ($action === 'list') {
    $groupId = int_param('group_id', 0);
    $sql = 'SELECT id, group_id, title, url, lan_url, description, icon_type, icon_value, icon_bg, open_method, sort
            FROM items';
    $params = [];
    if ($groupId > 0) {
        $sql .= ' WHERE group_id = ?';
        $params[] = $groupId;
    }
    $sql .= ' ORDER BY sort ASC, id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['group_id'] = (int)$r['group_id'];
        $r['open_method'] = (int)$r['open_method'];
        $r['sort'] = (int)$r['sort'];
    }
    unset($r);
    ok($rows);
}

if ($action === 'edit') {
    $id = int_param('id', 0);
    $groupId = int_param('group_id');
    $title = str_param('title');
    if ($groupId <= 0) fail('请选择分组');
    if ($title === '') fail('标题不能为空');
    if (sp_strlen($title) > 50) fail('标题过长');

    $gidExists = $pdo->prepare('SELECT COUNT(*) FROM item_groups WHERE id = ?');
    $gidExists->execute([$groupId]);
    if ((int)$gidExists->fetchColumn() === 0) fail('分组不存在');

    $url = sp_substr(str_param('url'), 1000);
    $lanUrl = sp_substr(str_param('lan_url'), 1000);
    // 协议白名单：仅允许 http(s)，拦截 javascript: / data: / vbscript: 等存储型 XSS 载荷
    foreach (['外网地址' => $url, '内网地址' => $lanUrl] as $label => $uVal) {
        if ($uVal !== '' && !sp_url_is_http($uVal)) {
            fail($label . '需以 http:// 或 https:// 开头');
        }
    }
    $desc = sp_substr(str_param('description'), 1000);
    $iconType = str_param('icon_type', 'image');
    if (!in_array($iconType, ['image', 'text', 'favicon'], true)) $iconType = 'image';
    $iconValue = sp_substr(str_param('icon_value'), 1000);
    $iconBg = str_param('icon_bg');
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$|^$/', $iconBg)) $iconBg = '';
    $openMethod = int_param('open_method', 2);
    if (!in_array($openMethod, [1, 2, 3], true)) $openMethod = 2;

    if ($id > 0) {
        $st = $pdo->prepare(
            'UPDATE items SET group_id = ?, title = ?, url = ?, lan_url = ?, description = ?,
             icon_type = ?, icon_value = ?, icon_bg = ?, open_method = ? WHERE id = ?'
        );
        $st->execute([$groupId, $title, $url, $lanUrl, $desc, $iconType, $iconValue, $iconBg, $openMethod, $id]);
        ok(['id' => $id]);
    }
    $stMax = $pdo->prepare('SELECT COALESCE(MAX(sort), -1) FROM items WHERE group_id = ?');
    $stMax->execute([$groupId]);
    $max = (int)$stMax->fetchColumn();
    $st = $pdo->prepare(
        'INSERT INTO items (group_id, title, url, lan_url, description, icon_type, icon_value, icon_bg, open_method, sort)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$groupId, $title, $url, $lanUrl, $desc, $iconType, $iconValue, $iconBg, $openMethod, $max + 1]);
    ok(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'delete') {
    $id = int_param('id');
    if ($id <= 0) fail('参数错误');
    $st = $pdo->prepare('DELETE FROM items WHERE id = ?');
    $st->execute([$id]);
    ok();
}

if ($action === 'sort') {
    move_sort($pdo, 'items', int_param('id'), str_param('direction', 'up'));
    ok();
}

if ($action === 'sort_batch') {
    // 拖拽排序保存：{group_id, ids:[id,...]}——按提交顺序重写该组内全部卡片的 sort
    $batchGroup = int_param('group_id');
    $batchIds = param('ids');
    if (!is_array($batchIds)) {
        fail('参数错误');
    }
    save_order($pdo, 'items', $batchIds, $batchGroup);
    ok();
}

if ($action === 'favicon' || $action === 'fetch_meta') {
    // 抓取链路较长：放宽执行时间；任何 PHP 致命错误都转为 JSON 返回具体原因（避免前端只看到 HTTP 500）
    @set_time_limit(45);
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            // 丢弃缓冲中的错误 HTML 输出，保证致命错误也返回纯 JSON
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'code' => 1,
                'data' => null,
                'msg'  => '服务器 PHP 错误：' . $e['message'] . '（' . basename((string)$e['file']) . ':' . (int)$e['line'] . '）',
            ], JSON_UNESCAPED_UNICODE);
        }
    });

    $url = str_param('url');
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') fail('URL 无效');
    $host = strtolower(preg_replace('/[^a-z0-9\.\-]/i', '', $host));
    if ($host === '' || strpos($host, '.') === false) fail('URL 无效');

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true)) $scheme = 'https';

    // 内网 / 本机 / 保留地址不发起服务端抓取（SSRF 防护）：
    // 基于主机名解析后的真实 IP 判定，覆盖十进制 IP、解析到内网的域名、
    // 云元数据 169.254.169.254、CGNAT 100.64/10、代理 fake-ip 198.18/15、IPv6 本地段等绕过手法
    $isLan = !sp_host_is_public($host);
    $html = '';
    $pageErr = '';
    if (!$isLan) {
        $html = sp_http_fetch($scheme . '://' . $host . '/', 6, true);
        if ($html === '') $pageErr = sp_http_last_error();
    }

    // 解析页面声明的图标
    $pageIcons = [];
    if ($html !== '' && preg_match_all('/<link[^>]+rel=[^>]*icon[^>]*>/i', $html, $mm)) {
        foreach ($mm[0] as $linkTag) {
            if (preg_match('/href=["\']([^"\']+)["\']/i', $linkTag, $m2)) {
                $abs = sp_absolutize($m2[1], $scheme, $host);
                if ($abs !== '') $pageIcons[] = $abs;
            }
        }
    }

    if ($action === 'favicon') {
        if ($isLan) fail('不支持从内网 / 本机地址获取图标，请手动填写图标地址或使用文字图标');
        // 服务端抓取并缓存；失败时回退公共图标服务地址（交给访客浏览器加载），前端不再报错
        $local = sp_try_cache_icon($host, $scheme, $pageIcons);
        if ($local !== '') {
            ok(['url' => $local, 'cached' => strpos($local, '/frontend/uploads/') === 0, 'fallback' => false]);
        }
        // 附带失败原因，便于排查服务器网络/权限问题
        ok(['url' => sp_favicon_services($host)[0], 'cached' => false, 'fallback' => true,
            'diag' => sp_http_last_error() ?: ($pageErr !== '' ? $pageErr : '各图标源均未取到有效图片')]);
    }

    /* ---- action=fetch_meta：标题 / 描述 / 图标，部分成功也返回 ---- */
    $result = ['title' => '', 'description' => '', 'icon' => '', 'fallback_icon' => false];

    if ($html !== '') {
        $title = sp_meta_content($html, 'property', 'og:title');
        if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES));
        }
        $result['title'] = sp_substr(trim(preg_replace('/\s+/u', ' ', $title)), 50);

        $desc = sp_meta_content($html, 'property', 'og:description');
        if ($desc === '') $desc = sp_meta_content($html, 'name', 'description');
        if ($desc === '') {
            // 智能兜底：去脚本/样式后截取正文纯文本
            $text = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', ' ', $html);
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
            $desc = sp_substr($text, 80);
        }
        $result['description'] = sp_substr(trim($desc), 200);
    }

    if (!$isLan) {
        $local = sp_try_cache_icon($host, $scheme, $pageIcons, 8.0);
        if ($local !== '') {
            $result['icon'] = $local;
        } else {
            // 服务端抓不到：回退公共图标服务 URL，由浏览器加载
            $result['icon'] = sp_favicon_services($host)[0];
            $result['fallback_icon'] = true;
            $iconErr = sp_http_last_error();
        }
    }

    // 页面没抓到（标题/描述为空）：告知具体原因，便于区分"站点无信息"与"服务器网络受限"
    if (!$isLan && $result['title'] === '' && $result['description'] === '') {
        $result['warn'] = '页面内容抓取失败：' . ($pageErr !== '' ? $pageErr : '站点无标题/描述信息');
    } elseif (!empty($iconErr)) {
        $result['warn'] = '图标未能缓存到本地（' . $iconErr . '），已改用公共图标源';
    }

    if ($result['title'] === '' && $result['description'] === '' && $result['icon'] === '') {
        $reason = $isLan ? '内网/本机地址不支持自动抓取，请手动填写'
            : ($pageErr !== '' ? $pageErr : '站点不可达、被防护拦截或服务器网络受限');
        fail('未能读取该站点信息（' . $reason . '），可手动填写');
    }
    ok($result);
}

fail('未知操作');
