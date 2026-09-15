<?php
/**
 * 纯 PHP TOTP 实现（RFC 6238）
 * - Base32 密钥（Google Authenticator / 微软 Authenticator 通用格式）
 * - HMAC-SHA1，30 秒时间步长，6 位数字动态码
 * - ±1 时间窗口容忍（防止时钟偏移 + 传输延迟）
 *
 * 无外部依赖；密钥 base32 编解码手动实现，兼容 RFC 4648 标准 alphabet。
 */

/** RFC 4648 Base32 alphabet（大写） */
const TOTP_B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** 随机生成 160-bit（20 字节）Base32 密钥，兼容 Go 版 */
function totp_generate_secret(): string
{
    // 20 字节 → 32 个 Base32 字符
    $bytes = random_bytes(20);
    $out = '';
    $buf = 0;
    $bits = 0;
    foreach (str_split($bytes) as $b) {
        $buf = ($buf << 8) | ord($b);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= TOTP_B32[($buf >> $bits) & 0x1F];
        }
    }
    if ($bits > 0) $out .= TOTP_B32[($buf << (5 - $bits)) & 0x1F];
    return $out;
}

/** Base32 字符串 → 二进制（处理 padding 忽略、小写容错） */
function totp_b32_decode(string $b32): string
{
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $map = array_flip(str_split(TOTP_B32));
    $out = '';
    $buf = 0;
    $bits = 0;
    foreach (str_split($b32) as $c) {
        $buf = ($buf << 5) | ($map[$c] ?? 0);
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buf >> $bits) & 0xFF);
        }
    }
    return $out;
}

/** 指定时间戳生成 6 位动态码 */
function totp_code(string $secret, int $timestamp = 0, int $step = 30): string
{
    if ($timestamp === 0) $timestamp = time();
    $counter = intdiv($timestamp, $step);
    // counter → 8 字节 big-endian
    $msg = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
    $key = totp_b32_decode($secret);
    if ($key === '') $key = str_pad('', 20, "\x00"); // 防御性兜底
    $hash = hash_hmac('sha1', $msg, $key, true);
    // RFC 6238 动态截断
    $offset = ord($hash[19]) & 0x0F;
    $bin = ((ord($hash[$offset]) & 0x7F) << 24)
         | ((ord($hash[$offset + 1]) & 0xFF) << 16)
         | ((ord($hash[$offset + 2]) & 0xFF) << 8)
         | (ord($hash[$offset + 3]) & 0xFF);
    $code = str_pad((string)($bin % 1000000), 6, '0', STR_PAD_LEFT);
    return $code;
}

/**
 * 验证动态码（±1 窗口容忍）
 * 时序安全：用 hash_equals 逐位比对，避免 oracle 攻击
 */
function totp_verify(string $secret, string $code, int $window = 1, int $step = 30): bool
{
    $code = preg_replace('/[^0-9]/', '', $code);
    if (strlen($code) !== 6) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $expected = totp_code($secret, $now + $i * $step, $step);
        if (hash_equals($expected, $code)) return true;
    }
    return false;
}

/** 生成 otpauth:// URL（前端传给手机 App 扫码 / 手动输入密钥） */
function totp_provisioning_url(string $secret, string $label, string $issuer = 'SolarPanel'): string
{
    $labelEnc = urlencode($label);
    $issuerEnc = urlencode($issuer);
    return sprintf(
        'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        $labelEnc, $secret, $issuerEnc
    );
}
