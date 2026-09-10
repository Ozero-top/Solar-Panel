<?php
/**
 * 系统升级（仅管理员）
 * POST ?action=check   上传升级包（multipart: file=<升级包.zip>），校验并暂存，返回变更预览
 *                      预览含 token，用于后续 apply / cancel；暂存 1 小时后过期自动清理
 * POST ?action=apply   应用暂存的升级包（JSON / 表单: token）：
 *                      被替换 / 被删除的原文件先备份到 frontend/uploads/upgrade_backup/{token}/，
 *                      再逐文件「写入 .upgtmp → md5 完整性比对 → rename 原子替换」；
 *                      全部成功后清理暂存；部分失败保留现场并返回失败明细（code=2）
 * POST ?action=cancel  放弃升级会话（JSON / 表单: token），清理暂存目录
 *
 * 升级包由 tools/build_upgrade.php 生成（或按 manifest 结构自制）。
 * 安全设计（详见 backend/lib/upgrade.php 头注释）：
 *   - 清单式提取：只读取 manifest.files 列出的文件，包内其他条目一律忽略（防夹带）
 *   - 路径三层校验 + from 必须等于站点当前版本（现场从 api.js 读取）
 *   - apply 只信任暂存目录内 check 时固化的 manifest 快照（防 check 与 apply 之间二次上传篡改）
 *   - 暂存 / 备份目录写入 .htaccess 禁止脚本执行（sp_ensure_upload_protected）
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/upgrade.php';

// 防止 PHP 通知 / 警告混入 JSON 响应
ini_set('display_errors', '0');
@ini_set('memory_limit', '256M');

require_roles('admin');

$ROOT = realpath(__DIR__ . '/../..');
if ($ROOT === false) $ROOT = dirname(__DIR__, 2);
$apijsPath   = $ROOT . '/frontend/assets/js/api.js';
$stagingBase = $ROOT . '/frontend/uploads/upgrade_staging';
$backupBase  = $ROOT . '/frontend/uploads/upgrade_backup';
$action = str_param('action', '');

/** 现场读取站点当前版本号（唯一权威来源 api.js，不维护独立常量） */
$curVersion = static function () use ($apijsPath): ?string {
    $src = @file_get_contents($apijsPath);
    if ($src === false || $src === '') return null;
    return sp_upgrade_version_from_apijs($src);
};

/* ==================== check：校验 + 暂存 + 预览 ==================== */
if ($action === 'check') {
    $cur = $curVersion();
    if ($cur === null) fail('无法读取站点版本号（frontend/assets/js/api.js 缺失或损坏），请使用完整包覆盖安装');

    if (empty($_FILES['file'])) {
        // 请求体超过 post_max_size 时，$_POST / $_FILES 都不会被填充
        fail('未收到上传文件：升级包可能超过 PHP 的 post_max_size 限制，请在面板 / php.ini 调大 upload_max_filesize 和 post_max_size');
    }
    $upErr = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($upErr !== UPLOAD_ERR_OK) {
        fail($upErr === UPLOAD_ERR_INI_SIZE || $upErr === UPLOAD_ERR_FORM_SIZE
            ? '升级包超过 PHP 上传限制（upload_max_filesize），请在面板 / php.ini 调大后重试'
            : '文件上传失败（PHP 错误码 ' . $upErr . '）');
    }
    if (!is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
        fail('请选择升级包文件（.zip）');
    }
    if ($_FILES['file']['size'] > 64 * 1024 * 1024) {
        fail('升级包过大（超过 64MB）');
    }
    if (!function_exists('inflate_init')) {
        fail('服务器 PHP 缺少 zlib 组件，无法在线升级。请手动解压升级包覆盖站点根目录完成升级（切勿覆盖 backend/config.php）');
    }

    // 暂存 / 备份目录 + 防脚本执行 + 过期清理
    if (!is_dir($stagingBase) && !@mkdir($stagingBase, 0755, true)) fail('无法创建升级暂存目录，请检查 uploads 目录写入权限');
    if (!is_dir($backupBase) && !@mkdir($backupBase, 0755, true)) fail('无法创建升级备份目录，请检查 uploads 目录写入权限');
    sp_ensure_upload_protected($stagingBase);
    sp_ensure_upload_protected($backupBase);
    sp_upgrade_cleanup_stale($stagingBase, 3600);
    sp_upgrade_cleanup_stale($backupBase, 86400);

    $reader = SpZipReader::open($_FILES['file']['tmp_name']);
    if ($reader === null) fail('无法读取升级包（不是有效的 zip 文件）');

    $mf = $reader->getStream('manifest.json');
    if ($mf === false) {
        $reader->close();
        fail('升级包缺少 manifest.json，请使用 SolarPanel 升级工具生成的升级包');
    }
    $manifestRaw = (string)stream_get_contents($mf);
    fclose($mf);
    $manifest = sp_upgrade_validate_manifest(json_decode($manifestRaw, true));
    if ($manifest === null) {
        $reader->close();
        fail('升级包清单无效或包含未授权路径（非法的 manifest / files / deleted）');
    }
    $isFullPkg = !empty($manifest['full']);
    if (!$isFullPkg && $manifest['from'] !== $cur) {
        $reader->close();
        fail('升级包要求当前版本为 ' . $manifest['from'] . '，本站是 ' . $cur . '。请按顺序逐版升级，或下载完整包覆盖安装');
    }
    if ($manifest['to'] === $cur) {
        $reader->close();
        fail('本站已是 ' . $cur . '，无需升级');
    }

    // 包内容预检：条目数 / 清单文件存在性 / 单文件与总体积上限
    $names = $reader->listNames();
    if (count($names) > 500) {
        $reader->close();
        fail('升级包条目数异常（超过 500），拒绝处理');
    }
    $sum = 0;
    foreach ($manifest['files'] as $rel) {
        if (!$reader->has($rel)) {
            $reader->close();
            fail('升级包内缺少清单声明的文件：' . $rel);
        }
        $s = $reader->size($rel) ?? 0;
        if ($s > 20 * 1024 * 1024) {
            $reader->close();
            fail('升级包内单文件过大（超过 20MB）：' . $rel);
        }
        $sum += $s;
    }
    if ($sum > 200 * 1024 * 1024) {
        $reader->close();
        fail('升级包内容总体积超过 200MB 上限');
    }

    $token = bin2hex(random_bytes(8));
    $staging = $stagingBase . '/' . $token;
    if (!@mkdir($staging, 0755, true)) {
        $reader->close();
        fail('无法创建升级会话目录，请重试');
    }
    // 固化 manifest 快照：apply 只信暂存内这份，防 check 与 apply 之间二次上传篡改
    if (@file_put_contents($staging . '/manifest.json', $manifestRaw) === false) {
        $reader->close();
        sp_upgrade_rm_rf($staging);
        fail('无法写入升级会话数据，请重试');
    }

    $errors = [];
    $done = sp_upgrade_extract_files($reader, $manifest['files'], $staging, $errors);
    $reader->close();
    if ($done !== count($manifest['files']) || $errors) {
        sp_upgrade_rm_rf($staging);
        $first = $errors[0] ?? ['path' => '?', 'reason' => '未知错误'];
        fail('升级包内容校验失败：' . $first['path'] . ' — ' . $first['reason']);
    }

    ok([
        'token' => $token,
        'from' => $manifest['from'],
        'to' => $manifest['to'],
        'current' => $cur,
        'updated' => count($manifest['files']),
        'deleted' => count($manifest['deleted']),
        'files' => $manifest['files'],
        'deleted_files' => $manifest['deleted'],
    ]);
}

/* ==================== apply：备份原文件 + 原子替换 ==================== */
if ($action === 'apply') {
    $token = str_param('token', '');
    if (!preg_match('/^[a-f0-9]{16}$/', $token)) fail('参数错误：无效的升级会话标识');
    $staging = $stagingBase . '/' . $token;
    $mfPath = $staging . '/manifest.json';
    if (!is_dir($staging) || !is_file($mfPath)) {
        fail('升级会话已过期或不存在（暂存 1 小时后过期），请重新上传升级包');
    }
    $manifest = sp_upgrade_validate_manifest(json_decode((string)@file_get_contents($mfPath), true));
    if ($manifest === null) {
        sp_upgrade_rm_rf($staging);
        fail('升级会话数据损坏，请重新上传升级包');
    }
    $cur = $curVersion();
    $isFullPkg = !empty($manifest['full']);
    if ($cur === null || (!$isFullPkg && $manifest['from'] !== $cur)) {
        sp_upgrade_rm_rf($staging);
        fail('站点版本已变化（当前 ' . ($cur ?? '未知') . '），本次会话失效，请重新上传升级包');
    }

    // staging 文件齐全性（防半途缺文件导致站点进入混合状态）
    foreach ($manifest['files'] as $rel) {
        if (!is_file($staging . '/' . $rel)) {
            sp_upgrade_rm_rf($staging);
            fail('暂存数据不完整（缺少 ' . $rel . '），请重新上传升级包');
        }
    }

    // ===== 写权限预检（在改动任何文件之前）：真实写入探测每个目标目录，避免改一半再回滚 =====
    $spRunUser = 'PHP 运行用户';
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(@posix_geteuid());
        if (!empty($pw['name'])) $spRunUser = $pw['name'];
    }
    $spDirWritable = static function (string $dir): bool {
        if (!is_dir($dir)) return false;
        $probe = rtrim($dir, '/') . '/.spwtest_' . bin2hex(random_bytes(4));
        $w = @file_put_contents($probe, 't');
        if ($w !== false) { @unlink($probe); return true; }
        return false;
    };
    $checkDirs = [];
    foreach ($manifest['files'] as $rel) {
        $d = $ROOT . '/' . dirname($rel);
        // 目标子目录尚不存在时，向上找最近的已存在父目录（替换时会自动建子目录，但父目录必须可写）
        while (!is_dir($d) && $d !== $ROOT && dirname($d) !== $d) { $d = dirname($d); }
        $checkDirs[$d] = true;
    }
    $unwritable = [];
    foreach (array_keys($checkDirs) as $d) {
        if (!$spDirWritable($d)) {
            $unwritable[] = (strpos($d, $ROOT . '/') === 0) ? substr($d, strlen($ROOT) + 1) : $d;
        }
    }
    if ($unwritable) {
        sort($unwritable);
        $show = array_slice($unwritable, 0, 8);
        $more = count($unwritable) > 8 ? ' 等共 ' . count($unwritable) . ' 个目录' : '';
        fail('升级预检未通过：以下目录对「' . $spRunUser . '」用户不可写：' . implode('、', $show) . $more
            . '。解决：宝塔「文件」→ 选中站点根目录 → 右键「权限」，权限设 755、所有者设为 ' . $spRunUser
            . '（一般为 www），务必勾选「应用到子目录」，确定后回到后台重试。');
    }

    if (!is_dir($backupBase) && !@mkdir($backupBase, 0755, true)) fail('无法创建备份目录，请检查 uploads 写入权限');
    $backupDir = $backupBase . '/' . $token;
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) fail('无法创建本次升级的备份目录，请重试');

    // 提取最近一次 PHP 错误的可读文本（去掉 "copy(...): " 前缀），失败时给管理员真实原因而非笼统猜测
    $lastErrText = static function (): string {
        $m = trim((string)(error_get_last()['message'] ?? ''));
        if ($m === '') return '';
        $m = (string)preg_replace('/^[a-z_]+\([^)]*\):\s*/i', '', $m);
        return $m !== '' ? '：' . $m : '';
    };

    // 先替换非 backend 文件，backend 最后覆盖，尽量缩短混合版本窗口
    $ordered = $manifest['files'];
    usort($ordered, static function ($a, $b) {
        return (strpos($a, 'backend/') === 0 ? 1 : 0) - (strpos($b, 'backend/') === 0 ? 1 : 0);
    });

    $updated = 0;
    $failed = [];
    $replaced = []; // 成功落盘的文件（失败自动回滚用）
    foreach ($ordered as $rel) {
        $target = $ROOT . '/' . $rel;
        if (is_link($target)) {
            $failed[] = ['path' => $rel, 'reason' => '目标是符号链接，拒绝覆盖'];
            continue;
        }
        $td = dirname($target);
        if (!is_dir($td) && !@mkdir($td, 0755, true)) {
            $failed[] = ['path' => $rel, 'reason' => '无法创建目标目录' . $lastErrText()];
            continue;
        }
        // 目录可写性自救：属主是 Web 运行用户但权限不足时 chmod 即可修复；属主不符时无副作用
        if (!@is_writable($td)) {
            @chmod($td, 0755);
            clearstatcache(true, $td);
        }
        // 覆盖前备份原文件（失败即中止该文件，保留原状）
        if (is_file($target)) {
            $bd = dirname($backupDir . '/' . $rel);
            if (!is_dir($bd) && !@mkdir($bd, 0755, true)) {
                $failed[] = ['path' => $rel, 'reason' => '无法写入备份目录' . $lastErrText()];
                continue;
            }
            if (!@copy($target, $backupDir . '/' . $rel)) {
                $failed[] = ['path' => $rel, 'reason' => '备份原文件失败' . $lastErrText()];
                continue;
            }
        }
        // 写入链（目标目录可能对 Web 进程只读，逐级降级自救）：
        //   ① 目标目录内 tmp → md5 校验 → rename 原子替换（首选，重试 3 次抗瞬时占用）；rename 失败降级 copy 直写
        //   ② 目标目录仍写不进 → tmp 落在升级暂存目录（本程序自建、必然可写）→ md5 校验 → rename/copy 落位
        $src = $staging . '/' . $rel;
        $tmp = $target . '.upgtmp';
        $tmpS = $staging . '/.upgtmp_' . md5($rel);
        @unlink($tmp); // 清理上次失败残留（残留只读 / 被占用会让重试永远失败）
        @unlink($tmpS);
        error_clear_last();
        $ok = false;
        for ($try = 0; $try < 3; $try++) {
            if ($try > 0) usleep(300000);
            if (!@copy($src, $tmp)) continue;
            error_clear_last();
            if (md5_file($tmp) !== md5_file($src)) {
                @unlink($tmp);
                $failed[] = ['path' => $rel, 'reason' => '写入完整性校验失败'];
                continue 2;
            }
            if (@rename($tmp, $target) || @copy($tmp, $target)) $ok = true;
            @unlink($tmp);
            break;
        }
        if (!$ok) { // 降级：经暂存目录中转，rename 语义（同分区移动）绕开目标目录内建临时文件的写法
            error_clear_last();
            if (@copy($src, $tmpS)) {
                if (md5_file($tmpS) === md5_file($src)) {
                    error_clear_last();
                    if (@rename($tmpS, $target) || @copy($tmpS, $target)) $ok = true;
                }
                @unlink($tmpS);
            }
        }
        if (!$ok) {
            $relDir = (strpos($td, $ROOT . '/') === 0) ? substr($td, strlen($ROOT) + 1) : $td;
            $why = @is_writable($td)
                ? '磁盘空间不足，或文件被安全软件 / 面板占用'
                : 'Web 运行进程对目录 ' . $relDir . ' 无写权限（属主不是站点运行用户）——'
                . '请在面板将该目录及其子目录权限设为 755、属主设为站点运行用户（如 www）';
            $failed[] = ['path' => $rel, 'reason' => '替换文件失败（' . $why . '）' . $lastErrText()];
            continue;
        }
        $updated++;
        $replaced[] = $rel;
    }

    // 废弃文件：移入备份目录（移动而非删除，天然可回滚）
    $deletedCount = 0;
    $skipped = 0;
    foreach ($manifest['deleted'] as $rel) {
        $target = $ROOT . '/' . $rel;
        if (!is_file($target)) {
            $skipped++;
            continue;
        }
        $bd = dirname($backupDir . '/' . $rel);
        if (!is_dir($bd) && !@mkdir($bd, 0755, true)) {
            $failed[] = ['path' => $rel, 'reason' => '无法写入备份目录' . $lastErrText()];
            continue;
        }
        if (@rename($target, $backupDir . '/' . $rel) || @unlink($target)) {
            $deletedCount++;
        } else {
            $failed[] = ['path' => $rel, 'reason' => '删除文件失败' . $lastErrText()];
        }
    }
    sp_upgrade_prune_empty_dirs($ROOT, $manifest['deleted']);

    if ($failed) {
        // 自动回滚：已替换文件从备份还原；包内新增文件（站点原本没有）删除；已移除文件移回原位
        $rollbackFailed = [];
        foreach ($replaced as $rel) {
            $target = $ROOT . '/' . $rel;
            $bak = $backupDir . '/' . $rel;
            if (is_file($bak)) {
                if (!@copy($bak, $target)) $rollbackFailed[] = $rel;
            } elseif (is_file($target) && !@unlink($target)) {
                $rollbackFailed[] = $rel;
            }
        }
        foreach ($manifest['deleted'] as $rel) {
            $bak = $backupDir . '/' . $rel;
            $target = $ROOT . '/' . $rel;
            if (is_file($bak)) {
                $td = dirname($target);
                if (!is_dir($td)) @mkdir($td, 0755, true);
                if (!@rename($bak, $target) && !@copy($bak, $target)) $rollbackFailed[] = $rel;
            }
        }
        if (function_exists('opcache_reset')) { @opcache_reset(); }
        $rolledBack = count($rollbackFailed) === 0;
        json_out(2, [
            'failed' => $failed,
            'rolled_back' => $rolledBack,
            'rollback_failed' => $rollbackFailed,
            'updated' => $updated,
            'deleted' => $deletedCount,
            'backup' => 'frontend/uploads/upgrade_backup/' . $token,
        ], $rolledBack
            ? '部分文件升级失败，已自动回滚到原版本，站点未受影响。常见原因：站点目录对 PHP 运行用户不可写——请在面板将站点目录权限设为 755、属主设为站点运行用户（如 www）后重试。'
            : '部分文件升级失败，自动回滚未能完全还原，请用备份目录 frontend/uploads/upgrade_backup/' . $token . ' 手动恢复。');
    }

    sp_upgrade_rm_rf($staging);
    // 重置 OPcache（宝塔等环境默认开启），确保新代码立即生效，不必等 revalidate_freq 过期
    if (function_exists('opcache_reset')) { @opcache_reset(); }
    ok([
        'updated' => $updated,
        'deleted' => $deletedCount,
        'skipped' => $skipped,
        'failed' => [],
        'backup' => 'frontend/uploads/upgrade_backup/' . $token,
        'version' => $manifest['to'],
    ]);
}

/* ==================== cancel：放弃会话 ==================== */
if ($action === 'cancel') {
    $token = str_param('token', '');
    if (preg_match('/^[a-f0-9]{16}$/', $token) && is_dir($stagingBase . '/' . $token)) {
        sp_upgrade_rm_rf($stagingBase . '/' . $token);
    }
    ok(['cancelled' => true]);
}

fail('未知操作');
