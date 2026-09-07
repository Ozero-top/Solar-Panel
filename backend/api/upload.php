<?php
/**
 * 图片上传（需登录）：卡片图标 / 站点 Logo / 壁纸
 * 权限：管理员 / 编辑者
 * POST multipart/form-data: file=<文件>, type=icon|logo|wallpaper
 * 分目录保存：图标 uploads/icons/、Logo uploads/logos/、壁纸 uploads/wallpapers/
 * 返回：{ url: "/frontend/uploads/<子目录>/xxx.png" }
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/security.php';

require_roles('admin', 'editor');

$type = str_param('type', 'icon');
$maxSize = $type === 'wallpaper' ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'svg'];

/** 类型 → 保存子目录（白名单，防路径穿越） */
$subdirMap = [
    'icon'      => 'icons',
    'logo'      => 'logos',
    'wallpaper' => 'wallpapers',
];
if (!isset($subdirMap[$type])) {
    fail('未知的上传类型');
}
$subdir = $subdirMap[$type];

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    fail('未收到文件');
}
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    fail('上传失败（错误码 ' . (int)$f['error'] . '），可能超过服务器上传限制');
}
if ($f['size'] <= 0 || $f['size'] > $maxSize) {
    fail($type === 'wallpaper' ? '壁纸不能超过 10MB' : '图片不能超过 5MB');
}

$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    fail('不支持的文件类型，仅允许：' . implode(' / ', $allowedExt));
}

// 位图需通过图像校验；SVG 走专门的安全净化（剥离脚本与事件处理器）
$svgClean = null;
if ($ext === 'svg') {
    $svgClean = sp_sanitize_svg((string)file_get_contents($f['tmp_name']));
    if ($svgClean === null) {
        fail('SVG 文件包含不安全内容（脚本 / 事件处理器）或不是有效的 SVG，已拒绝上传');
    }
} else {
    // 位图双保险：① getimagesize 确认是真实图片并取得类型；② 内容真实类型必须与扩展名同族，
    // 防改后缀的 polyglot（如 PHP/HTML 脚本改 .png）绕过
    $info = @getimagesize($f['tmp_name']);
    if ($info === false) {
        fail('文件不是有效的图片');
    }
    // getimagesize 图片类型 → 允许的扩展名
    $typeExtMap = [
        IMAGETYPE_GIF  => ['gif'],
        IMAGETYPE_JPEG => ['jpg', 'jpeg'],
        IMAGETYPE_PNG  => ['png'],
        IMAGETYPE_WEBP => ['webp'],
        IMAGETYPE_BMP  => ['bmp'],
        IMAGETYPE_ICO  => ['ico'],
    ];
    $allowedForType = $typeExtMap[$info[2]] ?? null;
    if ($allowedForType === null || !in_array($ext, $allowedForType, true)) {
        fail('文件内容与扩展名不符（真实类型不是 ' . strtoupper($ext) . '），已拒绝上传');
    }
    // finfo MIME 复核：非 image/* 一律拒绝（部分环境 libmagic 把 .ico 识别为 octet-stream，
    // 此时已有 getimagesize 背书，仅对 ico 放行）
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = $fi ? strtolower((string)finfo_file($fi, $f['tmp_name'])) : '';
        if ($fi) finfo_close($fi);
        if ($realMime !== '' && strpos($realMime, 'image/') !== 0
            && !($ext === 'ico' && $realMime === 'application/octet-stream')) {
            fail('文件内容不是受支持的图片格式，已拒绝上传');
        }
    }
}

$dir = __DIR__ . '/../../frontend/uploads/' . $subdir;
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    fail('上传目录创建失败，请检查目录权限');
}
if (!is_writable($dir)) {
    fail('上传目录不可写，请检查目录权限');
}
// 上传根目录保护：.htaccess 禁 PHP 执行 + SVG 沙箱（Apache；nginx 需在站点配置等价处理）
sp_ensure_upload_protected(dirname($dir));

$fname = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = $dir . '/' . $fname;
if (!move_uploaded_file($f['tmp_name'], $dest)) {
    fail('保存文件失败');
}
// SVG 保存净化后的内容
if ($svgClean !== null) {
    @file_put_contents($dest, $svgClean);
}

ok(['url' => '/frontend/uploads/' . $subdir . '/' . $fname]);
