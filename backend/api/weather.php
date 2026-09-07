<?php
/**
 * 天气接口（公开，主页无需登录）
 * GET ?action=current[&city=北京]
 *   - 不传 city：按访客 IP 自动定位
 *   - 传 city：按指定城市查询
 * 数据源：open-meteo 优先，wttr.in 回退（均免费、无需 key）；IP 定位：ip-api.com / ipapi.co
 * 结果缓存 30 分钟（城市或 IP 维度），目录 frontend/uploads/weather/
 */
require_once __DIR__ . '/../lib/response.php';
// HTTP 抓取公共库（TLS 证书严格校验）
require_once __DIR__ . '/../lib/http.php';

// 公开接口：结果含访客定位信息，禁止中间代理 / CDN 缓存
header('Cache-Control: no-store, max-age=0');

@set_time_limit(25);

/** 天气数据源抓取（URL 全部内置，无用户可控输入；实现见 lib/http.php 的 sp_http_simple） */
function w_fetch(string $u, int $timeout = 5): string
{
    return sp_http_simple($u, $timeout);
}

/** WMO 天气代码 → [中文描述, emoji] */
function w_wmo(int $code, bool $day = true): array
{
    $map = [
        0  => ['晴', $day ? '☀️' : '🌙'],
        1  => ['大致晴朗', $day ? '🌤️' : '🌙'],
        2  => ['多云', '⛅'],
        3  => ['阴', '☁️'],
        45 => ['雾', '🌫️'],
        48 => ['雾凇', '🌫️'],
        51 => ['小毛毛雨', '🌦️'],
        53 => ['毛毛雨', '🌦️'],
        55 => ['浓毛毛雨', '🌧️'],
        56 => ['冻毛毛雨', '🌧️'],
        57 => ['冻毛毛雨', '🌧️'],
        61 => ['小雨', '🌦️'],
        63 => ['中雨', '🌧️'],
        65 => ['大雨', '🌧️'],
        66 => ['冻雨', '🌧️'],
        67 => ['冻雨', '🌧️'],
        71 => ['小雪', '🌨️'],
        73 => ['中雪', '🌨️'],
        75 => ['大雪', '❄️'],
        77 => ['雪粒', '🌨️'],
        80 => ['小阵雨', '🌦️'],
        81 => ['阵雨', '🌦️'],
        82 => ['强阵雨', '⛈️'],
        85 => ['阵雪', '🌨️'],
        86 => ['强阵雪', '❄️'],
        95 => ['雷阵雨', '⛈️'],
        96 => ['雷阵雨伴冰雹', '⛈️'],
        99 => ['强雷暴伴冰雹', '⛈️'],
    ];
    return $map[$code] ?? ['未知', '🌡️'];
}

/** wttr.in 天气代码 → [中文描述, emoji]（j1 格式不返回可靠中文，自行映射） */
function w_wttr_code(int $code): array
{
    $map = [
        113 => ['晴', '☀️'],
        116 => ['多云', '⛅'],
        119 => ['阴', '☁️'],
        122 => ['阴', '☁️'],
        143 => ['薄雾', '🌫️'],
        145 => ['雾凇', '🌫️'],
        149 => ['霾', '🌫️'],
        153 => ['浓雾凇', '🌫️'],
        154 => ['薄雾凇', '🌫️'],
        176 => ['零星小雨', '🌦️'],
        179 => ['零星小雪', '🌨️'],
        182 => ['雨夹雪', '🌨️'],
        185 => ['冻毛毛雨', '🌧️'],
        200 => ['雷阵雨', '⛈️'],
        227 => ['吹雪', '🌨️'],
        230 => ['暴风雪', '❄️'],
        248 => ['雾', '🌫️'],
        260 => ['冻雾', '🌫️'],
        263 => ['零星毛毛雨', '🌦️'],
        266 => ['毛毛雨', '🌦️'],
        281 => ['冻毛毛雨', '🌧️'],
        284 => ['强冻毛毛雨', '🌧️'],
        293 => ['零星小雨', '🌦️'],
        296 => ['小雨', '🌦️'],
        299 => ['间歇性中雨', '🌧️'],
        302 => ['中雨', '🌧️'],
        305 => ['间歇性大雨', '🌧️'],
        308 => ['大雨', '🌧️'],
        311 => ['小冻雨', '🌧️'],
        314 => ['强冻雨', '🌧️'],
        317 => ['小雨夹雪', '🌨️'],
        320 => ['强雨夹雪', '🌨️'],
        323 => ['零星小雪', '🌨️'],
        326 => ['小雪', '🌨️'],
        329 => ['间歇性中雪', '🌨️'],
        332 => ['中雪', '🌨️'],
        335 => ['间歇性大雪', '❄️'],
        338 => ['大雪', '❄️'],
        350 => ['冰粒', '🌨️'],
        353 => ['小阵雨', '🌦️'],
        356 => ['强阵雨', '🌧️'],
        359 => ['暴雨', '⛈️'],
        362 => ['小阵雨夹雪', '🌨️'],
        365 => ['强阵雨夹雪', '🌨️'],
        368 => ['小阵雪', '🌨️'],
        371 => ['强阵雪', '❄️'],
        374 => ['小冰粒阵雨', '🌨️'],
        377 => ['强冰粒阵雨', '🌨️'],
        386 => ['雷阵雨', '⛈️'],
        389 => ['强雷阵雨', '⛈️'],
        392 => ['雷阵雪', '⛈️'],
        395 => ['强雷阵雪', '⛈️'],
    ];
    // 未收录代码（wttr 扩展码段）回退到 open-meteo WMO 表再试
    return $map[$code] ?? w_wmo($code);
}

/** 获取访客公网 IP（兼容反向代理） */
function w_client_ip(): string
{
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        foreach (explode(',', $fwd) as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    $ip = $_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/** IP → 经纬度+城市名（多源回退） */
function w_locate_by_ip(string $ip): array
{
    // 源 1：ip-api.com（http，中文）
    $j = @json_decode(w_fetch('http://ip-api.com/json/' . rawurlencode($ip) . '?lang=zh-CN&fields=status,city,regionName,lat,lon', 5), true);
    if (is_array($j) && ($j['status'] ?? '') === 'success' && isset($j['lat'], $j['lon'])) {
        return ['lat' => (float)$j['lat'], 'lon' => (float)$j['lon'], 'city' => $j['city'] ?: ($j['regionName'] ?? '')];
    }
    // 源 2：ipapi.co（https）
    $j = @json_decode(w_fetch('https://ipapi.co/' . rawurlencode($ip) . '/json/', 5), true);
    if (is_array($j) && isset($j['latitude'], $j['longitude'])) {
        return ['lat' => (float)$j['latitude'], 'lon' => (float)$j['longitude'], 'city' => $j['city'] ?? ''];
    }
    return [];
}

/** 城市名 → 经纬度（open-meteo geocoding 优先，wttr.in 兜底；支持中文） */
function w_locate_by_city(string $city): array
{
    // 源 1：open-meteo geocoding（支持中文，返回本地化城市名）
    $u = 'https://geocoding-api.open-meteo.com/v1/search?name=' . rawurlencode($city) . '&count=1&language=zh&format=json';
    $j = @json_decode(w_fetch($u, 6), true);
    if (is_array($j) && !empty($j['results'][0])) {
        $r = $j['results'][0];
        return [
            'lat'  => (float)$r['latitude'],
            'lon'  => (float)$r['longitude'],
            'city' => $r['name'] ?? $city,
        ];
    }
    // 源 2：wttr.in 按城市名查询 j1，取 nearest_area 坐标（城市名显示用户输入，避免英文回显）
    $j = @json_decode(w_fetch('https://wttr.in/' . rawurlencode($city) . '?format=j1', 6), true);
    $na = is_array($j) ? ($j['nearest_area'][0] ?? []) : [];
    if (isset($na['latitude'], $na['longitude'])) {
        return [
            'lat'  => (float)$na['latitude'],
            'lon'  => (float)$na['longitude'],
            'city' => $city,
        ];
    }
    return [];
}

/** 数据源 1：open-meteo（按经纬度）；失败返回 null */
function w_provider_openmeteo(float $lat, float $lon): ?array
{
    $wu = 'https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon
        . '&current=temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m,is_day&timezone=auto';
    $wj = @json_decode(w_fetch($wu, 6), true);
    if (!is_array($wj) || isset($wj['error']) || !isset($wj['current']['weather_code'])) return null;
    $cur = $wj['current'];
    [$desc, $emoji] = w_wmo((int)$cur['weather_code'], (int)($cur['is_day'] ?? 1) === 1);
    return [
        'temp'     => round((float)$cur['temperature_2m']),
        'desc'     => $desc,
        'emoji'    => $emoji,
        'humidity' => (int)($cur['relative_humidity_2m'] ?? 0),
        'wind'     => round((float)($cur['wind_speed_10m'] ?? 0), 1),
    ];
}

/** 数据源 2：wttr.in（城市名或 "lat,lon"，免费无需 key）；失败返回 null */
function w_provider_wttr(string $query): ?array
{
    $u = 'https://wttr.in/' . rawurlencode($query) . '?format=j1';
    $j = @json_decode(w_fetch($u, 8), true);
    if (!is_array($j) || empty($j['current_condition'][0])) return null;
    $c = $j['current_condition'][0];
    // j1 格式不返回可靠中文描述，统一按天气代码映射中文
    [$desc, $emoji] = w_wttr_code((int)($c['weatherCode'] ?? -1));
    $city = $j['nearest_area'][0]['areaName'][0]['value'] ?? '';
    return [
        'temp'     => round((float)($c['temp_C'] ?? 0)),
        'desc'     => $desc,
        'emoji'    => $emoji,
        'humidity' => (int)($c['humidity'] ?? 0),
        'wind'     => round((float)($c['windspeedKmph'] ?? 0), 1),
        'city'     => $city,
    ];
}

if (($_GET['action'] ?? 'current') !== 'current') fail('未知操作');

$city = trim(str_param('city', ''));
$cacheDir = __DIR__ . '/../../frontend/uploads/weather';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

// 缓存键：城市维度（v2：修复过定位回退逻辑，换前缀使旧缓存失效）；IP 模式按 IP 维度
$ip = w_client_ip();
$cacheKey = md5($city !== '' ? 'city2:' . $city : 'ip:' . $ip);
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';
if (is_file($cacheFile) && filemtime($cacheFile) > time() - 1800) {
    $cached = @json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($cached) && isset($cached['temp'])) {
        $cached['cached'] = true;
        ok($cached);
    }
}

// 1. 定位
$loc = [];
if ($city !== '') {
    // 手动指定城市：定位失败必须明确报错，绝不静默回退 IP 定位（否则用户会看到别的城市，误以为填写无效）
    $loc = w_locate_by_city($city);
    if (!$loc) {
        fail('城市「' . $city . '」定位失败：名称可能有误，或定位服务（open-meteo / wttr.in）暂不可达；可尝试换写法（如拼音 Beijing）后刷新主页重试');
    }
} else {
    // 仅当访客 IP 是公网地址时才用 IP 定位（内网/本机地址直接走出口 IP 兜底）
    $isPublic = $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($isPublic) {
        $loc = w_locate_by_ip($ip);
    }
    if (!$loc) {
        // 私有/保留地址（本机或内网反代）：用服务器出口 IP 兜底
        $j = @json_decode(w_fetch('http://ip-api.com/json/?lang=zh-CN&fields=status,city,regionName,lat,lon', 5), true);
        if (is_array($j) && ($j['status'] ?? '') === 'success') {
            $loc = ['lat' => (float)$j['lat'], 'lon' => (float)$j['lon'], 'city' => $j['city'] ?? ''];
        }
    }
    if (!$loc) fail('定位失败：无法确定地区，可在后台手动指定城市');
}

// 2. 天气：open-meteo 优先，失败/限额时回退 wttr.in（均为免费无需 key）
$weather = w_provider_openmeteo($loc['lat'], $loc['lon']);
if ($weather === null) {
    // wttr 支持城市名或 "lat,lon"
    $wttrQuery = $city !== '' ? $city : ($loc['lat'] . ',' . $loc['lon']);
    $wttr = w_provider_wttr($wttrQuery);
    if ($wttr !== null) {
        // 城市名优先用定位阶段结果（open-meteo/ip-api 返回中文名），wttr 的英文名仅兜底
        $wttr['city'] = ($loc['city'] ?? '') !== '' ? $loc['city'] : ($wttr['city'] ?? '');
        $weather = $wttr;
    }
}
if ($weather === null) {
    fail('天气数据获取失败（天气服务暂不可用或服务器网络受限），请稍后重试');
}

$data = [
    'city'     => $weather['city'] ?? ($loc['city'] !== '' ? $loc['city'] : ($city !== '' ? $city : '未知地区')),
    'temp'     => $weather['temp'],
    'desc'     => $weather['desc'],
    'emoji'    => $weather['emoji'],
    'humidity' => $weather['humidity'],
    'wind'     => $weather['wind'],
    'cached'   => false,
];

// 缓存目录文件数上限：IP 缓存键可被伪造 X-Forwarded-For 批量制造，超限清理最旧文件，防占满磁盘
$capFiles = @glob($cacheDir . '/*.json');
if (is_array($capFiles) && count($capFiles) > 300) {
    usort($capFiles, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
    foreach (array_slice($capFiles, 0, count($capFiles) - 300) as $old) {
        @unlink($old);
    }
}
@file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
ok($data);
