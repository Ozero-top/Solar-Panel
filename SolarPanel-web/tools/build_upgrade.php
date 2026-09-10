<?php
/**
 * SolarPanel 升级包生成工具（CLI）
 *
 * 用法：
 *   php -d phar.readonly=0 tools/build_upgrade.php <旧版完整包.zip 或 旧版目录> [新版目录=项目根] [输出.zip]
 *
 * 示例（Windows，在项目目录下）：
 *   docker run --rm -v "%cd%:/app" -w /app php:8-cli php -d phar.readonly=0 tools/build_upgrade.php /old/SolarPanel-v0.10.0.0022.zip
 *
 * 行为：
 *   对比「旧版完整包」与「当前项目目录」的文件树（md5 逐文件对比），生成仅含
 *   变更 / 新增文件的升级包 zip，内含：
 *     manifest.json   升级清单（backend/api/upgrade.php 在线升级依据）
 *     <变更文件>       站点根相对路径
 *     升级说明.txt     手动解压用户的操作说明
 *
 * 排除规则（与 backend/lib/upgrade.php 的 sp_upgrade_path_allowed() 互为兜底）：
 *   - project_memory.md          开发专用记忆文件，不随包分发
 *   - backend/config.php         用户数据库配置，升级包绝不含（防止覆盖用户配置）
 *   - backend/api/install.php    安装入口，已装站点上不应复活
 *   - manifest.json / 升级说明.txt  防御：误把上一次的升级包当旧版输入时自动跳过
 *   - frontend/uploads/**        运行态文件（用户上传 / 缓存 / 升级会话），
 *                                仅放行目录守卫 index.html 与预置壁纸目录 weather/**
 *
 * 清单复核（build 时拦截，杜绝上线后被站点拒绝）：
 *   files / deleted 生成后，用【基线自带的起始版本 backend/lib/upgrade.php】在 PHP
 *   子进程复跑 sp_upgrade_path_allowed()——与站点在线升级校验同源。files 命中拒绝
 *   即终止；deleted 命中拒绝（基线残留的已废弃路径）自动剔除并警告。
 *
 * 注意：写 zip 需要 phar.readonly=0（php.ini 级 INI，无法运行时 ini_set）。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../backend/lib/upgrade.php';

const BUILD_EXCLUDE_EXACT = [
    'project_memory.md',
    'backend/config.php',
    'backend/api/install.php',
    'manifest.json',
    '升级说明.txt',
    'frontend/uploads/.htaccess', // 运行时生成的保护文件，不入包（部署端自动生成）
    // 以下为本地开发 / 部署工件，不随在线升级分发（站点根白名单本就只允许 index.html / favicon.ico / README.md）
    '.dockerignore',
    'Dockerfile',
    'docker-compose.yml',
    'docker/nginx.conf',
    'LICENSE',
    'SolarPanel.zip',
    'Solar Panel v1.0.001.zip',
];

$SELF = basename($argv[0] ?? 'build_upgrade.php');
$fullMode = ($argv[1] ?? '') === 'full';
if ($fullMode) {
    // 完整包：php build_upgrade.php full [新版目录=项目根] [输出.zip]
    $oldArg = '';
    $newArg = $argv[2] ?? dirname(__DIR__);
    $outArg = $argv[3] ?? '';
} else {
    $oldArg = $argv[1] ?? '';
    $newArg = $argv[2] ?? dirname(__DIR__);
    $outArg = $argv[3] ?? '';
}

if ((!$fullMode && $oldArg === '') || in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
    fwrite(STDERR, "用法:\n"
        . "  增量包: php -d phar.readonly=0 {$SELF} <旧版完整包.zip 或 旧版目录> [新版目录=项目根] [输出.zip]\n"
        . "  完整包: php -d phar.readonly=0 {$SELF} full [新版目录=项目根] [输出.zip]\n"
        . "完整包含全部代码文件（manifest full=true, from=v0.0.0），任意旧版可一步在线升级，发版推荐。\n");
    exit(1);
}

/** 统一路径：正斜杠 + 去尾部斜杠 */
function build_norm_dir(string $p): string
{
    $r = realpath($p);
    if ($r === false) {
        fwrite(STDERR, "错误：路径不存在或不可读：{$p}\n");
        exit(1);
    }
    return rtrim(str_replace('\\', '/', $r), '/');
}

/** build 排除规则（与 sp_upgrade_path_allowed 对齐，双端兜底） */
function build_excluded(string $rel): bool
{
    if (in_array($rel, BUILD_EXCLUDE_EXACT, true)) return true;
    // 服务器面板（宝塔等）自动生成 .user.ini（open_basedir 并 chattr +i 加锁），
    // 属服务器环境文件：升级覆盖会触发 Operation not permitted 整包回滚，也会破坏面板安全配置，一律不入包
    if (basename($rel) === '.user.ini') return true;
    if (strpos($rel, 'up/') === 0) return true; // 根目录升级包发布目录不入包
    if (strpos($rel, 'frontend/uploads/') === 0) {
        if ($rel === 'frontend/uploads/index.html') return false;
        if (strpos($rel, 'frontend/uploads/weather/') === 0) return false;
        return true; // 其余 uploads 运行态文件一律不入包
    }
    // 根目录下的 *.zip（完整包 / 旧升级包）一律不入包，避免版本号变更后重复登记
    if (strpos($rel, '/') === false && str_ends_with(strtolower($rel), '.zip')) return true;
    return false;
}

/** 递归收集目录文件树：rel => md5 */
function build_collect_tree(string $base, array &$warnings): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        $path = str_replace('\\', '/', $f->getPathname());
        if (is_link($path)) {
            $warnings[] = '跳过符号链接（不入包）：' . substr($path, strlen($base) + 1);
            continue;
        }
        if (!$f->isFile()) continue;
        $rel = substr($path, strlen($base) + 1);
        if (build_excluded($rel)) continue;
        if ($f->getSize() > 20 * 1024 * 1024) {
            $warnings[] = '跳过超过 20MB 的文件：' . $rel;
            continue;
        }
        $md5 = @md5_file($path);
        if ($md5 === false) {
            $warnings[] = '无法读取（跳过）：' . $rel;
            continue;
        }
        $out[$rel] = $md5;
    }
    return $out;
}

/** 从旧版包（zip 或目录）读取文件树：rel => md5 */
function build_read_old_tree(string $input, array &$warnings): array
{
    if (is_dir($input)) return build_collect_tree(build_norm_dir($input), $warnings);

    $reader = SpZipReader::open($input);
    if ($reader === null) {
        fwrite(STDERR, "错误：无法读取旧版包（不是有效的 zip 文件）：{$input}\n");
        exit(1);
    }
    $out = [];
    foreach ($reader->listNames() as $rel) {
        if (build_excluded($rel)) continue;
        $size = $reader->size($rel) ?? 0;
        if ($size > 20 * 1024 * 1024) {
            $warnings[] = '跳过超过 20MB 的文件：' . $rel;
            continue;
        }
        $fp = $reader->getStream($rel);
        if ($fp === false) {
            $warnings[] = '无法读取包内文件（跳过）：' . $rel;
            continue;
        }
        $content = stream_get_contents($fp);
        fclose($fp);
        $out[$rel] = md5((string)$content);
    }
    $reader->close();
    return $out;
}

/**
 * 取「起始版本」基线自带的 backend/lib/upgrade.php。
 * 目录基线直接返回原路径；zip 基线解压到临时文件（tmp 字段为待清理路径）。取不到返回 null。
 */
function build_old_lib_path(string $oldArg): ?array
{
    $rel = 'backend/lib/upgrade.php';
    if (is_dir($oldArg)) {
        $p = build_norm_dir($oldArg) . '/' . $rel;
        return is_file($p) ? ['path' => $p, 'tmp' => null] : null;
    }
    $reader = SpZipReader::open($oldArg);
    if ($reader === null) return null;
    $fp = $reader->getStream($rel);
    if ($fp === false) {
        $reader->close();
        return null;
    }
    $content = (string)stream_get_contents($fp);
    fclose($fp);
    $reader->close();
    $tmp = tempnam(sys_get_temp_dir(), 'spoldlib') . '.php';
    if (@file_put_contents($tmp, $content) === false) return null;
    return ['path' => $tmp, 'tmp' => $tmp];
}

/**
 * 在 PHP 子进程中用指定版本的 upgrade.php 纯函数库判定路径白名单——
 * 与站点在线升级时 sp_upgrade_validate_manifest() 的判定同源同规则
 * （站点用的是「当前已安装版本」的 lib，所以打包时必须拿基线 / 起始版本的 lib 复核）。
 * 入参为已规范化相对路径数组；返回 [rel => bool]，无法执行返回 null。
 */
function build_policy_check_with_lib(string $libPath, array $rels): ?array
{
    if (!is_file($libPath)) return null;
    $script = '<?php'
        . ' require ' . var_export($libPath, true) . ';'
        . ' $out = [];'
        . ' foreach (array_slice($argv, 1) as $p) {'
        . '   $r = sp_upgrade_safe_relpath($p);'
        . '   $out[$p] = ($r !== null && sp_upgrade_path_allowed($r));'
        . ' }'
        . ' echo json_encode($out);';
    $tmpScript = tempnam(sys_get_temp_dir(), 'spchk') . '.php';
    if (@file_put_contents($tmpScript, $script) === false) return null;

    $cmd = array_merge([PHP_BINARY, $tmpScript], $rels);
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        @unlink($tmpScript);
        return null;
    }
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($tmpScript);
    $map = json_decode(trim($out), true);
    return is_array($map) ? $map : null;
}

/** 宽松提取指定版本号在 APP_CHANGELOG 中的条目文案；失败返回空数组（不致命） */
function build_changelog_items(string $apiJs, string $ver): array
{
    $items = [];
    if (preg_match("/ver:\\s*'" . preg_quote($ver, '/') . "'[^\\[\\]]*?items:\\s*\\[(.*?)\\]\\s*,?\\s*\\}/s", $apiJs, $m)) {
        if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $mm)) {
            foreach ($mm[1] as $s) $items[] = trim($s);
        }
    }
    return $items;
}

$warnings = [];
$newBase = build_norm_dir($newArg);
$newTree = build_collect_tree($newBase, $warnings);
$newApiJs = (string)@file_get_contents($newBase . '/frontend/assets/js/api.js');
$to = sp_upgrade_version_from_apijs($newApiJs);
if ($to === null) {
    fwrite(STDERR, "错误：无法从新版 api.js 提取 APP_VERSION，请人工检查\n");
    exit(1);
}

if ($fullMode) {
    /* ---- 完整包：全部代码文件入包，任意旧版可一步升级（from 用 v0.0.0 占位） ---- */
    $from = 'v0.0.0';
    $files = array_keys($newTree);
    sort($files, SORT_STRING);
    $deleted = [];
    // 白名单用新版规则逐文件复核（full 包面向未来，规则最新最严）
    $badFiles = [];
    foreach ($files as $r) {
        $nr = sp_upgrade_safe_relpath($r);
        if ($nr === null || !sp_upgrade_path_allowed($nr)) $badFiles[] = $r;
    }
    if ($badFiles) {
        fwrite(STDERR, "错误：以下文件不在升级白名单内，完整包拒绝生成：\n");
        foreach ($badFiles as $r) fwrite(STDERR, "  - {$r}\n");
        exit(1);
    }
} else {
$oldTree = build_read_old_tree($oldArg, $warnings);

/* ---- 版本号提取（两侧都必须可读） ---- */
$oldApiJs = '';
if (is_dir($oldArg)) {
    $oldApiJs = (string)@file_get_contents(build_norm_dir($oldArg) . '/frontend/assets/js/api.js');
} else {
    $reader = SpZipReader::open($oldArg);
    if ($reader !== null) {
        $fp = $reader->getStream('frontend/assets/js/api.js');
        if ($fp !== false) {
            $oldApiJs = (string)stream_get_contents($fp);
            fclose($fp);
        }
        $reader->close();
    }
}

$from = sp_upgrade_version_from_apijs($oldApiJs);
if ($from === null) {
    fwrite(STDERR, "错误：无法从旧版 api.js 提取 APP_VERSION（from=" . var_export($from, true) . "），请人工检查\n");
    exit(1);
}

/* ---- 差异 ---- */
$files = [];
$deleted = [];
foreach ($newTree as $rel => $md5) {
    if (!isset($oldTree[$rel]) || $oldTree[$rel] !== $md5) $files[] = $rel;
}
foreach ($oldTree as $rel => $md5) {
    if (!isset($newTree[$rel])) $deleted[] = $rel;
}
sort($files, SORT_STRING);
sort($deleted, SORT_STRING);

if ($from === $to) {
    fwrite(STDERR, "错误：旧版与新版版本号相同（{$from}），请先在 api.js 中提升 APP_VERSION 再打包\n");
    exit(1);
}
if (!count($files) && !count($deleted)) {
    fwrite(STDERR, "两版本文件内容完全一致，无需生成升级包\n");
    exit(1);
}
if (!in_array('frontend/assets/js/api.js', $files, true)) {
    fwrite(STDERR, "异常：版本号 {$from} → {$to} 但 api.js 无变更，疑似版本提取有误，终止\n");
    exit(1);
}

/* ---- 清单策略校验：必须通过「起始版本（from）站点」的白名单 ----
 * 站点在线升级时，用的是【当前已安装版本】backend/lib/upgrade.php 的规则校验 manifest。
 * 若打包基线与起始版本不一致（典型：目录已从白名单移除并删除，如 tests/，却仍用含该
 * 目录的陈旧快照当基线），deleted 会携带目标站点拒绝的路径，上线即报
 * 「升级包清单无效或包含未授权路径」。这里用基线自带的旧版 lib 在子进程复跑同一规则，
 * build 时即拦截 / 剔除。旧版 lib 取不到时用当前 lib 兜底（更严格，宁可误拦）。 */
$oldLib = build_old_lib_path($oldArg);
$policyRels = array_merge($files, $deleted);
$policyMap = null;
if ($oldLib !== null) {
    $policyMap = build_policy_check_with_lib($oldLib['path'], $policyRels);
}
if ($policyMap === null) {
    fwrite(STDERR, "警告：无法运行起始版本的白名单校验（基线缺 lib 或 PHP 子进程不可用），改用当前版本规则兜底判定\n");
    $policyMap = [];
    foreach ($policyRels as $r) {
        $nr = sp_upgrade_safe_relpath($r);
        $policyMap[$r] = ($nr !== null && sp_upgrade_path_allowed($nr));
    }
}
if ($oldLib !== null && $oldLib['tmp'] !== null) @unlink($oldLib['tmp']);

$badFiles = [];
foreach ($files as $r) {
    if (!($policyMap[$r] ?? false)) $badFiles[] = $r;
}
if ($badFiles) {
    fwrite(STDERR, "错误：以下变更文件不在起始版本（{$from}）的升级白名单内，目标站点将拒绝该升级包：\n");
    foreach ($badFiles as $r) fwrite(STDERR, "  - {$r}\n");
    exit(1);
}
$staleDeleted = [];
$deleted = array_values(array_filter($deleted, static function ($r) use ($policyMap, &$staleDeleted) {
    if ($policyMap[$r] ?? false) return true;
    $staleDeleted[] = $r;
    return false;
}));
if ($staleDeleted) {
    fwrite(STDERR, "警告：以下废弃路径不在起始版本（{$from}）的升级白名单内（基线与起始版本不一致；"
        . "这些文件在目标站点上本就不存在或升级流程无权处理），已自动从 deleted 清单剔除：\n");
    foreach ($staleDeleted as $r) fwrite(STDERR, "  - {$r}\n");
}
} // end else（增量包模式）

$manifest = [
    'app' => 'SolarPanel',
    'type' => 'upgrade',
    'from' => $from,
    'to' => $to,
    'generated_at' => date('c'),
    'files' => $files,
    'deleted' => $deleted,
    'counts' => ['updated' => count($files), 'deleted' => count($deleted)],
];
if ($fullMode) $manifest['full'] = true;

/* ---- 输出路径 ---- */
$defaultName = $fullMode ? 'SolarPanel-full-' . $to . '.zip' : 'SolarPanel-upgrade-' . $from . '-to-' . $to . '.zip';
$out = $outArg !== '' ? $outArg : dirname($newBase) . '/' . $defaultName;
$outDir = dirname($out);
if (!is_dir($outDir)) {
    fwrite(STDERR, "错误：输出目录不存在：{$outDir}\n");
    exit(1);
}
if (is_file($out)) @unlink($out);

/* ---- 生成升级说明 ---- */
$clItems = build_changelog_items($newApiJs, $to);
$lines = [];
$lines[] = $fullMode ? ('SolarPanel 完整升级包：任意旧版 → ' . $to) : ('SolarPanel 升级包：' . $from . ' → ' . $to);
$lines[] = '生成时间：' . date('Y-m-d H:i:s');
$lines[] = '';
$lines[] = '【方式一：后台在线升级（推荐）】';
$lines[] = '1. 登录管理员账号，进入「站点设置 → 💾 备份与恢复 → 在线升级」；';
$lines[] = '2. 上传本升级包（.zip），核对变更预览后点击「确认升级」；';
$lines[] = '3. 升级完成后页面自动刷新，浏览器再按 Ctrl+F5 强制刷新缓存。';
$lines[] = '';
$lines[] = '【方式二：手动解压覆盖】';
$lines[] = '1. 将本压缩包内的全部文件解压到站点根目录（覆盖同名文件）；';
$lines[] = '2. 本包不含 backend/config.php，原有数据库配置保持不变，请勿从旧完整包复制该文件；';
$lines[] = '3. 本次升级包含 ' . count($files) . ' 个变更文件'
    . (count($deleted) ? '，需删除 ' . count($deleted) . ' 个废弃文件（清单见包内 manifest.json）' : '')
    . '；';
$lines[] = '4. 完成后浏览器按 Ctrl+F5 强制刷新缓存。';
$lines[] = '';
$lines[] = '【回滚】';
$lines[] = '在线升级时被替换 / 删除的原文件自动备份于站点 frontend/uploads/upgrade_backup/ 对应会话目录，';
$lines[] = '可手动复制回原位置恢复；或使用上一版完整包覆盖安装（分组 / 卡片 / 设置等数据库数据不受影响）。';
if ($clItems) {
    $lines[] = '';
    $lines[] = '【本版变更】';
    foreach ($clItems as $it) $lines[] = ' - ' . $it;
}
$readmeTxt = implode("\r\n", $lines) . "\r\n";

/* ---- 写 zip（PharData，条目名自拼保证正斜杠；写操作需 phar.readonly=0） ---- */
try {
    $phar = new PharData($out);
    foreach ($files as $rel) {
        $content = @file_get_contents($newBase . '/' . $rel);
        if ($content === false) {
            throw new RuntimeException('读取新版本文件失败：' . $rel);
        }
        $phar->addFromString($rel, $content);
    }
    $phar->addFromString('manifest.json', (string)json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $phar->addFromString('升级说明.txt', $readmeTxt);
    unset($phar); // 触发析构落盘
} catch (Exception $e) {
    @unlink($out);
    fwrite(STDERR, '错误：生成升级包失败：' . $e->getMessage() . "\n"
        . "若提示 phar.readonly 相关错误，请加启动参数：php -d phar.readonly=0 {$SELF} ...\n");
    exit(1);
}

/* ---- 自检：重新打开验证条目完整性 ---- */
$check = SpZipReader::open($out);
if ($check === null) {
    fwrite(STDERR, "错误：生成的升级包无法重新打开（损坏？）\n");
    exit(1);
}
$names = $check->listNames();
$problems = [];
foreach ($names as $n) {
    if (strpos($n, '\\') !== false || strpos($n, ':') !== false || $n[0] === '/' || strpos($n, '..') !== false) {
        $problems[] = '非法条目名：' . $n;
    }
}
foreach ($files as $rel) {
    if (!$check->has($rel)) $problems[] = '包内缺少清单文件：' . $rel;
}
$check->close();
if ($problems) {
    @unlink($out);
    foreach ($problems as $p) fwrite(STDERR, '  ' . $p . "\n");
    fwrite(STDERR, "错误：自检失败，已删除产物，请重试\n");
    exit(1);
}

/* ---- 摘要 ---- */
foreach ($warnings as $w) fwrite(STDERR, '警告：' . $w . "\n");
$abs = str_replace('\\', '/', $out);
fwrite(STDOUT, "升级包已生成：{$abs}\n"
    . '版本：' . $from . ' → ' . $to . "\n"
    . '变更文件 ' . count($files) . ' 个，废弃文件 ' . count($deleted) . ' 个，包体积 '
    . sprintf('%.2f', (float)filesize($out) / 1024) . " KB\n");
if ($deleted) {
    fwrite(STDOUT, "废弃文件（在线升级自动移入备份；手动覆盖请按 manifest.json 自行删除）：\n");
    foreach ($deleted as $d) fwrite(STDOUT, '  - ' . $d . "\n");
}
