<?php
/**
 * 每日壁纸接口（公开，主页无需登录）
 * GET ?action=daily[&source=bing]
 *   - 抓取 Bing 每日壁纸，按服务器当日日期本地缓存（frontend/uploads/daily_wallpaper/）
 *   - 当日已缓存则直接返回本地 URL，不回源；失败回退最近缓存
 */
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/http.php';

header('Cache-Control: public, max-age=3600');

@set_time_limit(30);

/** 每日壁纸缓存目录 */
function wallpaper_cache_dir(): string
{
    $dir = __DIR__ . '/../../frontend/uploads/daily_wallpaper';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

/** 删除超过 N 天的旧缓存文件 */
function wallpaper_cleanup(string $dir, int $keepDays = 3): void
{
    $cutoff = time() - $keepDays * 86400;
    foreach ((glob($dir . '/*.*') ?: []) as $f) {
        $base = basename($f);
        if ($base === 'index.html') continue;
        if (is_file($f) && @filemtime($f) < $cutoff) {
            @unlink($f);
        }
    }
}

/** 抓取 Bing 每日壁纸（优先 1920x1080 高清，失败回退原始） */
function wallpaper_fetch_bing(): ?array
{
    $api = 'https://cn.bing.com/HPImageArchive.php?format=js&idx=0&n=1&mkt=zh-CN';
    $raw = sp_http_fetch($api, 6, true);
    if ($raw === '') return null;
    $data = json_decode($raw, true);
    $img = $data['images'][0] ?? null;
    if (!is_array($img) || empty($img['url'])) return null;

    // 优先 1920x1080 高清
    $base = !empty($img['urlbase']) ? $img['urlbase'] : $img['url'];
    $src  = 'https://cn.bing.com' . $base . '_1920x1080.jpg';

    $bin = sp_http_fetch($src, 10, true);
    if ($bin === '' || strlen($bin) < 2048) {
        // 高清失败则回退原始 url
        $src = 'https://cn.bing.com' . $img['url'];
        $bin = sp_http_fetch($src, 10, true);
        if ($bin === '' || strlen($bin) < 2048) return null;
    }

    $dir = wallpaper_cache_dir();
    $date = date('Y-m-d');
    $file = $dir . '/bing_' . $date . '.jpg';
    $meta = $dir . '/bing_' . $date . '.json';
    if (@file_put_contents($file, $bin) === false) return null;
    @file_put_contents($meta, json_encode([
        'title'     => (string)($img['title'] ?? ''),
        'copyright' => (string)($img['copyright'] ?? ''),
        'source'    => 'bing',
        'date'      => $date,
        'fetched'   => date('c'),
    ], JSON_UNESCAPED_UNICODE));
    @chmod($file, 0644);

    return [
        'url'       => '/frontend/uploads/daily_wallpaper/bing_' . $date . '.jpg',
        'title'     => (string)($img['title'] ?? ''),
        'copyright' => (string)($img['copyright'] ?? ''),
        'source'    => 'bing',
        'date'      => $date,
    ];
}

$action = str_param('action', 'daily');
if ($action !== 'daily') fail('不支持的 action');

$source = str_param('source', 'bing');
if (strtolower($source) !== 'bing') fail('暂不支持的壁纸源：' . $source);

$dir = wallpaper_cache_dir();
wallpaper_cleanup($dir);

// DB 级限流
require_once __DIR__ . '/../lib/auth.php';
$rate = sp_rate_check('wallpaper', 10, 60);
if (!$rate['ok']) {
    // 限流命中时优先返回已缓存的今日壁纸
    $date = date('Y-m-d');
    $file = $dir . '/bing_' . $date . '.jpg';
    $meta = $dir . '/bing_' . $date . '.json';
    if (is_file($file) && is_file($meta)) {
        $info = json_decode((string)@file_get_contents($meta), true) ?: [];
        ok([
            'url'       => '/frontend/uploads/daily_wallpaper/bing_' . $date . '.jpg',
            'title'     => (string)($info['title'] ?? ''),
            'copyright' => (string)($info['copyright'] ?? ''),
            'source'    => 'bing',
            'date'      => $date,
            'cache'     => 'hit',
        ]);
    }
    sp_rate_limit_respond($rate);
}

// 当日缓存命中
$date = date('Y-m-d');
$file = $dir . '/bing_' . $date . '.jpg';
$meta = $dir . '/bing_' . $date . '.json';
if (is_file($file) && is_file($meta)) {
    $info = json_decode((string)@file_get_contents($meta), true) ?: [];
    ok([
        'url'       => '/frontend/uploads/daily_wallpaper/bing_' . $date . '.jpg',
        'title'     => (string)($info['title'] ?? ''),
        'copyright' => (string)($info['copyright'] ?? ''),
        'source'    => 'bing',
        'date'      => $date,
        'cache'     => 'hit',
    ]);
}

// 回源抓取
$res = wallpaper_fetch_bing();
if ($res === null) {
    // 抓取失败：复用最近一次缓存
    $stale = glob($dir . '/bing_*.jpg') ?: [];
    rsort($stale);
    foreach ($stale as $old) {
        if (is_file($old)) {
            $oldMeta = preg_replace('/\.jpg$/', '.json', $old);
            $info = is_file($oldMeta) ? (json_decode((string)@file_get_contents($oldMeta), true) ?: []) : [];
            $rel = '/frontend/uploads/daily_wallpaper/' . basename($old);
            ok([
                'url'       => $rel,
                'title'     => (string)($info['title'] ?? ''),
                'copyright' => (string)($info['copyright'] ?? ''),
                'source'    => 'bing',
                'date'      => (string)($info['date'] ?? ''),
                'cache'     => 'stale',
            ]);
        }
    }
    fail('每日壁纸获取失败，请稍后重试或检查服务器网络');
}

$res['cache'] = 'miss';
ok($res);
