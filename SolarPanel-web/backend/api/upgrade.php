<?php
/**
 * 升级包应用 / 清理（仅用于 version.php 一键下载后的应用链路）
 *   action=apply  读取 session 中暂存目录的 zip → 原子替换 → 成功后 opcache_reset() → 返回结果
 *   action=cancel 清理 session 中暂存目录
 *
 * 安全要点：路径白名单、每文件写入前真实权限预检、失败自动回滚（基于替换前备份）
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/upgrade.php';
require_roles('admin');

$action = str_param('action');

session_start();

if ($action === 'apply') {
    $token = str_param('token');
    if ($token === '') fail('缺少 token');

    $key = 'upgrade_stage_' . $token;
    if (empty($_SESSION[$key])) fail('升级会话不存在或已过期（请重新点「一键下载并升级」）');
    $sess = $_SESSION[$key];
    if (empty($sess['stage']) || !is_array($sess['manifest'])) fail('升级会话数据损坏');
    if (!empty($sess['created']) && (time() - $sess['created']) > 3600) {
        unset($_SESSION[$key]);
        fail('升级会话已过期（超过 1 小时），请重新点「一键下载并升级」');
    }

    $root = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    $stageDir = $sess['stage'];
    $zipPath = $stageDir . '/pkg.zip';
    $m = $sess['manifest'];
    $cur = sp_upgrade_version_from_apijs($root);

    // full 包跳过 from 版本链校验；delta 包需 from==当前版本
    if (empty($m['full']) && !empty($m['from']) && $m['from'] !== $cur) {
        sp_upgrade_rm_rf($stageDir);
        unset($_SESSION[$key]);
        fail('当前版本 ' . $cur . ' 不匹配升级包 from 版本 ' . $m['from']);
    }

    // 打开 zip
    $reader = SpZipReader::open($zipPath);
    if (!$reader) {
        sp_upgrade_rm_rf($stageDir);
        unset($_SESSION[$key]);
        fail('升级包损坏，无法打开');
    }

    // 路径 + 权限预检：遍历 manifest files 列出所有目标目录，真实创建测试
    $targetDirs = [];
    foreach ($m['files'] as $rel) {
        $okRel = sp_upgrade_safe_relpath($rel);
        if ($okRel === null) { $reader->close(); fail('升级包路径不合法：' . $rel); }
        if (!sp_upgrade_path_allowed($okRel)) { $reader->close(); fail('升级包路径不在允许范围：' . $rel); }
        $dir = dirname($okRel);
        if ($dir !== '.' && !isset($targetDirs[$dir])) $targetDirs[$dir] = true;
    }
    foreach ($targetDirs as $dir => $_) {
        $abs = $root . '/' . $dir;
        if (!is_dir($abs) && !@mkdir($abs, 0755, true)) {
            $reader->close();
            fail('无法创建目录：' . $dir . '（请检查站点目录权限）');
        }
        if (!is_writable($abs)) {
            $reader->close();
            fail('目录不可写：' . $dir . '（请将站点目录属主递归设为 PHP 运行用户，宝塔为 www）');
        }
    }

    $backupDir = $root . '/frontend/uploads/upgrade_backup/' . date('Ymd_His') . '_' . substr($token, 0, 8);
    @mkdir($backupDir, 0755, true);

    $replaced = []; // 成功替换的相对路径
    $failed = [];   // 失败 [{path, reason}]
    $updated = 0;

    foreach ($m['files'] as $rel) {
        $okRel = sp_upgrade_safe_relpath($rel);
        if ($okRel === null) { $failed[] = ['path' => $rel, 'reason' => '路径不合法']; continue; }
        if (!sp_upgrade_path_allowed($okRel)) { $failed[] = ['path' => $rel, 'reason' => '路径不在允许范围']; continue; }

        $abs = $root . '/' . $okRel;
        $data = $reader->read($okRel);
        if ($data === null) { $failed[] = ['path' => $okRel, 'reason' => '升级包缺少该条目']; continue; }

        // 若目标存在，先备份（相对原文件的相对副本，保留目录结构）
        if (file_exists($abs)) {
            $bkAbs = $backupDir . '/' . $okRel;
            @mkdir(dirname($bkAbs), 0755, true);
            if (!@copy($abs, $bkAbs)) {
                $failed[] = ['path' => $okRel, 'reason' => '备份失败']; continue;
            }
        }

        // 写入新内容
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $failed[] = ['path' => $okRel, 'reason' => '创建目录失败']; continue;
        }
        if (@file_put_contents($abs, $data) === false) {
            $failed[] = ['path' => $okRel, 'reason' => '写入失败']; continue;
        }
        $replaced[] = $okRel;
        $updated++;
    }
    $reader->close();

    // 处理 deleted 列表（若有）
    $deleted = 0;
    if (!empty($m['deleted']) && is_array($m['deleted'])) {
        foreach ($m['deleted'] as $rel) {
            $okRel = sp_upgrade_safe_relpath($rel);
            if ($okRel === null) continue;
            if (!sp_upgrade_path_allowed($okRel)) continue;
            $abs = $root . '/' . $okRel;
            if (is_file($abs)) {
                // 也备份一份再删
                $bkAbs = $backupDir . '/' . $okRel;
                @mkdir(dirname($bkAbs), 0755, true);
                @copy($abs, $bkAbs);
                @unlink($abs);
                $deleted++;
            }
        }
    }

    // 无论成功/失败都清暂存
    sp_upgrade_rm_rf($stageDir);
    unset($_SESSION[$key]);

    // 有失败 → 自动回滚
    if (!empty($failed)) {
        // 把 replaced 中已成功写入的恢复回备份（有备份的文件 copy 还原）
        foreach ($replaced as $rel) {
            $bkAbs = $backupDir . '/' . $rel;
            $abs = $root . '/' . $rel;
            if (file_exists($bkAbs)) {
                @copy($bkAbs, $abs);
            } else {
                // 备份不存在表示这是本次新建的文件，直接删掉
                @unlink($abs);
            }
        }
        // 把 deleted 中被删的从备份移回
        if (!empty($m['deleted'])) {
            foreach ($m['deleted'] as $rel) {
                $okRel = sp_upgrade_safe_relpath($rel);
                if ($okRel === null) continue;
                $bkAbs = $backupDir . '/' . $okRel;
                $abs = $root . '/' . $okRel;
                if (file_exists($bkAbs) && !file_exists($abs)) {
                    @mkdir(dirname($abs), 0755, true);
                    @rename($bkAbs, $abs);
                }
            }
        }
        // 清 OPcache + 版本检查缓存，避免升级后还显示旧 latest
        if (function_exists('opcache_reset')) @opcache_reset();
        @unlink(__DIR__ . '/updates_cache.json');

        json_out(2, [
            'failed'  => $failed,
            'updated' => max(0, $updated - count($replaced)), // 已回滚的不计入成功
            'backup'  => $backupDir,
        ], '部分文件升级失败，已自动回滚到原版本，站点未受影响。');
    }

    // 全成功：清 OPcache + 更新检测缓存
    if (function_exists('opcache_reset')) @opcache_reset();
    @unlink(__DIR__ . '/updates_cache.json');
    $newVer = sp_upgrade_version_from_apijs($root);
    ok([
        'updated' => $updated,
        'deleted' => $deleted,
        'version' => $newVer,
        'backup'  => $backupDir,
    ]);
}

if ($action === 'cancel') {
    $token = str_param('token');
    if ($token === '') fail('缺少 token');
    $key = 'upgrade_stage_' . $token;
    if (!empty($_SESSION[$key])) {
        $sess = $_SESSION[$key];
        if (!empty($sess['stage'])) sp_upgrade_rm_rf($sess['stage']);
        unset($_SESSION[$key]);
    }
    ok(null);
}

fail('未知 action：' . $action);

/* ===================== 辅助 ===================== */

/** 递归删除目录 */
function sp_upgrade_rm_rf(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        if ($entry->isDir()) @rmdir($entry->getPathname());
        else @unlink($entry->getPathname());
    }
    @rmdir($dir);
}
