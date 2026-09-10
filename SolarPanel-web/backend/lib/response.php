<?php
/**
 * 统一 JSON 输出与请求参数辅助
 * 返回格式：{ code: 0 成功, data, msg }
 */
function json_out(int $code, $data = null, string $msg = 'ok'): void
{
    // 丢弃缓冲区中的意外输出（PHP 通知/警告、BOM 等），保证客户端拿到纯 JSON
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['code' => $code, 'data' => $data, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok($data = null): void
{
    json_out(0, $data);
}

function fail(string $msg, int $code = 1): void
{
    json_out($code, null, $msg);
}

/** 请求体：优先 JSON，其次表单 */
function json_body(): array
{
    static $body = null;
    if ($body === null) {
        $raw = file_get_contents('php://input');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $body = is_array($decoded) ? $decoded : [];
        if (empty($body) && !empty($_POST)) {
            $body = $_POST;
        }
    }
    return $body;
}

/** 依次从 JSON body / POST / GET 取参数 */
function param(string $key, $default = null)
{
    $b = json_body();
    if (array_key_exists($key, $b)) return $b[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    return $default;
}

function str_param(string $key, string $default = ''): string
{
    $v = param($key, $default);
    return is_scalar($v) ? trim((string)$v) : $default;
}

function int_param(string $key, int $default = 0): int
{
    $v = param($key, $default);
    return is_numeric($v) ? (int)$v : $default;
}

/** 对输入进行 HTML 转义（防 XSS，输出层主要在前端，后端入库前统一清洗） */
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/* ==================== 致命错误兜底：统一转 JSON（避免前端只看到空白 HTTP 500） ==================== */

/** 将致命错误 / 未捕获异常以 JSON 形式返回（已有正常响应输出时不干预） */
function sp_fatal_to_json(string $msg): void
{
    if (headers_sent()) return; // 正常 JSON 已发送（json_out 会先发 header），不重复输出
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 500, 'data' => null, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
}

// 致命错误（E_ERROR / E_PARSE / 未定义函数 / 类型错误等）→ JSON，管理员能直接看到真实原因
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        sp_fatal_to_json('服务器错误：' . $e['message'] . '（' . basename($e['file']) . ' 第 ' . $e['line'] . ' 行）');
    }
});

// 未捕获的异常 / Error（PHP 7+ 的 TypeError 等）→ JSON
set_exception_handler(function ($e): void {
    sp_fatal_to_json('服务器异常：' . $e->getMessage() . '（' . basename($e->getFile()) . ' 第 ' . $e->getLine() . ' 行）');
});
