<?php
/**
 * 在线更新检测（check）+ 下载（download）
 * POST ?action=check    从自建 OSS 拉 versions.json，对比当前版本，返回是否有更新
 * POST ?action=download 下载 zip 到暂存目录（MD5 校验）
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/upgrade.php';

ini_set('display_errors', '0');
@ini_set('memory_limit', '256M');

require_roles('admin');

$ROOT = realpath(__DIR__ . '/../..');
if ($ROOT === false) $ROOT = dirname(__DIR__, 2);
$action = str_param('action', '');

/* ==================== 版本工具函数 ==================== */

/** 对比版本号: a > b → 1, a == b → 0, a < b → -1 */
function sp_compare_ver($a, $b): int {
    $a = ltrim($a, 'v'); $b = ltrim($b, 'v');
    $ap = explode('.', $a); $bp = explode('.', $b);
    while (count($ap) < count($bp)) $ap[] = '0';
    while (count($bp) < count($ap)) $bp[] = '0';
    for ($i = 0; $i < count($ap); $i++) {
        $ai = (int)$ap[$i]; $bi = (int)$bp[$i];
        if ($ai !== $bi) return $ai < $bi ? -1 : 1;
    }
    return 0;
}

/** 计算升级路径 */
function sp_find_path($cur, $latest, $meta): array {
    $latestEntry = $meta['versions'][$latest] ?? null;
    if (!$latestEntry) return [$cur, $latest];
    $minFrom = $latestEntry['min_from'] ?? 'v0.0.0';
    if (sp_compare_ver($cur, $minFrom) < 0) return ['full'];
    // Docker 版（天然跨版本）
    if (isset($latestEntry['docker'])) return [$cur, $latest];
    // PHP 版查 deltas
    if (isset($latestEntry['web']['deltas'][$cur])) return [$cur, $latest];
    return [$cur, $latest];
}

/** 拉 versions.json 带 24h 缓存（文件缓存 + 过期时间） */
function sp_fetch_versions_cached($url, $bypass = false) {
    $cacheDir = sys_get_temp_dir() . '/sp_version_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/versions.json';
    $cacheTtl  = 3600; // 1h（后台自动检测每小时最多回源一次；手动检查带 nocache=1 即时刷新）

    // 读缓存（除非 bypass）
    if (!$bypass && @file_exists($cacheFile)) {
        $mtime = @filemtime($cacheFile);
        if ($mtime && (time() - $mtime) < $cacheTtl) {
            $data = @file_get_contents($cacheFile);
            if ($data && ($m = json_decode($data, true)) !== null) return $m;
        }
    }

    // 远端拉取
    $ctx = stream_context_create([
        'http' => [
            'timeout'      => 10,
            'header'       => "User-Agent: SolarPanel-Update/1.0\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        // 失败时尝试用缓存（即使过期）
        if (@file_exists($cacheFile)) {
            $data = @file_get_contents($cacheFile);
            if ($data && ($m = json_decode($data, true)) !== null) return $m;
        }
        return null;
    }

    $meta = json_decode($body, true);
    if ($meta === null) return null;

    // 写缓存
    @file_put_contents($cacheFile, $body);
    return $meta;
}

/* ==================== check ==================== */

if ($action === 'check') {
    $source = 'https://updates.ozero.top';
    $versionsURL = $source . '/versions.json';

    $bypass = (isset($_GET['nocache']) || isset($_POST['nocache']));
    $meta = sp_fetch_versions_cached($versionsURL, $bypass);
    if ($meta === null) {
        fail('无法获取更新源，请检查 update_source 配置或网络');
    }

    // 当前版本：读 api.js 里的 APP_VERSION
    $apijsPath = $ROOT . '/frontend/assets/js/api.js';
    $cur = null;
    if (is_file($apijsPath)) {
        $src = @file_get_contents($apijsPath);
        if ($src !== false && preg_match("/APP_VERSION\s*=\s*'([^']+)'/", $src, $m)) {
            $cur = $m[1];
        }
    }
    if (!$cur) $cur = 'v1.0.006'; // 兜底

    // 已是最新
    if (sp_compare_ver($cur, $meta['latest']) >= 0) {
        ok([
            'available' => false,
            'current'   => $cur,
            'latest'    => $cur,
        ]);
    }

    $latestEntry = $meta['versions'][$meta['latest']];
    $path        = sp_find_path($cur, $meta['latest'], $meta);

    $resp = [
        'available' => true,
        'current'   => $cur,
        'latest'    => $meta['latest'],
        'path'      => $path,
        'jumpable'  => count($path) === 2,
        'changelog' => $latestEntry['changelog'] ?? [],
    ];

    // PHP 版：优先完整包（full）——任意旧版本可一步升到最新，无需维护多版本增量包；
    // 未发布 full 时退回 delta（兼容旧 versions.json）
    $web   = $latestEntry['web'] ?? [];
    $full  = $web['full'] ?? ($latestEntry['full'] ?? null);
    $delta = $web['deltas'][$cur] ?? null;
    if ($full) {
        $resp['package_url'] = $full['url'];
        $resp['package_md5'] = $full['md5'];
        $resp['size']        = $full['size'];
        $resp['full_pkg']    = true;
    } elseif ($delta) {
        $resp['package_url'] = $delta['url'];
        $resp['package_md5'] = $delta['md5'];
        $resp['size']        = $delta['size'];
    }
    // 两者都没有时不返回 package_url，前端提示「暂无可直接升级的包，请稍后重试或联系作者」

    ok($resp);
}

/* ==================== download ==================== */

if ($action === 'download') {
    set_time_limit(300);
    $url = trim(str_param('url', ''));
    $md5 = trim(str_param('md5', ''));
    if (!$url || !$md5) fail('参数缺失: url + md5');
    if (!function_exists('inflate_init')) {
        fail('服务器 PHP 缺少 zlib 扩展，无法在线解压升级包。请在宝塔 PHP 设置中安装/启用 zlib，或手动解压升级包覆盖站点根目录完成升级（切勿覆盖 backend/config.php）');
    }

    $token = bin2hex(random_bytes(8));
    $staging = $ROOT . '/frontend/uploads/upgrade_staging/' . $token;
    if (!@mkdir($staging, 0755, true)) fail('无法创建暂存目录');
    sp_ensure_upload_protected($staging);

    $tmp = $staging . '/download.zip';
    $downloaded = false;

    // 优先 curl
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = fopen($tmp, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_BUFFERSIZE     => 65536,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($httpCode === 200 && !$curlErr) {
            $downloaded = true;
        } else {
            @unlink($tmp);
        }
    }

    // 兜底 file_get_contents
    if (!$downloaded) {
        $content = @file_get_contents($url);
        if ($content !== false) {
            file_put_contents($tmp, $content);
            $downloaded = true;
        }
    }

    if (!$downloaded) {
        sp_upgrade_rm_rf($staging);
        fail('下载失败，请检查 URL 或服务器 allow_url_fopen / curl 支持');
    }

    // 验 MD5
    $got = md5_file($tmp);
    if (strtolower($got) !== strtolower($md5)) {
        sp_upgrade_rm_rf($staging);
        fail('MD5 校验失败，升级包可能被篡改');
    }

    // 解压 + 验 manifest（复用 upgrade.php 的逻辑）
    $reader = SpZipReader::open($tmp);
    if ($reader === null) {
        sp_upgrade_rm_rf($staging);
        fail('无法解析 zip 文件');
    }
    $mf = $reader->getStream('manifest.json');
    if ($mf === false) {
        $reader->close(); sp_upgrade_rm_rf($staging);
        fail('升级包缺少 manifest.json');
    }
    $manifestRaw = (string)stream_get_contents($mf); fclose($mf);
    $manifest    = sp_upgrade_validate_manifest(json_decode($manifestRaw, true));
    if ($manifest === null) {
        $reader->close(); sp_upgrade_rm_rf($staging);
        fail('manifest 无效');
    }
    file_put_contents($staging . '/manifest.json', $manifestRaw);

    $errors = [];
    $done   = sp_upgrade_extract_files($reader, $manifest['files'], $staging, $errors);
    $reader->close();
    @unlink($tmp);

    if ($done !== count($manifest['files']) || $errors) {
        sp_upgrade_rm_rf($staging);
        fail('升级包内容校验失败');
    }

    ok(['token' => $token, 'files' => $manifest['files']]);
}

fail('未知操作');
