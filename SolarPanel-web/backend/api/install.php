<?php
/**
 * SolarPanel 安装向导
 * 浏览器打开本文件即可安装：
 *   1. 填写数据库地址 / 端口 / 数据库名 / 用户名 / 密码
 *   2. 自动检测连接、建库、建表、写入默认数据
 *   3. 自动将连接信息写回 backend/config.php
 *      （若文件不可写，会显示配置内容供手动保存，数据仍会完成初始化）
 * 安全提示：安装成功后本文件与 sql 目录会被自动删除。
 */
require_once __DIR__ . '/../lib/response.php';

$configPath = realpath(__DIR__ . '/../config.php') ?: (__DIR__ . '/../config.php');

/* ============================================================
 * 输出辅助：Soft UI 风格独立页面
 * ============================================================ */
function page_start(string $title): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . '</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;'
        . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;'
        . 'background:linear-gradient(160deg,#eef1f7 0%,#e7ecf4 45%,#dde4ee 100%);color:#3b4354;}'
        . '.card{width:100%;max-width:420px;background:#e9edf5;border:1px solid rgba(255,255,255,.62);'
        . 'border-radius:28px;padding:32px 30px;box-shadow:4px 4px 9px rgba(163,175,198,.38),'
        . '10px 10px 24px rgba(163,175,198,.32),-4px -4px 9px #fff,-10px -10px 24px rgba(255,255,255,.85);}'
        . 'h1{margin:0 0 6px;font-size:20px;text-align:center;}'
        . '.sub{margin:0 0 22px;font-size:13px;color:#8b94a7;text-align:center;}'
        . 'label{display:block;font-size:13px;color:#8b94a7;margin:12px 0 6px;}'
        . 'input{width:100%;padding:9px 14px;font-size:14px;color:#3b4354;border:1px solid transparent;'
        . 'border-radius:14px;outline:none;background:#e9edf5;box-sizing:border-box;'
        . 'box-shadow:inset 2px 2px 5px rgba(163,175,198,.45),inset 5px 5px 12px rgba(163,175,198,.28),'
        . 'inset -2px -2px 5px rgba(255,255,255,.95),inset -5px -5px 12px rgba(255,255,255,.65);}'
        . 'input:focus{border-color:rgba(92,124,250,.14);}'
        . '.row{display:flex;gap:10px;}.row>div{flex:1;}'
        . 'button{width:100%;margin-top:22px;padding:11px;font-size:15px;border:none;border-radius:999px;'
        . 'background:#5c7cfa;color:#fff;cursor:pointer;'
        . 'box-shadow:2px 2px 5px rgba(163,175,198,.52),6px 6px 16px rgba(92,124,250,.32);'
        . 'transition:all .2s cubic-bezier(.33,1,.68,1);}'
        . 'button:hover{background:#4a6bf0;transform:translateY(-2px);}'
        . 'button:active{transform:translateY(1px);box-shadow:1px 1px 3px rgba(163,175,198,.52);}'
        . '.err{background:#fdecec;color:#c0392b;border-radius:12px;padding:10px 14px;font-size:13px;'
        . 'margin-bottom:14px;word-break:break-all;}'
        . '.ok-tag{display:inline-block;background:#e7f6ef;color:#1f8f62;border-radius:999px;'
        . 'padding:4px 14px;font-size:13px;margin-bottom:14px;}'
        . '.info{font-size:14px;line-height:2;}'
        . '.info b{color:#3b4354;}'
        . 'a.btn-link{display:block;text-align:center;margin-top:18px;padding:10px;border-radius:999px;'
        . 'background:#5c7cfa;color:#fff !important;text-decoration:none;font-size:14px;}'
        . 'a.link{color:#5c7cfa;font-size:13px;}'
        . 'textarea{width:100%;height:190px;margin-top:10px;padding:12px;font-size:12px;'
        . 'font-family:Consolas,monospace;color:#3b4354;border:1px solid transparent;border-radius:14px;'
        . 'outline:none;background:#e9edf5;box-sizing:border-box;resize:vertical;'
        . 'box-shadow:inset 2px 2px 5px rgba(163,175,198,.45),inset -2px -2px 5px rgba(255,255,255,.95);}'
        . '.tip{font-size:12px;color:#8b94a7;line-height:1.8;margin-top:14px;}'
        . '</style></head><body><div class="card">';
}

function page_end(): void
{
    echo '</div></body></html>';
}

/* ============================================================
 * 数据库探测与安装（全部使用表单值，不依赖 config.php）
 * ============================================================ */
function make_pdo(array $c, bool $withDb): PDO
{
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ';charset=utf8mb4';
    if ($withDb) {
        $dsn .= ';dbname=' . $c['dbname'];
    }
    return new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function create_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(50) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `name` VARCHAR(50) NOT NULL DEFAULT '',
        `status` TINYINT NOT NULL DEFAULT 1,
        `role` VARCHAR(20) NOT NULL DEFAULT 'admin' COMMENT '权限组：admin 管理员 / editor 编辑者 / viewer 只读',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `item_groups` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(50) NOT NULL,
        `description` VARCHAR(1000) NOT NULL DEFAULT '',
        `sort` INT NOT NULL DEFAULT 0,
        `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
        `user_id` INT UNSIGNED NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_sort` (`sort`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `items` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `group_id` INT UNSIGNED NOT NULL,
        `title` VARCHAR(50) NOT NULL,
        `url` VARCHAR(1000) NOT NULL DEFAULT '',
        `lan_url` VARCHAR(1000) NOT NULL DEFAULT '',
        `description` VARCHAR(1000) NOT NULL DEFAULT '',
        `icon_type` VARCHAR(10) NOT NULL DEFAULT 'image',
        `icon_value` VARCHAR(1000) NOT NULL DEFAULT '',
        `icon_bg` VARCHAR(20) NOT NULL DEFAULT '',
        `open_method` TINYINT NOT NULL DEFAULT 2,
        `sort` INT NOT NULL DEFAULT 0,
        `user_id` INT UNSIGNED NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_group_sort` (`group_id`, `sort`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `config_name` VARCHAR(50) NOT NULL,
        `config_value` TEXT,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_config_name` (`config_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function seed_data(PDO $pdo, string $adminUser, string $adminPass, string $siteUrl = ''): bool
{
    // 已安装过（管理员存在）则不重复写入，避免重置后台账号
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
        return false;
    }

    $st = $pdo->prepare('INSERT INTO users (username, password, name, status, role) VALUES (?, ?, ?, 1, \'admin\')');
    $st->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), '管理员']);

    $defaultSettings = [
        'site_title'         => 'SolarPanel',
        'site_logo'          => '',
        'wallpaper'          => '',
        'mask_opacity'       => '0.35',
        'wallpaper_blur'     => '6',
        'announcement'       => '欢迎使用 SolarPanel！所有展示内容均可在后台设置。',
        'announcement_show'  => '0',
        'footer'             => 'Powered by SolarPanel',
        'clock_show'         => '1',
        'default_theme'      => 'dark',
        'theme_style'        => 'soft',
        'card_style'         => 'detail',
        'default_lan_mode'   => 'public',
        'site_url'           => $siteUrl,
        'content_maxwidth'   => '1200',
        'content_pad_lr'     => '20',
        'content_pad_top'    => '0',
        'content_pad_bottom' => '40',
        'weather_show'       => '1',
        'weather_city'       => '',
        'search_width'       => '640',
        'home_view'          => 'both',
        'news_sources'       => '',
        'news_order'         => '',
        'search_engines'     => json_encode([
            ['name' => '百度',   'url' => 'https://www.baidu.com/s?wd=%s'],
            ['name' => 'Google', 'url' => 'https://www.google.com/search?q=%s'],
            ['name' => 'Bing',   'url' => 'https://www.bing.com/search?q=%s'],
            ['name' => 'DuckDuckGo', 'url' => 'https://duckduckgo.com/?q=%s'],
            ['name' => 'Yandex', 'url' => 'https://yandex.com/search/?text=%s'],
            ['name' => 'GitHub', 'url' => 'https://github.com/search?q=%s'],
            ['name' => '搜狗',     'url' => 'https://www.sogou.com/web?query=%s'],
            ['name' => '360搜索',  'url' => 'https://www.so.com/s?q=%s'],
            ['name' => '神马',     'url' => 'https://m.sm.cn/s?q=%s'],
            ['name' => '夸克',     'url' => 'https://www.quark.cn/s?q=%s'],
            ['name' => '头条搜索', 'url' => 'https://so.toutiao.com/search?keyword=%s'],
            ['name' => '中国搜索', 'url' => 'https://www.chinaso.com/search/all?q=%s'],
            ['name' => '抖音',     'url' => 'https://www.douyin.com/search/%s'],
        ], JSON_UNESCAPED_UNICODE),
        'search_default'     => '百度',
    ];
    $st = $pdo->prepare('INSERT INTO settings (config_name, config_value) VALUES (?, ?)');
    foreach ($defaultSettings as $name => $value) {
        $st->execute([$name, $value]);
    }

    $pdo->exec("INSERT INTO item_groups (title, description, sort, is_visible, user_id) VALUES ('常用推荐', '点击卡片即可跳转，可在后台管理', 0, 1, 1)");
    $gid = (int)$pdo->lastInsertId();
    $sampleItems = [
        ['GitHub',     'https://github.com',       '全球最大代码托管平台', 'favicon', '', '', 2, 0],
        ['哔哩哔哩',    'https://www.bilibili.com', '视频弹幕网站', 'favicon', '', '', 2, 1],
        ['Docker Hub', 'https://hub.docker.com',   '容器镜像仓库', 'favicon', '', '', 2, 2],
    ];
    $st = $pdo->prepare('INSERT INTO items (group_id, title, url, description, icon_type, icon_value, icon_bg, open_method, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($sampleItems as $it) {
        $st->execute([$gid, $it[0], $it[1], $it[2], $it[3], $it[4], $it[5], $it[6], $it[7]]);
    }
    return true;
}

/** 生成 config.php 文件内容 */
function build_config(array $c): string
{
    $v = function (string $s): string {
        return addslashes($s);
    };
    return "<?php\n"
        . "/**\n"
        . " * SolarPanel 数据库配置（由 install.php 自动生成于 " . date('Y-m-d H:i:s') . "）\n"
        . " * 管理员账号仅在首次安装时写入数据库，不保存于此文件。\n"
        . " */\n"
        . "return [\n"
        . "    'host'    => '" . $v($c['host']) . "',\n"
        . "    'port'    => " . (int)$c['port'] . ",\n"
        . "    'dbname'  => '" . $v($c['dbname']) . "',\n"
        . "    'user'    => '" . $v($c['user']) . "',\n"
        . "    'pass'    => '" . $v($c['pass']) . "',\n"
        . "    'charset' => 'utf8mb4',\n"
        . "];\n";
}

/* ============================================================
 * 页面：表单
 * ============================================================ */
function show_form(array $v, string $error = ''): void
{
    page_start('安装 SolarPanel');
    echo '<h1>SolarPanel 安装向导</h1>'
        . '<p class="sub">填写 MySQL 数据库信息，将自动建库建表并写入配置</p>';
    if ($error !== '') {
        echo '<div class="err">' . e($error) . '</div>';
    }
    echo '<form method="post" autocomplete="off">'
        . '<div class="row">'
        . '<div><label>数据库地址</label><input name="host" value="' . e($v['host']) . '" required></div>'
        . '<div><label>端口</label><input name="port" value="' . e($v['port']) . '" required></div>'
        . '</div>'
        . '<label>数据库名</label><input name="dbname" value="' . e($v['dbname']) . '" placeholder="如 solarpanel" required>'
        . '<label>用户名</label><input name="user" value="' . e($v['user']) . '" required>'
        . '<label>密码</label><input name="pass" value="' . e($v['pass']) . '" placeholder="没有密码可留空">'
        . '<label>站点地址（「退出登录」将跳转到此地址，如 https://nav.example.com 或 http://192.168.1.10）</label>'
        . '<input name="site_url" value="' . e($v['site_url']) . '" placeholder="http://192.168.1.10">'
        . '<div class="row">'
        . '<div><label>管理员账号</label><input name="admin_username" value="' . e($v['admin_username']) . '" required></div>'
        . '<div><label>管理员密码</label><input name="admin_password" value="' . e($v['admin_password']) . '" required></div>'
        . '</div>'
        . '<button type="submit">开始安装</button>'
        . '</form>'
        . '<p class="tip">提示：数据库需提前在面板中创建（如宝塔 / 1Panel）。安装成功后本文件与 sql 目录将自动删除。</p>';
    page_end();
}

/* ============================================================
 * 页面：安装成功
 * ============================================================ */
function show_success(array $v, bool $seeded, bool $configWritten, bool $cleaned): void
{
    page_start('安装成功 - SolarPanel');
    echo '<span class="ok-tag">✓ 安装成功</span>'
        . '<div class="info">';
    if ($seeded) {
        echo '已创建数据表并写入默认数据。<br>管理员账号：<b>' . e($v['admin_username']) . '</b><br>'
            . '管理员密码：<b>' . e($v['admin_password']) . '</b>（请登录后立即修改）';
    } else {
        echo '系统此前已安装过，本次仅校验并写回数据库配置，未改动任何数据。';
    }
    echo '</div>';
    if (!$configWritten) {
        echo '<p class="tip" style="color:#c0392b">config.php 自动写入失败（文件或目录无写权限），'
            . '请将下方内容手动保存为 <b>backend/config.php</b> 后即可正常使用：</p>'
            . '<textarea readonly onclick="this.select()">' . e(build_config($v)) . '</textarea>';
    }
    $homeLink = $v['site_url'] !== ''
        ? $v['site_url'] . '/index.html'
        : '../../index.html';
    echo '<a class="btn-link" href="' . e($homeLink) . '">前往主页 →</a>'
        . '<p class="tip">后台登录：<a class="link" href="../../frontend/login.html">frontend/login.html</a>'
        . ($cleaned
            ? '<br>✓ 安装文件与 sql 目录已自动删除。'
            : '<br>安全建议：确认运行正常后<b>手动删除 install.php 与 sql 目录</b>。')
        . '</p>';
    page_end();
}

/**
 * 安装成功后自动清理：删除本安装文件与 sql 目录（尽力而为）
 */
function cleanup_install_files(): bool
{
    $ok = true;
    if (!@unlink(__FILE__)) {
        $ok = false; // Windows 等环境下运行中的脚本可能无法自删
    }
    $sqlDir = dirname(__DIR__, 2) . '/sql';
    if (is_dir($sqlDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sqlDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        if (!@rmdir($sqlDir)) {
            $ok = false;
        }
    }
    return $ok;
}

/* ============================================================
 * 页面：已安装过
 * ============================================================ */
function show_installed(): void
{
    page_start('已安装 - SolarPanel');
    echo '<span class="ok-tag">✓ 系统已安装</span>'
        . '<div class="info">检测到管理员账号已存在，无需重复安装。<br>'
        . '如需更换数据库连接，请修改 <b>backend/config.php</b>；<br>'
        . '如需重装，请先清空数据表（或删除所有表）后再访问本页。</div>'
        . '<a class="btn-link" href="../../index.html">前往主页 →</a>'
        . '<p class="tip">安全建议：确认运行正常后删除或改名本安装文件。</p>';
    page_end();
}

/* ============================================================
 * 主流程
 * ============================================================ */
$defaults = [
    'host'           => 'localhost',
    'port'           => '3306',
    'dbname'         => '',
    'user'           => '',
    'pass'           => '',
    'site_url'       => '',
    'admin_username' => 'admin',
    // 不预填默认密码：强制安装者自行设置，避免 admin/admin123 弱口令站点上线
    'admin_password' => '',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 已配置且已安装时不再显示表单，防止误操作重置后台账号
    try {
        $c = require $configPath;
        if (is_array($c) && !empty($c['dbname']) && !empty($c['user'])) {
            $probe = array_merge($defaults, $c);
            $pdo = make_pdo($probe, true);
            if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                show_installed();
                exit;
            }
        }
    } catch (Throwable $e) {
        // 配置缺失或不可用时正常显示表单
    }
    // 站点地址默认自动识别当前访问地址
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $defaults['site_url'] = rtrim($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? ''), '/');
    show_form($defaults);
    exit;
}

/* ---- 收集并校验表单 ---- */
$v = [
    'host'           => trim((string)($_POST['host'] ?? $defaults['host'])) ?: 'localhost',
    'port'           => (string)((int)($_POST['port'] ?? 0) ?: 3306),
    'dbname'         => trim((string)($_POST['dbname'] ?? '')),
    'user'           => trim((string)($_POST['user'] ?? '')),
    'pass'           => (string)($_POST['pass'] ?? ''),
    'site_url'       => trim((string)($_POST['site_url'] ?? '')),
    'admin_username' => trim((string)($_POST['admin_username'] ?? 'admin')) ?: 'admin',
    'admin_password' => (string)($_POST['admin_password'] ?? ''),
];

if ($v['dbname'] === '' || $v['user'] === '') {
    show_form($v + $defaults, '数据库名与用户名不能为空');
    exit;
}
// 数据库名白名单：仅字母 / 数字 / 下划线、1-64 位。
// 该名会进入 DSN 与 CREATE DATABASE 语句（标识符无法预处理参数化），必须严格限定字符集，杜绝注入
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $v['dbname'])) {
    show_form($v + $defaults, '数据库名仅允许字母、数字和下划线，长度 1-64 位（中划线等特殊字符不支持）');
    exit;
}
if ($v['admin_username'] === '' || strlen($v['admin_password']) < 6) {
    show_form($v + $defaults, '请填写管理员账号，密码至少 6 位');
    exit;
}
if ($v['site_url'] !== '' && !preg_match('#^https?://#i', $v['site_url'])) {
    show_form($v + $defaults, '站点地址需以 http:// 或 https:// 开头，或留空');
    exit;
}
$v['site_url'] = rtrim($v['site_url'], '/');

try {
    /* ---- 1. 直连目标数据库（宝塔 / 1Panel 创建的账号通常仅授权单库，无建库权限） ---- */
    try {
        $pdo = make_pdo($v, true);
    } catch (PDOException $connectErr) {
        // 目标库连不上：尝试自动建库（需账号具备全局建库权限，如 root；面板用户没有）
        try {
            $server = make_pdo($v, false);
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $v['dbname'] . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = make_pdo($v, true);
        } catch (Throwable $autoErr) {
            // 原始异常可能含主机 / 端口 / DSN / SQL 细节，仅写服务端日志，不整段回显
            error_log('[SolarPanel] install.php 数据库连接失败：' . $connectErr->getMessage()
                . '；自动建库失败：' . $autoErr->getMessage());
            $m = $connectErr->getMessage();
            if (strpos($m, '1045') !== false) {
                show_form($v + $defaults, '数据库用户名或密码不正确，请在面板中核对后重试');
            } elseif (strpos($m, '1044') !== false) {
                show_form($v + $defaults, '账号 ' . $v['user'] . ' 无权访问数据库 ' . $v['dbname']
                    . '：宝塔创建的数据库与用户名通常相同，请检查是否填反或填错；'
                    . '该账号也没有自动建库权限，数据库需先在面板中创建');
            } elseif (strpos($m, '1049') !== false) {
                show_form($v + $defaults, '数据库 ' . $v['dbname'] . ' 不存在，且当前账号无自动建库权限；请先在面板中创建数据库后重试');
            } else {
                show_form($v + $defaults, '连接数据库失败，请检查数据库地址、端口及账号信息是否正确（详细错误已记录到服务器日志）');
            }
            exit;
        }
    }

    /* ---- 2. 已安装检测：避免重置后台账号 ---- */
    $tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
    if ($tables && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
        show_installed();
        exit;
    }

    /* ---- 3. 建表 + 初始化数据 ---- */
    create_tables($pdo);
    $seeded = seed_data($pdo, $v['admin_username'], $v['admin_password'], $v['site_url']);

    /* ---- 4. 确保上传目录存在（含子目录；各 API 运行时也会自建，这里预先建好避免权限不一致） ---- */
    foreach (['', 'icons', 'logos', 'wallpapers'] as $sub) {
        $dir = __DIR__ . '/../../frontend/uploads' . ($sub !== '' ? '/' . $sub : '');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /* ---- 5. 写回 config.php（直接尝试写入，避免 is_writeable 在部分环境误报） ---- */
    $configWritten = @file_put_contents($configPath, build_config($v), LOCK_EX) !== false;

    /* ---- 6. 安装成功后自动删除安装文件与 sql 目录 ---- */
    $cleaned = $configWritten ? cleanup_install_files() : false;

    show_success($v, $seeded, $configWritten, $cleaned);
} catch (Throwable $e) {
    // 原始异常（可能含库地址 / 表结构 / SQL 细节）仅写服务端日志；安装页无鉴权，不回显
    error_log('[SolarPanel] install.php 安装失败：' . $e->getMessage());
    show_form($v + $defaults, '安装失败，请检查数据库信息与目录权限是否正确（详细错误已记录到服务器日志）');
}
