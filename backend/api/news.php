<?php
/**
 * 热点新闻接口（公开，主页无需登录；参考 NewsNow 项目的卡片式热榜）
 *
 * GET ?action=meta           数据源定义 + 总开关（后台勾选数据源用）
 * GET ?action=all            全部已启用数据源的缓存数据（首屏秒开，无缓存的源标记 empty）
 * GET ?action=source&id=xx   单个源：间隔内直接回缓存；TTL 内回缓存；超时回源，失败回退旧缓存
 *      &latest=1             强制回源刷新（每张卡片的「刷新」按钮）
 *
 * 缓存：frontend/uploads/news/{id}.json，按源的 interval 差异化过期，TTL 30 分钟兜底。
 * 安全：源 id 白名单校验；抓取地址全部内置（见 lib/news_sources.php），无用户可控 URL。
 */
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/news_sources.php';
// HTTP 抓取公共库（TLS 证书严格校验；curl 失败自动回退 stream；OpenSSL3 close_notify 兼容）
require_once __DIR__ . '/../lib/http.php';

// 公开接口：禁止中间代理 / CDN 缓存
header('Cache-Control: no-store, max-age=0');
@set_time_limit(30);

const NEWS_TTL = 1800; // 缓存兜底有效期（秒）：超过且回源失败则报错

/**
 * 数据源抓取（lib/news_sources.php 的闭包按此名称调用）
 * 实现见 lib/http.php 的 sp_http_news()：地址全部为内置源，无用户可控 URL，不需 SSRF 防护
 */
function news_http(string $u, int $timeout = 8, array $headers = [], ?string $postBody = null): string
{
    return sp_http_news($u, $timeout, $headers, $postBody);
}

/* ---------------- 缓存读写 ---------------- */
function news_cache_dir(): string
{
    $dir = __DIR__ . '/../../frontend/uploads/news';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // 缓存只经本接口读取，不应对外直接暴露（JSON 为公开数据，主要防目录被列目录/误作他用）
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents(
            $ht,
            "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
        );
    }
    return $dir;
}

function news_read_cache(string $id): ?array
{
    $f = news_cache_dir() . '/' . $id . '.json';
    if (!is_file($f)) return null;
    $j = @json_decode((string)file_get_contents($f), true);
    if (is_array($j) && isset($j['updated'], $j['items']) && is_array($j['items'])) return $j;
    return null;
}

function news_write_cache(string $id, array $items): void
{
    $f = news_cache_dir() . '/' . $id . '.json';
    @file_put_contents(
        $f,
        json_encode(['updated' => time(), 'items' => array_values($items)], JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/* ---------------- 启用的数据源与显示顺序（读站点设置；数据库不可用时默认全部启用、内置顺序） ---------------- */
function news_enabled_setting(): array
{
    try {
        require_once __DIR__ . '/../lib/db.php';
        $rows = db()->query("SELECT config_name, config_value FROM settings WHERE config_name IN ('home_view','news_sources','news_order')")->fetchAll();
        $cfg = [];
        foreach ($rows as $r) $cfg[$r['config_name']] = (string)$r['config_value'];
    } catch (Throwable $e) {
        $cfg = []; // 未安装 / 数据库异常：不阻断新闻功能
    }
    // 热点新闻功能开关由「前端视图」设置决定：nav（只显示导航站）时新闻功能关闭（原独立总开关已并入 home_view）
    $enabled = ($cfg['home_view'] ?? 'both') !== 'nav';
    $ids = [];
    if (!empty($cfg['news_sources'])) {
        $arr = json_decode($cfg['news_sources'], true);
        if (is_array($arr)) {
            foreach ($arr as $v) {
                if (is_string($v)) $ids[] = $v;
            }
        }
    }
    $order = [];
    if (!empty($cfg['news_order'])) {
        $arr = json_decode($cfg['news_order'], true);
        if (is_array($arr)) {
            foreach ($arr as $v) {
                if (is_string($v)) $order[] = $v;
            }
        }
    }
    return [$enabled, $ids, $order]; // ids 为空 = 全部启用；order 为空 = 内置顺序
}

/** 对外暴露的源定义（去掉闭包）；$ids 为空=全部启用，$order 为显示顺序（未包含的新源按内置顺序追加） */
function news_public_defs(array $ids, array $order = []): array
{
    $defs = news_source_defs();
    $byId = [];
    foreach ($defs as $d) $byId[$d['id']] = $d;
    $pub = function (array $d): array {
        return [
            'id' => $d['id'],
            'name' => $d['name'],
            'color' => $d['color'],
            'type' => $d['type'],
            'home' => $d['home'],
            'interval' => $d['interval'],
            'logo' => $d['logo'] ?? '',
        ];
    };
    $enabledFlip = $ids ? array_flip($ids) : null;
    $out = [];
    $seen = [];
    // 1) 按保存的显示顺序输出
    foreach ($order as $sid) {
        if (!isset($byId[$sid]) || isset($seen[$sid])) continue;
        if ($enabledFlip !== null && !isset($enabledFlip[$sid])) { $seen[$sid] = true; continue; }
        $seen[$sid] = true;
        $out[] = $pub($byId[$sid]);
    }
    // 2) 顺序记录中没有的源（新版本新增）按内置定义顺序追加
    foreach ($defs as $d) {
        if (isset($seen[$d['id']])) continue;
        if ($enabledFlip !== null && !isset($enabledFlip[$d['id']])) continue;
        $seen[$d['id']] = true;
        $out[] = $pub($d);
    }
    return $out;
}

/* ---------------- 路由 ---------------- */
$action = str_param('action', 'all');

if ($action === 'meta') {
    // 后台勾选需要完整源列表（含被关掉的源），故不按启用列表过滤；顺序按保存的显示顺序
    [$enabled, , $order] = news_enabled_setting();
    ok(['enabled' => $enabled, 'sources' => news_public_defs([], $order)]);
}

if ($action === 'all') {
    [$enabled, $ids, $order] = news_enabled_setting();
    if (!$enabled) ok(['enabled' => false, 'sources' => [], 'list' => []]);
    $defs = news_public_defs($ids, $order);
    $list = [];
    foreach ($defs as $d) {
        $c = news_read_cache($d['id']);
        if ($c) {
            $list[] = ['id' => $d['id'], 'status' => 'cache', 'updated' => (int)$c['updated'], 'items' => $c['items']];
        } else {
            $list[] = ['id' => $d['id'], 'status' => 'empty', 'updated' => 0, 'items' => []];
        }
    }
    ok(['enabled' => true, 'sources' => $defs, 'list' => $list]);
}

if ($action === 'source') {
    $id = str_param('id', '');
    $def = news_source_def($id);
    if (!$def) fail('未知数据源');
    $latest = str_param('latest', '') === '1';
    $now = time();
    $cache = news_read_cache($id);

    // 1. 间隔内：直接回缓存（不回源）
    if (!$latest && $cache && $now - (int)$cache['updated'] < (int)$def['interval']) {
        ok(['id' => $id, 'status' => 'success', 'updated' => (int)$cache['updated'], 'items' => $cache['items']]);
    }
    // 2. TTL 内且非强制刷新：回缓存（后台静默，卡片显示缓存时间）
    if (!$latest && $cache && $now - (int)$cache['updated'] < NEWS_TTL) {
        ok(['id' => $id, 'status' => 'cache', 'updated' => (int)$cache['updated'], 'items' => $cache['items']]);
    }
    // 3. 回源抓取
    try {
        $items = call_user_func($def['fetch']);
        if (!$items) throw new RuntimeException('empty result');
        $items = array_slice(array_values($items), 0, 20);
        news_write_cache($id, $items);
        ok(['id' => $id, 'status' => 'success', 'updated' => $now, 'items' => $items]);
    } catch (Throwable $e) {
        error_log('[SolarPanel] news.php 数据源「' . $id . '」抓取失败：' . $e->getMessage());
        // 回源失败：有旧缓存（哪怕超 TTL）先兜住
        if ($cache) {
            ok(['id' => $id, 'status' => 'cache', 'updated' => (int)$cache['updated'], 'items' => $cache['items']]);
        }
        ok(['id' => $id, 'status' => 'error', 'updated' => 0, 'items' => []]);
    }
}

fail('未知操作');
