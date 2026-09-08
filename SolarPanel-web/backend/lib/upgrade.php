<?php
/**
 * 系统升级纯函数库（不依赖数据库与会话，可独立单测）
 *
 * 升级包结构（由 tools/build_upgrade.php 生成）：
 *   manifest.json  { app:"SolarPanel", type:"upgrade", from, to, files:[站点根相对路径...],
 *                    deleted:[...], counts:{updated,deleted}, generated_at }
 *   <files 中列出的文件，路径相对站点根>
 *   升级说明.txt   仅供手动解压用户阅读，在线升级不处理
 *
 * 安全要点：
 *   - 路径三层校验：物理层（safe_relpath 拒穿越）→ 策略层（顶层白名单）→ 清单层（只提取 manifest.files 列出的文件）
 *   - backend/config.php 与 backend/api/install.php 永不参与升级
 *     （覆盖 config.php 会破坏用户数据库配置；重新部署 install.php 会让安装入口复活）
 *   - frontend/uploads/ 仅放行守卫 index.html 与随系统分发的预置壁纸目录 weather/**
 */

/* ==================== 基础校验 ==================== */

/** 从 api.js 源码提取 APP_VERSION 常量值；取不到返回 null */
function sp_upgrade_version_from_apijs(string $src): ?string
{
    if (preg_match("/const\\s+APP_VERSION\\s*=\\s*['\"]([^'\"]+)['\"]/", $src, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * 校验并规范化升级文件相对路径：反斜杠归一为斜杠、剥离 '.' 段与 './' 前缀、
 * 拒绝空路径 / 绝对路径 / 盘符 / '..' 穿越；非法返回 null，合法返回规范化路径。
 * 与 backup.php 的 backup_safe_relpath 同强度（独立实现，互不影响）。
 */
function sp_upgrade_safe_relpath(string $path): ?string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || $path[0] === '/' || strpos($path, ':') !== false) return null;
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') return null;
        $parts[] = $seg;
    }
    if (!$parts) return null;
    return implode('/', $parts);
}

/**
 * 策略层白名单：只允许升级站点代码与资源，运行态文件（用户上传、缓存）与敏感文件一律拒绝。
 * 注意与 tools/build_upgrade.php 的排除规则保持一致（两侧互为兜底）。
 */
function sp_upgrade_path_allowed(string $rel): bool
{
    // 硬拒绝：数据库配置与安装入口不参与升级
    if ($rel === 'backend/config.php' || $rel === 'backend/api/install.php') return false;
    // 站点根文件
    if ($rel === 'index.html' || $rel === 'favicon.ico' || $rel === 'README.md') return true;
    foreach (['backend/', 'frontend/', 'sql/', 'tools/'] as $p) {
        if (strpos($rel, $p) === 0) {
            // uploads 内是运行态文件（用户上传 / 缓存 / 升级会话自身），
            // 仅放行目录守卫与随系统分发的预置壁纸目录
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

/**
 * 校验并规范化升级包 manifest；非法返回 null，合法返回结构化数组。
 * 强制要求 files 含 frontend/assets/js/api.js（版本号必须前进，否则后续升级链断裂）。
 */
function sp_upgrade_validate_manifest($m): ?array
{
    if (!is_array($m)) return null;
    if (($m['app'] ?? '') !== 'SolarPanel' || ($m['type'] ?? '') !== 'upgrade') return null;
    $from = (string)($m['from'] ?? '');
    $to   = (string)($m['to'] ?? '');
    // 版本号格式：v + 3 或 4 段数字（兼容 v1.0.001 三段式 与 v0.10.0.0022 四段式）
    if (!preg_match('/^v\\d+(\\.\\d+){2,3}$/', $from) || !preg_match('/^v\\d+(\\.\\d+){2,3}$/', $to)) return null;
    if ($from === $to) return null;
    $files   = $m['files'] ?? null;
    $deleted = $m['deleted'] ?? null;
    if (!is_array($files) || !is_array($deleted) || !count($files)) return null;
    $cleanFiles = [];
    foreach ($files as $f) {
        if (!is_string($f)) return null;
        $rel = sp_upgrade_safe_relpath($f);
        if ($rel === null || !sp_upgrade_path_allowed($rel)) return null;
        $cleanFiles[] = $rel;
    }
    if (count($cleanFiles) !== count(array_unique($cleanFiles))) return null;
    if (!in_array('frontend/assets/js/api.js', $cleanFiles, true)) return null;
    $cleanDel = [];
    foreach ($deleted as $f) {
        if (!is_string($f)) return null;
        $rel = sp_upgrade_safe_relpath($f);
        if ($rel === null || !sp_upgrade_path_allowed($rel)) return null;
        $cleanDel[] = $rel;
    }
    $counts = $m['counts'] ?? null;
    if (!is_array($counts)) return null;
    if ((int)($counts['updated'] ?? -1) !== count($cleanFiles)) return null;
    if ((int)($counts['deleted'] ?? -1) !== count($cleanDel)) return null;
    return [
        'from' => $from,
        'to' => $to,
        'files' => $cleanFiles,
        'deleted' => $cleanDel,
        'generated_at' => (string)($m['generated_at'] ?? ''),
    ];
}

/* ==================== zip 读取（纯 PHP，零扩展依赖） ==================== */

/**
 * 手写 zip 读取器：解析中央目录（EOCD → PK\x01\x02），逐条目读取并解压
 * （method 0 stored / 8 deflate，zlib 为 PHP 默认内置）。
 *
 * 为什么不用 ZipArchive / PharData：
 *   - ZipArchive 依赖 pecl zip + libzip（php:8 官方镜像等环境缺失）
 *   - PharData 对 './' 前缀条目（Linux `zip -r x.zip .` 风格）迭代产出为空、
 *     bare './' 目录条目导致 extractTo 抛异常、恶意条目在迭代中途抛 RuntimeException
 *   - 手写解析只有一条代码路径，行为完全确定且可用单元测试覆盖
 *
 * 安全与健壮性设计：
 *   - 只依赖中央目录的元数据（流式写入工具把真实尺寸放在中央目录，本地头不可信）
 *   - 条目名统一过 sp_upgrade_safe_relpath：'./' 前缀剥离、反斜杠归一、穿越/盘符条目直接过滤
 *   - 目录条目（尾部 '/'）、加密条目（flag bit0）、未知压缩方式条目跳过
 *   - 解压输出与读取字节双重 20MB 上限（防解压炸弹），中央目录条目数上限 10000
 *   - CRC32 逐条目校验，损坏数据 getStream 返回 false
 */
final class SpZipReader
{
    private const ENTRY_CAP = 10000;
    private const CONTENT_CAP = 20 * 1024 * 1024;

    /** @var resource|null */
    private $fp = null;
    private int $fileSize = 0;
    /** @var array<string, array{method:int, csize:int, usize:int, crc:string, offset:int}> 规范化名 => 条目 */
    private $entries = [];

    private function __construct()
    {
    }

    public function __destruct()
    {
        $this->close();
    }

    /** 打开 zip 文件；结构无法解析返回 null（合法但无文件条目的 zip 允许打开） */
    public static function open(string $file): ?self
    {
        if (!is_file($file)) return null;
        $size = @filesize($file);
        if ($size === false || $size < 22) return null;
        $fp = @fopen($file, 'rb');
        if ($fp === false) return null;
        $obj = new self();
        $obj->fp = $fp;
        $obj->fileSize = (int)$size;
        if (!$obj->parseCentralDirectory()) {
            $obj->close();
            return null;
        }
        return $obj;
    }

    /** 解析 EOCD 与中央目录；填充 $entries。结构性异常返回 false */
    private function parseCentralDirectory(): bool
    {
        // EOCD 定位：从文件尾部向前扫签名（zip 注释最长 65535 字节）
        $scanLen = min($this->fileSize, 22 + 65535);
        if (@fseek($this->fp, $this->fileSize - $scanLen) !== 0) return false;
        $tail = (string)fread($this->fp, $scanLen);
        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false || strlen($tail) - $pos < 22) return false;
        $h = unpack('vdisk1/vcdisk/vn1/vn2/Vcdsize/Vcdoffset', (string)substr($tail, $pos + 4, 18));
        if ($h['n1'] !== $h['n2'] || $h['n1'] < 0) return false;
        $count = (int)$h['n1'];
        if ($count > self::ENTRY_CAP) return false;
        $cdOff = (int)$h['cdoffset'];
        $cdSize = (int)$h['cdsize'];
        if ($cdSize < 0 || $cdOff < 0 || $cdOff + $cdSize > $this->fileSize) return false;
        if (@fseek($this->fp, $cdOff) !== 0) return false;
        $cd = (string)fread($this->fp, $cdSize);
        if (strlen($cd) !== $cdSize) return false;

        $off = 0;
        for ($i = 0; $i < $count; $i++) {
            if (strlen($cd) - $off < 46) return false;
            if (substr($cd, $off, 4) !== "PK\x01\x02") return false;
            // 中央头字段布局：sig(4) made(2) need(2) flag(2) method(2) mtime(2) mdate(2)
            //                  crc(4) csize(4) usize(4) nlen(2) elen(2) cmt(2) disk(2) iattr(2) eattr(4) lhoff(4) = 46 字节
            $r = unpack(
                'vmade/vneed/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnlen/velen/vcmt/vdisk/viattr/Veattr/Vlhoff',
                (string)substr($cd, $off + 4, 42)
            );
            $name = (string)substr($cd, $off + 46, $r['nlen']);
            $off += 46 + $r['nlen'] + $r['elen'] + $r['cmt'];
            if ($off > strlen($cd)) return false;
            if (($r['flag'] & 1) !== 0) continue;                 // 加密条目：跳过
            if ($r['method'] !== 0 && $r['method'] !== 8) continue; // 未知压缩方式：跳过
            if ($name === '' || substr($name, -1) === '/') continue; // 目录条目：跳过
            $norm = sp_upgrade_safe_relpath($name);
            if ($norm === null) continue;                          // 穿越 / 盘符 / 非法名：过滤
            $this->entries[$norm] = [
                'method' => (int)$r['method'],
                'csize'  => (int)$r['csize'],
                'usize'  => (int)$r['usize'],
                'crc'    => pack('V', (int)$r['crc']), // CRC 原始 4 字节（V 往返无损，规避 32 位符号差异）
                'offset' => (int)$r['lhoff'],
            ];
        }
        return true;
    }

    /** 全部文件条目（规范化路径，升序） */
    public function listNames(): array
    {
        $names = array_keys($this->entries);
        sort($names, SORT_STRING);
        return $names;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** 条目解压后大小（字节）；条目不存在返回 null */
    public function size(string $name): ?int
    {
        return isset($this->entries[$name]) ? $this->entries[$name]['usize'] : null;
    }

    /**
     * 条目内容流（只读 php://temp resource）；用完须 fclose。
     * 条目不存在 / 读取失败 / CRC 不符 / 超过内容上限返回 false。
     */
    public function getStream(string $name)
    {
        $content = $this->readEntry($name);
        if ($content === null) return false;
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) return false;
        fwrite($fp, $content);
        rewind($fp);
        return $fp;
    }

    /** 读取并解压单个条目（含 CRC 校验与上限防护）；失败返回 null */
    private function readEntry(string $name): ?string
    {
        if ($this->fp === null || !isset($this->entries[$name])) return null;
        $e = $this->entries[$name];
        if ($e['usize'] < 0 || $e['usize'] > self::CONTENT_CAP) return null; // 声明尺寸超限（check 阶段会先用 size() 给出明确报错）

        // 本地文件头：只取名字长度与扩展字段长度（数据偏移），其余信任中央目录
        if (@fseek($this->fp, $e['offset']) !== 0) return null;
        $hdr = (string)fread($this->fp, 30);
        if (strlen($hdr) < 30 || substr($hdr, 0, 4) !== "PK\x03\x04") return null;
        $lh = unpack('vneed/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnlen/velen', (string)substr($hdr, 4, 26));
        $dataOff = $e['offset'] + 30 + $lh['nlen'] + $lh['elen'];
        if ($dataOff + $e['csize'] > $this->fileSize) return null; // 数据越界（截断 / 伪造包）
        if (@fseek($this->fp, $dataOff) !== 0) return null;

        if ($e['method'] === 0) {
            $data = (string)fread($this->fp, min($e['csize'], self::CONTENT_CAP + 1));
        } else {
            // deflate：分块解压，输出超限即中止（防解压炸弹）
            $ctx = inflate_init(ZLIB_ENCODING_RAW);
            if ($ctx === false) return null;
            $data = '';
            $done = 0;
            while ($done < $e['csize']) {
                $chunk = (string)fread($this->fp, (int)min(65536, $e['csize'] - $done));
                if ($chunk === '') break;
                $done += strlen($chunk);
                $out = @inflate_add($ctx, $chunk);
                if ($out === false) return null;
                $data .= $out;
                if (strlen($data) > self::CONTENT_CAP + 1) return null;
            }
        }
        if (strlen($data) !== $e['usize']) return null;   // 内容不完整 / 尺寸造假
        if (pack('V', crc32($data)) !== $e['crc']) return null; // CRC 校验失败
        return $data;
    }

    public function close(): void
    {
        if ($this->fp !== null) {
            @fclose($this->fp);
            $this->fp = null;
        }
        $this->entries = [];
    }
}

/* ==================== 暂存提取与目录工具 ==================== */

/**
 * 按清单把文件从升级包提取到暂存目录（stagingDir 必须已存在且路径可信）。
 * 单文件上限 20MB，累计上限 200MB。失败明细追加到 $errors（['path','reason']）。
 * 返回成功提取的文件数。
 */
function sp_upgrade_extract_files(SpZipReader $reader, array $files, string $stagingDir, array &$errors = []): int
{
    $done = 0;
    $total = 0;
    foreach ($files as $rel) {
        $fp = $reader->getStream($rel);
        if ($fp === false) {
            $errors[] = ['path' => $rel, 'reason' => '升级包内缺少该文件'];
            continue;
        }
        $target = $stagingDir . '/' . $rel;
        $td = dirname($target);
        if (!is_dir($td) && !@mkdir($td, 0755, true)) {
            fclose($fp);
            $errors[] = ['path' => $rel, 'reason' => '无法创建暂存目录'];
            continue;
        }
        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($fp);
            $errors[] = ['path' => $rel, 'reason' => '无法写入暂存文件（权限不足？）'];
            continue;
        }
        $n = stream_copy_to_stream($fp, $out, 20 * 1024 * 1024 + 1);
        fclose($fp);
        fclose($out);
        if ($n === false || $n > 20 * 1024 * 1024) {
            @unlink($target);
            $errors[] = ['path' => $rel, 'reason' => '单文件超过 20MB 上限'];
            continue;
        }
        $total += (int)$n;
        if ($total > 200 * 1024 * 1024) {
            $errors[] = ['path' => $rel, 'reason' => '累计内容超过 200MB 上限'];
            continue;
        }
        $done++;
    }
    return $done;
}

/** 递归删除目录（CHILD_FIRST，先文件后目录；沿用 backup.php 已验证写法） */
function sp_upgrade_rm_rf(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** 清理过期会话目录（按目录 mtime 判定），用于 staging / backup 目录防堆积 */
function sp_upgrade_cleanup_stale(string $base, int $ttl): void
{
    if (!is_dir($base)) return;
    $deadline = time() - $ttl;
    foreach (scandir($base) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $p = $base . '/' . $name;
        if (!is_dir($p)) continue;
        $mt = @filemtime($p);
        if ($mt !== false && $mt < $deadline) sp_upgrade_rm_rf($p);
    }
}

/**
 * 删除文件后自底向上摘除因此空掉的目录（仅限 rel 所在分支，不动站点其他目录）。
 */
function sp_upgrade_prune_empty_dirs(string $root, array $rels): void
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $seen = [];
    foreach ($rels as $rel) {
        $dir = dirname(str_replace('\\', '/', $rel));
        while ($dir !== '.' && $dir !== '/' && $dir !== '') {
            if (isset($seen[$dir])) break;
            $seen[$dir] = true;
            $abs = $root . '/' . $dir;
            if (!is_dir($abs)) {
                $dir = dirname($dir);
                continue;
            }
            $entries = scandir($abs) ?: [];
            if (count(array_diff($entries, ['.', '..'])) > 0) break; // 非空即停
            @rmdir($abs);
            $dir = dirname($dir);
        }
    }
}
