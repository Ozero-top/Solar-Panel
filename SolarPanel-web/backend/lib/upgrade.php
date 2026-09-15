<?php
/**
 * 升级包校验、解压、路径安全等公共函数
 *
 * 升级包结构（tools/build_upgrade.php 生成）：
 *   manifest.json  {"ver":"vX.Y.Z","from":"vX.Y.Z","files":["rel/path.ext",...],"deleted":[...],?full:true}
 *   <entry>         zip 条目名与 files 对应（正斜杠，无前导 ./）
 *
 * 安全三层：sp_upgrade_safe_relpath（无 .. / 绝对 / 反斜杠）
 *          + sp_upgrade_path_allowed（白名单：backend/api, backend/lib, frontend/assets, frontend/index.html...）
 *          + sp_upgrade_validate_manifest（版本链 + manifest.json 结构）
 * 硬拒 backend/config.php 与 backend/api/install.php（防止覆盖运行态配置 / 重新安装入口）
 */

/* ===================== manifest 与版本 ===================== */

/** 从 frontend/assets/js/api.js 中提取 APP_VERSION（如 'v2.0.01'） */
function sp_upgrade_version_from_apijs(string $root): string
{
    $path = rtrim($root, '/\\') . '/frontend/assets/js/api.js';
    if (!is_file($path)) return '';
    $s = @file_get_contents($path);
    if ($s === false) return '';
    if (preg_match("/APP_VERSION\s*=\s*['\"]([^'\"]+)['\"]/", $s, $m)) return trim($m[1]);
    return '';
}

/* ===================== 路径安全 ===================== */

/**
 * 清洗并校验相对路径：拒绝绝对路径、.. 反斜杠、控制字符、空片段
 * 返回规范化正斜杠相对路径；不合法返回 null
 */
function sp_upgrade_safe_relpath(string $p): ?string
{
    $p = str_replace('\\', '/', $p);
    if (strpos($p, ':') !== false) return null;
    if (strlen($p) > 240) return null;
    $p = trim($p, '/');
    if ($p === '') return null;
    if (strpos($p, '..') !== false) return null;
    if (preg_match('#[\x00-\x1f\x7f]#', $p)) return null;
    $segs = explode('/', $p);
    foreach ($segs as $s) {
        if ($s === '' || $s === '.' || $s === '..') return null;
    }
    return $p;
}

/**
 * 升级包路径白名单：允许覆盖 / 新增的路径前缀
 * 硬拒：backend/config.php（用户数据库配置）
 *      backend/api/install.php（安装向导入口，防止被重新触发）
 *      backend/api/.user.ini（宝塔 chattr +i 锁定，覆盖必 Operation not permitted）
 * 运行态数据目录（frontend/uploads/**、backend/updates/** 等）默认拒绝，除非 uploads/index.html
 */
function sp_upgrade_path_allowed(string $rel): bool
{
    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, './');

    $hard_deny = [
        'backend/config.php',
        'backend/api/install.php',
        'backend/api/.user.ini',
    ];
    foreach ($hard_deny as $d) {
        if ($rel === $d) return false;
    }

    // 运行态目录默认拒绝（防止覆盖用户上传 / 运行数据）
    $deny_prefixes = [
        'frontend/uploads/',
        'backend/updates/',
    ];
    foreach ($deny_prefixes as $pfx) {
        if (strpos($rel, $pfx) === 0) {
            // 程序自带预置资源放行：uploads/index.html 空占位 + uploads/weather/ 默认天气背景图
            if ($rel === 'frontend/uploads/index.html') return true;
            if (strpos($rel, 'frontend/uploads/weather/') === 0) return true;
            return false;
        }
    }

    // 白名单前缀
    $allow_prefixes = [
        'backend/api/',
        'backend/lib/',
        'frontend/assets/',
        'frontend/index.html',
        'frontend/login.html',
        'frontend/admin.html',
        'frontend/favicon.ico',
        'frontend/.htaccess',
        'frontend/offline.html',       // v2.0.04+ PWA 离线兜底
        'frontend/uploads/index.html',
        'frontend/uploads/weather/',  // 程序预置默认天气背景图（build_upgrade.php 同步放行）
        'sql/',                        // 数据库初始化脚本（build_upgrade.php 同步放行）
        '.htaccess',
        'favicon.ico',
        'index.html',
        'sw.js',                       // v2.0.04+ PWA Service Worker（根目录）
        'manifest.json',               // v2.0.04+ PWA manifest（根目录）
    ];
    foreach ($allow_prefixes as $pfx) {
        if (strpos($rel, $pfx) === 0) return true;
    }

    return false;
}

/* ===================== manifest 校验 ===================== */

/**
 * 校验 manifest.json 结构与版本链
 *   - required: ver / to / files
 *   - optional: from / deleted / min_from / full
 *   - 版本号格式：v\d+(\.\d+){2,3}
 * 返回 null 表示通过；否则返回错误字符串
 */
function sp_upgrade_validate_manifest($m): ?string
{
    if (!is_array($m)) return 'manifest.json 格式错误：非 JSON 对象';
    foreach (['ver', 'to', 'files'] as $k) {
        if (!isset($m[$k])) return 'manifest.json 缺少字段：' . $k;
    }
    $ver_re = '/^v\d+(\.\d+){2,3}$/';
    if (!is_string($m['ver']) || !preg_match($ver_re, $m['ver'])) return 'manifest.json 字段 ver 不合法';
    if (!is_string($m['to']) || !preg_match($ver_re, $m['to'])) return 'manifest.json 字段 to 不合法';
    if (!empty($m['from'])) {
        if (!is_string($m['from']) || !preg_match($ver_re, $m['from'])) return 'manifest.json 字段 from 不合法';
    }
    if ($m['ver'] !== $m['to']) return 'manifest.json 字段 ver 必须等于 to';
    if (!is_array($m['files']) || empty($m['files'])) return 'manifest.json files 为空';
    foreach ($m['files'] as $f) {
        if (!is_string($f) || trim($f) === '') return 'manifest.json files 含空条目';
    }
    if (!empty($m['deleted']) && !is_array($m['deleted'])) return 'manifest.json deleted 必须是数组';

    // full 包标记
    $m['full'] = !empty($m['full']);

    return null;
}

/* ===================== Zip 读取 ===================== */

/** 精简 ZipReader（PharData 为 PHP 核心扩展，无需额外安装） */
class SpZipReader
{
    private PharData $phar;
    private array $entries = [];

    public static function open(string $zip): ?self
    {
        if (!class_exists('PharData')) return null;
        if (!is_file($zip) || !is_readable($zip)) return null;
        try {
            $phar = new PharData($zip);
        } catch (Throwable $e) {
            return null;
        }
        $me = new self();
        $me->phar = $phar;
        // PharData 默认迭代器只列出顶层条目，必须 RecursiveIteratorIterator 才能遍历所有文件
        $it = new RecursiveIteratorIterator($phar);
        foreach ($it as $entry) {
            if ($entry->isFile()) {
                // getFilename() 只返回 basename（如 auth.php），需要从 getPathname() 里提取 zip 内部完整路径
                // getPathname() 格式：phar://<zip路径>/backend/api/auth.php
                $pathname = $entry->getPathname();
                // 去掉 phar://<zip绝对路径>/ 前缀
                $prefix = 'phar://' . realpath($zip) . '/';
                if (strpos($pathname, $prefix) === 0) {
                    $name = substr($pathname, strlen($prefix));
                } else {
                    // fallback：正则匹配 phar://.../ 之后的内容
                    $name = preg_replace('#^phar://[^/]+/#', '', $pathname);
                }
                $name = str_replace('\\', '/', $name);
                $me->entries[$name] = $entry;
            }
        }
        return $me;
    }

    /** 返回 zip 条目名数组（正斜杠，无前导） */
    public function files(): array
    {
        return array_keys($this->entries);
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    public function read(string $name): ?string
    {
        if (!isset($this->entries[$name])) return null;
        $e = $this->entries[$name];
        try {
            // PHP 8.4+ 移除了 PharFileInfo::open()，改用 file_get_contents(phar://...)
            $pathname = $e->getPathname();
            $data = @file_get_contents($pathname);
            if ($data === false) return null;
            return $data;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function close(): void
    {
        unset($this->phar);
    }
}
