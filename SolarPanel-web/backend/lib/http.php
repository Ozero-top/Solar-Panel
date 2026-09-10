<?php
/**
 * 服务端 HTTP 抓取公共库（items.php 站点信息 / news.php 热榜 / weather.php 天气共用）
 *
 * 安全约定：
 *  - TLS 证书严格校验（curl VERIFYPEER=true / VERIFYHOST=2；stream verify_peer/verify_peer_name=true），
 *    防止中间人攻击篡改响应；
 *  - public_only 模式（SSRF 防护）：仅允许公网 http(s) 地址，重定向逐跳重新解析校验目标主机，
 *    依赖 security.php 的 sp_url_is_public() / sp_join_url()；
 *  - curl 优先，无 curl 扩展时退回 stream（allow_url_fopen）；
 *  - 失败返回 ''，具体原因通过 sp_http_last_error() 获取（已做中文提示映射，不回显底层敏感细节）。
 */
require_once __DIR__ . '/security.php';

/** 最近一次抓取失败的具体原因 */
function sp_http_last_error(): string
{
    return isset($GLOBALS['sp_http_err']) ? (string)$GLOBALS['sp_http_err'] : '';
}

/** curl 错误码 → 可操作的中文提示（#28 超时最常见：境外站点从境内服务器访问常被墙） */
function sp_curl_hint(int $errno, string $raw): string
{
    $map = [
        6  => '域名解析失败（域名不存在或服务器 DNS 异常）',
        7  => '无法连接目标站点（端口未开放或被防火墙拦截）',
        28 => '连接/响应超时（站点不可达或网络受限，境外站点从境内服务器访问可能被墙）',
        35 => 'SSL/TLS 握手失败',
        60 => 'SSL 证书校验失败（目标站点证书配置异常，已拒绝连接以防中间人攻击）',
    ];
    return isset($map[$errno]) ? $map[$errno] . '（curl #' . $errno . '）' : ('curl 错误：' . $raw . ' #' . $errno);
}

/**
 * 发起 HTTP(S) 请求
 *
 * $opts 支持：
 *   timeout           总超时秒数（默认 8）
 *   connect_timeout   连接超时秒数（默认 5）
 *   headers           自定义请求头数组（每项一行 "Name: value"）
 *   post_body         非 null 时以 POST 发送该字符串
 *   public_only       true：SSRF 防护模式，禁内网/保留地址且重定向逐跳校验（默认 false）
 *   max_redirs        最大重定向跳数（默认 3）
 *   user_agent        User-Agent（默认 Chrome 桌面 UA）
 *   ssl_eof_workaround true：附加 CURLSSLOPT_IGNORE_UNEXPECTED_EOF，兼容 OpenSSL 3 未发 close_notify 的站点
 *   fallback_stream   true：curl 执行失败或空响应时自动用 stream 再试一次（默认 false）
 */
function sp_http_request(string $u, array $opts = []): string
{
    $opts += [
        'timeout'           => 8,
        'connect_timeout'   => 5,
        'headers'           => [],
        'post_body'         => null,
        'public_only'       => false,
        'max_redirs'        => 3,
        'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        'ssl_eof_workaround'=> false,
        'fallback_stream'   => false,
    ];
    $GLOBALS['sp_http_err'] = '';

    if ($opts['public_only'] && !sp_url_is_public($u)) {
        $GLOBALS['sp_http_err'] = '目标地址为内网 / 保留地址，已拦截';
        return '';
    }

    if (function_exists('curl_init')) {
        $body = sp_http_curl($u, $opts);
        if ($body !== '' || !$opts['fallback_stream'] || !ini_get('allow_url_fopen')) {
            return $body;
        }
        // curl 失败 / 空响应且允许兜底：记录 curl 原因后用 stream 重试（stream 成功则清空错误）
        $curlErr = $GLOBALS['sp_http_err'];
        $sbody = sp_http_stream($u, $opts);
        if ($sbody !== '') return $sbody;
        if ($GLOBALS['sp_http_err'] === '') $GLOBALS['sp_http_err'] = $curlErr;
        return '';
    }

    if (!ini_get('allow_url_fopen')) {
        $GLOBALS['sp_http_err'] = 'PHP 未安装 curl 扩展且 allow_url_fopen=Off，服务器无法访问外部站点';
        return '';
    }
    return sp_http_stream($u, $opts);
}

/** curl 实现（public_only 时手动逐跳跟随重定向） */
function sp_http_curl(string $u, array $opts): string
{
    $ch = curl_init();
    $base = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)$opts['timeout'],
        CURLOPT_CONNECTTIMEOUT => (int)$opts['connect_timeout'],
        // 证书严格校验：防中间人篡改响应内容
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '', // 自动 gzip
        CURLOPT_USERAGENT      => $opts['user_agent'],
        CURLOPT_HTTPHEADER     => $opts['headers'],
    ];
    if ($opts['ssl_eof_workaround']) {
        // OpenSSL 3 下部分服务器不正常发送 close_notify，不加此项 curl 会报 unexpected eof 导致 body 为空
        $base[CURLOPT_SSL_OPTIONS] = defined('CURLSSLOPT_IGNORE_UNEXPECTED_EOF') ? CURLSSLOPT_IGNORE_UNEXPECTED_EOF : 4;
    }
    if ($opts['post_body'] !== null) {
        $base[CURLOPT_POST] = true;
        $base[CURLOPT_POSTFIELDS] = $opts['post_body'];
    }

    if ($opts['public_only']) {
        // 手动逐跳跟随重定向，每一跳都校验目标主机为公网（防 302 跳转绕过 SSRF 防护）
        $base[CURLOPT_FOLLOWLOCATION] = false;
        $base[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        $current = $u;
        for ($hop = 0; $hop <= (int)$opts['max_redirs']; $hop++) {
            curl_setopt_array($ch, $base + [CURLOPT_URL => $current]);
            $body = curl_exec($ch);
            if ($body === false) {
                $GLOBALS['sp_http_err'] = sp_curl_hint(curl_errno($ch), curl_error($ch));
                return '';
            }
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($code >= 300 && $code < 400) {
                $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                $next = $loc !== '' ? sp_join_url($current, $loc) : '';
                if ($next === '' || !sp_url_is_public($next)) {
                    $GLOBALS['sp_http_err'] = '重定向目标为内网 / 保留地址或非法地址，已拦截';
                    return '';
                }
                $current = $next;
                continue;
            }
            if ($code >= 400) {
                $GLOBALS['sp_http_err'] = '目标站点返回 HTTP ' . $code;
                return '';
            }
            return (string)$body;
        }
        $GLOBALS['sp_http_err'] = '重定向次数过多';
        return '';
    }

    curl_setopt_array($ch, $base + [
        CURLOPT_URL            => $u,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => (int)$opts['max_redirs'],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $GLOBALS['sp_http_err'] = sp_curl_hint(curl_errno($ch), curl_error($ch));
        return '';
    }
    if ((int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) >= 400) {
        $GLOBALS['sp_http_err'] = '目标站点返回 HTTP ' . curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return '';
    }
    // 注意：不要调用 curl_close()（PHP 8.5 起弃用，且 8.0 起已无效果），弃用通知会污染 JSON 输出
    return (string)$body;
}

/** stream 实现（无 curl 环境或 curl 失败兜底；public_only 时手动逐跳跟随重定向） */
function sp_http_stream(string $u, array $opts): string
{
    $lines = (array)$opts['headers'];
    // headers 未自带 User-Agent 时补上（自带时以自定义头为准，避免重复）
    $hasUA = false;
    foreach ($lines as $h) {
        if (preg_match('/^user-agent\s*:/i', (string)$h)) { $hasUA = true; break; }
    }
    if (!$hasUA) $lines[] = 'User-Agent: ' . $opts['user_agent'];

    $http = [
        'timeout'       => (int)$opts['timeout'],
        'ignore_errors' => true,
        'header'        => implode("\r\n", $lines),
    ];
    if ($opts['post_body'] !== null) {
        $http['method'] = 'POST';
        $http['content'] = $opts['post_body'];
    }
    // 证书严格校验（与 curl 端一致）
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];

    if ($opts['public_only']) {
        $http['follow_location'] = 0; // 关闭自动跟随，手动逐跳校验
        $current = $u;
        for ($hop = 0; $hop <= (int)$opts['max_redirs']; $hop++) {
            $ctx = stream_context_create(['http' => $http, 'ssl' => $ssl]);
            $body = @file_get_contents($current, false, $ctx);
            if ($body === false) {
                $err = error_get_last();
                $GLOBALS['sp_http_err'] = '读取失败：' . (isset($err['message']) ? $err['message'] : '未知错误');
                return '';
            }
            $code = 0;
            $loc = '';
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
                if (preg_match('#^location:\s*(.+)$#i', $h, $m)) $loc = trim($m[1]);
            }
            if ($code >= 300 && $code < 400 && $loc !== '') {
                $next = sp_join_url($current, $loc);
                if ($next === '' || !sp_url_is_public($next)) {
                    $GLOBALS['sp_http_err'] = '重定向目标为内网 / 保留地址或非法地址，已拦截';
                    return '';
                }
                $current = $next;
                continue;
            }
            if ($code >= 400) {
                $GLOBALS['sp_http_err'] = '目标站点返回 HTTP ' . $code;
                return '';
            }
            return (string)$body;
        }
        $GLOBALS['sp_http_err'] = '重定向次数过多';
        return '';
    }

    $ctx = stream_context_create(['http' => $http, 'ssl' => $ssl]);
    $body = @file_get_contents($u, false, $ctx);
    if ($body === false) {
        $err = error_get_last();
        $GLOBALS['sp_http_err'] = '读取失败：' . (isset($err['message']) ? $err['message'] : '未知错误');
        return '';
    }
    return (string)$body;
}

/* ------------------------------ 场景化封装 ------------------------------ */

/**
 * 卡片 / 图标抓取（items.php 用）
 * $publicOnly=true：SSRF 防护模式——仅公网地址、重定向逐跳校验
 */
function sp_http_fetch(string $u, int $timeout = 8, bool $publicOnly = false): string
{
    return sp_http_request($u, [
        'timeout'         => $timeout,
        'connect_timeout' => min(4, $timeout),
        'public_only'     => $publicOnly,
    ]);
}

/**
 * 热榜数据源抓取（news.php 用）：支持自定义头 / POST，
 * curl 失败自动回退 stream，容忍 OpenSSL 3 close_notify 兼容问题。
 * 注意：抓取地址全部为内置源（lib/news_sources.php），无用户可控 URL，不需要 public_only。
 */
function sp_http_news(string $u, int $timeout = 8, array $headers = [], ?string $postBody = null): string
{
    return sp_http_request($u, [
        'timeout'            => $timeout,
        'connect_timeout'    => 5,
        'headers'            => $headers,
        'post_body'          => $postBody,
        'ssl_eof_workaround' => true,
        'fallback_stream'    => true,
    ]);
}

/** 天气接口简单 GET（weather.php 用；URL 全部内置） */
function sp_http_simple(string $u, int $timeout = 5): string
{
    return sp_http_request($u, [
        'timeout'         => $timeout,
        'connect_timeout' => 4,
        'user_agent'      => 'Mozilla/5.0 (compatible; SolarPanel/1.0)',
    ]);
}
