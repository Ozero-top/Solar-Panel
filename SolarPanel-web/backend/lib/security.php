<?php
/**
 * 安全辅助函数
 *  - sp_url_is_public / sp_host_is_public / sp_ip_is_public：SSRF 防护（解析后判定私有/保留地址）
 *  - sp_sanitize_svg：SVG 文件净化（剥离脚本与事件处理器）
 *  - sp_ensure_upload_protected：上传目录写入 .htaccess（禁 PHP 执行 + SVG 沙箱）
 */

/* ==================== SSRF 防护 ==================== */

/** 判断单个 IP 是否为公网地址（IPv4 / IPv6） */
function sp_ip_is_public(string $ip): bool
{
    $ip = trim($ip, '[]');

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        // filter 内置私有/保留段判定（10/8、172.16/12、192.168/16、127/8、169.254/16、0/8、240/4 等）
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $long = ip2long($ip);
        // 100.64.0.0/10 CGNAT 运营商级 NAT
        if (($long & 0xFFC00000) === 0x64400000) return false;
        // 198.18.0.0/15 网络基准测试段（常见代理 fake-ip 占用）
        if (($long & 0xFFFE0000) === 0xC6120000) return false;
        return true;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $b = inet_pton($ip);
        if ($b === false) return false;
        // ::ffff:a.b.c.d（IPv4-mapped）优先按 IPv4 规则判定
        // （部分 PHP 版本的 NO_RES_RANGE 会把 ::ffff:0:0/96 整段误判为保留，导致 mapped 公网地址被拦）
        if (strlen($b) === 16 && substr($b, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            return sp_ip_is_public(inet_ntop(substr($b, 12)));
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // fe80::/10 链路本地、2001:db8::/32 文档段（filter 未必覆盖，手动拦截）
        if ((ord($b[0]) & 0xFF) === 0xFE && (ord($b[1]) & 0xC0) === 0x80) return false;
        if (ord($b[0]) === 0x20 && ord($b[1]) === 0x01 && ord($b[2]) === 0x0D && ord($b[3]) === 0xB8) return false;
        return true;
    }

    return false;
}

/**
 * 主机名（域名或 IP）解析后是否全部为公网地址
 * 解析失败且本机无 DNS 解析能力时放行（交给网络层失败）；有解析能力但无结果则拦截
 */
function sp_host_is_public(string $host): bool
{
    $host = strtolower(trim(trim($host), '[]'));
    $host = rtrim($host, '.');
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) return false;

    // 字面量 IP
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return sp_ip_is_public($host);
    }
    // 不含点的纯数字/十六进制怪异主机名（如十进制整数 IP 2130706433）拒绝
    if (strpos($host, '.') === false && preg_match('/^[0-9a-fA-Fx]+$/', $host)) return false;

    $canResolve = function_exists('gethostbynamel') || function_exists('dns_get_record');
    if (!$canResolve) return true; // 极端环境无 DNS 函数，不阻断正常功能

    $ips = [];
    if (function_exists('gethostbynamel')) {
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) $ips = array_merge($ips, $v4);
    }
    if (function_exists('dns_get_record')) {
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $r) {
                if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }
    }
    if (!$ips) return false; // 域名解析无结果：拦截（curl 同样连不上，无副作用）
    foreach ($ips as $ip) {
        if (!sp_ip_is_public($ip)) return false;
    }
    return true;
}

/** URL 是否为公网 http(s) 地址（协议 + 主机双重校验） */
function sp_url_is_public(string $u): bool
{
    $scheme = strtolower((string)parse_url($u, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) return false;
    $host = parse_url($u, PHP_URL_HOST);
    if (!is_string($host) || $host === '') return false;
    return sp_host_is_public($host);
}

/**
 * 链接协议白名单：仅允许 http(s)（内网 http 地址同样合法，如局域网导航）
 * 用于卡片地址 / 搜索引擎地址等会被跳转、window.open、iframe 使用的用户输入，
 * 拦截 javascript: / data: / vbscript: 等可执行协议（存储型 XSS 防护）
 */
function sp_url_is_http(string $u): bool
{
    return preg_match('#^\s*https?://#i', $u) === 1;
}

/**
 * 将重定向 Location（可能为相对路径）拼接到基础 URL 上
 */
function sp_join_url(string $base, string $ref): string
{
    $ref = trim($ref);
    if ($ref === '') return '';
    if (preg_match('#^https?://#i', $ref)) return $ref;
    $parts = parse_url($base);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return '';
    $origin = $parts['scheme'] . '://' . $parts['host']
        . (!empty($parts['port']) ? ':' . $parts['port'] : '');
    if (strpos($ref, '//') === 0) return $parts['scheme'] . ':' . $ref;
    if ($ref[0] === '/') return $origin . $ref;
    $path = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';
    return $origin . $path . ltrim($ref, './');
}

/* ==================== SVG 净化 ==================== */

/**
 * 校验并净化 SVG 内容：
 *  - 必须是 <svg> 文档
 *  - 含 script / iframe / object / embed / use / foreignObject 等危险标签直接拒绝
 *  - 剥离所有 on* 事件属性
 *  - 剥离 href / xlink:href 中的 javascript: / vbscript: / data: 协议
 * 返回净化后的内容；不合法返回 null
 */
function sp_sanitize_svg(string $svg): ?string
{
    $s = ltrim($svg);
    if ($s === '' || strlen($s) > 5 * 1024 * 1024) return null;
    // 允许 <?xml 声明开头，其后必须出现 <svg
    if (!preg_match('/<svg[\s>]/i', $s)) return null;

    // 危险标签：直接拒绝（避免净化绕过）
    if (preg_match('/<\s*(script|iframe|object|embed|use|foreignObject|audio|video)\b/i', $s)) {
        return null;
    }

    // 剥离事件处理器属性（onload= / onclick= ...，含无引号写法）
    $s = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', ' ', $s);

    // 剥离危险协议的 href / xlink:href
    $s = preg_replace_callback(
        '/(?:xlink:href|href)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',
        function ($m) {
            $val = $m[2] ?? $m[3] ?? $m[4] ?? '';
            if (preg_match('/^\s*(javascript|vbscript|data)\s*:/i', $val)) {
                return ' href="#"';
            }
            return $m[0];
        },
        $s
    );

    return $s;
}

/* ==================== 上传目录保护 ==================== */

/**
 * 在上传目录写入 .htaccess（Apache）：
 *  - 禁止该目录及子目录执行 PHP 类脚本
 *  - SVG 响应加 CSP sandbox 与 nosniff（直接访问 SVG 时脚本不执行）
 * nginx / IIS 环境需在站点配置中实现等价规则（见 README 部署说明）
 */
function sp_ensure_upload_protected(string $dir): void
{
    $ht = rtrim($dir, '/\\') . '/.htaccess';
    if (is_file($ht)) return;
    $content = "# SolarPanel 上传目录保护（自动生成，请勿删除）\n"
        . "# 1) 禁止执行任何脚本文件\n"
        . "<IfModule mod_authz_core.c>\n"
        . "  <FilesMatch \"\\.(php|phtml|pht|php3|php4|php5|php7|phps|phar|cgi|pl|py|jsp|asp|aspx)$\">\n"
        . "    Require all denied\n"
        . "  </FilesMatch>\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "  <FilesMatch \"\\.(php|phtml|pht|php3|php4|php5|php7|phps|phar|cgi|pl|py|jsp|asp|aspx)$\">\n"
        . "    Order allow,deny\n"
        . "    Deny from all\n"
        . "  </FilesMatch>\n"
        . "</IfModule>\n"
        . "<IfModule mod_php.c>\n"
        . "  php_flag engine off\n"
        . "</IfModule>\n"
        . "<IfModule mod_php7.c>\n"
        . "  php_flag engine off\n"
        . "</IfModule>\n"
        . "<IfModule mod_php8.c>\n"
        . "  php_flag engine off\n"
        . "</IfModule>\n"
        . "# 2) SVG 直接访问时沙箱化，禁止脚本执行\n"
        . "<IfModule mod_headers.c>\n"
        . "  <FilesMatch \"\\.svgz?$\">\n"
        . "    Header set Content-Security-Policy \"sandbox\"\n"
        . "    Header set X-Content-Type-Options \"nosniff\"\n"
        . "  </FilesMatch>\n"
        . "</IfModule>\n";
    @file_put_contents($ht, $content);
}
