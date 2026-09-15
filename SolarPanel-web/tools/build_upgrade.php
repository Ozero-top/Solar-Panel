<?php
/**
 * 升级包生成工具：full 完整包（from=v0.0.0，任意旧版本直升）
 *
 * 用法（Docker PHP CLI，本机无 php-cli 时用 PowerShell System.IO.Compression 等价打包，manifest schema 保持一致）：
 *   docker run --rm -v "c:\Users\Administrator\Desktop\SolarPanel-go:/work" -w /work/SolarPanel-web \
 *     php:8-cli php -d phar.readonly=0 tools/build_upgrade.php full /work/SolarPanel-web /work/SolarPanel-web/up/SolarPanel-full-vX.Y.Z.zip
 *
 * manifest schema（v2.0 起）：
 * {
 *   "app": "SolarPanel",          // ← 旧版 validate 硬要求
 *   "type": "upgrade",            // ← 旧版 validate 硬要求
 *   "ver": "v2.0.02",             // 新版本
 *   "from": "v0.0.0",             // full 包固定为 v0.0.0（旧版 validate 不限制具体值，只检查版本格式）
 *   "to": "v2.0.02",
 *   "full": true,
 *   "files": ["relative/path.ext", ...],
 *   "deleted": [],
 *   "counts": {"updated": N, "deleted": 0},   // ← 旧版 validate 硬要求：updated == count(files)，deleted == count(deleted)
 *   "generated_at": "ISO8601"
 * }
 */

if ($argc < 4) {
    fwrite(STDERR, "用法: php build_upgrade.php full <项目根目录> <输出zip>\n");
    exit(1);
}
$mode = $argv[1];
$root = rtrim($argv[2], '/\\');
$out  = $argv[3];

if ($mode !== 'full') {
    fwrite(STDERR, "仅支持 full 模式（完整包，from=v0.0.0）\n");
    exit(1);
}
if (!is_dir($root)) {
    fwrite(STDERR, "项目根目录不存在: $root\n");
    exit(1);
}

/** 版本提取（从 api.js 的 APP_VERSION 常量） */
function sp_version_from_apijs(string $root): string
{
    $p = $root . '/frontend/assets/js/api.js';
    if (!is_file($p)) return '';
    $s = @file_get_contents($p);
    if ($s === false || $s === '') return '';
    if (preg_match("/const\s+APP_VERSION\s*=\s*['\"]([^'\"]+)['\"]/", $s, $m)) return trim($m[1]);
    return '';
}

/** 路径安全三层校验（与 backend/lib/upgrade.php 的 sp_upgrade_path_allowed 保持一致） */
function sp_path_allowed(string $rel): bool
{
    // 硬拒绝
    if ($rel === 'backend/config.php') return false;
    if ($rel === 'backend/api/install.php') return false;
    if (basename($rel) === '.user.ini') return false;

    // 站点根白名单
    // 注意：manifest.json 不在白名单 —— 下面用 addFromString 写 PWA+升级混合格式
    if (in_array($rel, ['index.html', 'favicon.ico', 'README.md', 'sw.js'], true)) return true;
    if ($rel === 'frontend/offline.html') return true;
    if (strpos($rel, 'frontend/assets/img/') === 0) return true;

    // 顶层目录白名单
    foreach (['backend/', 'frontend/', 'sql/', 'tools/'] as $p) {
        if (strpos($rel, $p) === 0) {
            // uploads 运行态只放行 index.html 和预置 weather 目录
            if (strpos($rel, 'frontend/uploads/') === 0) {
                if ($rel === 'frontend/uploads/index.html') return true;
                if (strpos($rel, 'frontend/uploads/weather/') === 0) return true;
                return false;
            }
            return true;
        }
    }
    return false;
}

$ver = sp_version_from_apijs($root);
if ($ver === '') {
    fwrite(STDERR, "无法从 frontend/assets/js/api.js 提取版本号\n");
    exit(1);
}

/* ===================== 收集文件 ===================== */
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($it as $entry) {
    if (!$entry->isFile()) continue;
    $abs = $entry->getPathname();
    $rel = str_replace('\\', '/', substr($abs, strlen($root) + 1));
    if ($rel === '' || $rel === 'up' || str_starts_with($rel, 'up/')) continue;
    // 打包工具自身：tools/build_upgrade.php 和 tools/SolarPanel-full-*.zip
    if ($rel === 'tools/build_upgrade.php') continue; // 不让打包工具自己进包（循环依赖）
    if (preg_match('#^tools/SolarPanel-full-#', $rel)) continue;
    if (!sp_path_allowed($rel)) continue;
    $files[] = $rel;
}
sort($files, SORT_STRING);

// api.js 必须在包里（版本号前进的唯一权威来源）
if (!in_array('frontend/assets/js/api.js', $files, true)) {
    fwrite(STDERR, "打包失败：未找到 frontend/assets/js/api.js\n");
    exit(1);
}

/* ===================== 写 zip ===================== */
// manifest.json 是 addFromString 写入的混合格式，手动加进 files 数组让 apply 写入站点
$files[] = 'manifest.json';

$outDir = dirname($out);
if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
if (is_file($out)) @unlink($out);

try {
    $phar = new PharData($out, 0, basename($out));
} catch (Throwable $e) {
    fwrite(STDERR, "无法创建 zip（需 phar.readonly=0）: " . $e->getMessage() . "\n");
    exit(1);
}

$manifest = [
    'app'     => 'SolarPanel',
    'type'    => 'upgrade',
    'ver'     => $ver,
    'from'    => 'v0.0.0',
    'to'      => $ver,
    'full'    => true,
    'files'   => $files,
    'deleted' => [],
    'counts'  => ['updated' => count($files), 'deleted' => 0],
    'generated_at' => gmdate('c'),
];

// manifest.json — PWA 字段 + 升级字段混合（v2.0.01 旧版只认这个，浏览器 PWA 也认）
// 注意：不用 addFile（白名单已排除），用 addFromString 写混合格式
$pwaManifest = json_decode(file_get_contents($root . '/manifest.json'), true);
$mixedManifest = array_merge($pwaManifest ?: [], $manifest);
$phar->addFromString('manifest.json', json_encode($mixedManifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$phar->addFromString('upgrade-manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// manifest.json 不是从文件系统 addFile 进来的，是 addFromString 写的混合格式
foreach ($files as $rel) {
    if ($rel === 'manifest.json') continue;
    $phar->addFile($root . '/' . $rel, $rel);
}
unset($phar);

$md5 = strtolower(md5_file($out) ?: '');
$size = filesize($out);

echo "✅ 完整包生成完成\n";
echo "  输出: $out\n";
echo "  版本: $ver\n";
echo "  文件数: " . count($files) . "\n";
echo "  MD5:  $md5\n";
echo "  大小: " . number_format($size) . " bytes\n";
