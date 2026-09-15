<?php
/**
 * 在线更新检测 / 下载
 *   action=check   读更新源 versions.json，对比当前版本
 *   action=download 从 versions.json 返回的 URL 下载 zip 到临时目录、验 MD5、解压、验 manifest
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/upgrade.php';
require_roles('admin');

$action = str_param('action');
if ($action === 'check') {
    // 读更新源 versions.json（带 1h 服务端缓存）
    $cacheTtl = 3600;
    $cacheKey = __DIR__ . '/../updates_cache.json';
    $cacheOk = is_file($cacheKey) && (time() - @filemtime($cacheKey)) < $cacheTtl;
    $raw = $cacheOk ? @file_get_contents($cacheKey) : false;

    if (!$raw) {
        // versions.json 地址（配置化：settings 里无，硬编码 OSS 公共路径）
        $urls = [
            'https://updates.ozero.top/versions.json',
        ];
        $fetched = false;
        foreach ($urls as $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'SolarPanel-updater/2.0',
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $r = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($r !== false && $code === 200 && strlen($r) > 50) {
                $raw = $r;
                $fetched = true;
                break;
            }
        }
        if (!$fetched) fail('无法连接到更新源，请稍后再试');

        // 写入缓存（不阻塞，写前也去 BOM 防污染）
        if (strlen($raw) >= 3 && $raw[0] === "\xEF" && $raw[1] === "\xBB" && $raw[2] === "\xBF") {
            $raw = substr($raw, 3);
        }
        @file_put_contents($cacheKey, $raw, LOCK_EX);
    }

    // 防御：去 UTF-8 BOM（某些 CDN / 编辑器保存 JSON 会加 BOM）
    if (strlen($raw) >= 3 && $raw[0] === "\xEF" && $raw[1] === "\xBB" && $raw[2] === "\xBF") {
        $raw = substr($raw, 3);
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['latest'])) fail('更新源返回格式错误');

    $cur = sp_upgrade_version_from_apijs(__DIR__ . '/../..');
    $latest = (string)$data['latest'];
    $avail = false;
    $entry = null;
    if (!empty($data['versions'][$latest])) {
        $entry = $data['versions'][$latest];
        $avail = version_compare(ltrim($latest, 'v'), ltrim($cur ?: '0.0.0', 'v'), '>');
    }

    $out = [
        'current'  => $cur,
        'latest'   => $latest,
        'available' => $avail,
        'path'     => '',
        'full_pkg' => !empty($entry['web']['full']),
    ];
    if ($avail && is_array($entry)) {
        $web = $entry['web'] ?? [];
        $full = $web['full'] ?? null;
        if ($full && !empty($full['url']) && !empty($full['md5'])) {
            $out['package_url'] = $full['url'];
            $out['package_md5'] = $full['md5'];
            $out['size'] = (int)($full['size'] ?? 0);
        }
        $out['changelog'] = !empty($entry['changelog']) ? $entry['changelog'] : [];
        if (!empty($entry['date'])) $out['date'] = $entry['date'];
    }
    ok($out);
}

if ($action === 'download') {
    // 从更新源下载 zip → 临时目录 → 验 MD5 → 解压 → 验 manifest
    $url = str_param('url');
    $md5 = str_param('md5');
    if ($url === '' || $md5 === '') fail('缺少升级包 url 或 md5 参数');
    // 只允许 http(s) 外部地址
    if (!sp_url_is_public($url)) fail('升级包地址不合法');
    if (!extension_loaded('zlib')) fail('PHP 缺少 zlib 扩展，无法解压升级包');

    $root = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    $stageDir = $root . '/frontend/uploads/upgrade_staging_' . bin2hex(random_bytes(6));
    if (!@mkdir($stageDir, 0755, true)) fail('无法创建升级暂存目录');
    $zipPath = $stageDir . '/pkg.zip';

    // 下载
    $ch = curl_init($url);
    $fh = fopen($zipPath, 'wb');
    if (!$fh) fail('无法写入升级包暂存文件');
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'SolarPanel-updater/2.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    if ($httpCode !== 200) fail('下载升级包失败（HTTP ' . $httpCode . '）');

    // MD5
    $act = strtolower(md5_file($zipPath) ?: '');
    if ($act !== strtolower($md5)) {
        @unlink($zipPath);
        @rmdir($stageDir);
        fail('升级包 MD5 校验失败（期望 ' . $md5 . '，实际 ' . $act . '）');
    }

    // 解压 + manifest 校验（复用 upgrade.php 的 apply 流程读取，仅暂存、不写入）
    $reader = SpZipReader::open($zipPath);
    if (!$reader) {
        @unlink($zipPath);
        fail('升级包损坏或无法打开');
    }
    // 优先 upgrade-manifest.json（避免与站点 PWA manifest.json 同名冲突），回退 manifest.json 兼容旧包
    $manifest = $reader->read('upgrade-manifest.json');
    if (!$manifest) $manifest = $reader->read('manifest.json');
    if (!$manifest) {
        $reader->close();
        fail('升级包缺少 upgrade-manifest.json / manifest.json');
    }
    $m = json_decode($manifest, true);
    $err = sp_upgrade_validate_manifest($m);
    $reader->close();
    if ($err !== null) fail($err);

    // 把暂存路径塞到 session（upgrade.php?action=apply 用）
    session_start();
    $tk = bin2hex(random_bytes(16));
    $_SESSION['upgrade_stage_' . $tk] = [
        'stage' => $stageDir,
        'manifest' => $m,
        'created' => time(),
    ];
    // 清理超过 1h 的旧会话
    foreach ($_SESSION as $k => $v) {
        if (str_starts_with($k, 'upgrade_stage_') && is_array($v) && !empty($v['created']) && (time() - $v['created']) > 3600) {
            unset($_SESSION[$k]);
        }
    }
    ok(['token' => $tk, 'to' => $m['to']]);
}

fail('未知 action：' . $action);
