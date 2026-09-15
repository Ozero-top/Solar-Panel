<?php
/**
 * 浏览器书签导入（Netscape Bookmark HTML 格式）
 * POST action=bookmarks  multipart/form-data（字段名：file）
 *   → 解析 HTML，返回结构化 { groups: [{title, items: [{title, url, description}]}], total }
 *   → 不直接落库，让前端预览后确认再调用 apply
 * POST action=apply  { groups: [{title, items: [{title, url, description}]}] }
 *   → 将解析结果落库为 item_groups + items
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/security.php';

auth_session_start();
$u = require_login();

if (user_has_role($u, 'viewer')) fail('只读账号不可导入书签', 403);
$pdo = db();

$action = str_param('action', 'bookmarks');

/* ================ 解析 Netscape Bookmark HTML ================ */
/**
 * Netscape 书签格式：
 *   <H3 ADD_DATE="...">分组名</H3>
 *   <DL><p>
 *     <DT><A HREF="https://...">卡片标题</A>
 *     <DD>描述（可选）
 *   </DL><p>
 */
function parse_netscape_bookmark(string $html): array
{
    // 去除 DOCTYPE / COMMENT / meta 等无关内容
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    $groups = [];
    $current = null;
    $inDl = false;

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $h3Nodes = $dom->getElementsByTagName('h3');
    foreach ($h3Nodes as $h3) {
        $title = trim($h3->textContent);
        if ($title === '') continue;

        $items = [];
        // 找紧接着的 DL
        $dl = null;
        $next = $h3->nextSibling;
        while ($next) {
            if ($next instanceof DOMElement && strtolower($next->tagName) === 'dl') {
                $dl = $next;
                break;
            }
            if ($next instanceof DOMElement && strtolower($next->tagName) === 'h3') break;
            $next = $next->nextSibling;
        }
        if ($dl) {
            foreach ($dl->getElementsByTagName('a') as $a) {
                $href = trim($a->getAttribute('href'));
                $text = trim($a->textContent);
                if ($href === '' || $text === '') continue;
                // 协议白名单（与 Go 版一致）
                if (!preg_match('#^(https?|ftp)://#i', $href)) continue;
                $desc = '';
                $dd = $a->parentNode->nextSibling ?? null;
                while ($dd && !(($dd instanceof DOMElement) && strtolower($dd->tagName) === 'dd')) {
                    $dd = $dd->nextSibling;
                }
                if ($dd) $desc = trim($dd->textContent);
                $items[] = ['title' => mb_substr($text, 0, 50), 'url' => mb_substr($href, 0, 1000), 'description' => mb_substr($desc, 0, 500)];
            }
        }
        if ($title !== '' && count($items) > 0) {
            $groups[] = ['title' => mb_substr($title, 0, 50), 'items' => $items];
        }
    }

    // 如果完全没 H3（扁平列表），全部塞进"导入的书签"分组
    if (count($groups) === 0) {
        $all = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            $text = trim($a->textContent);
            if ($href === '' || $text === '' || !preg_match('#^(https?|ftp)://#i', $href)) continue;
            $all[] = ['title' => mb_substr($text, 0, 50), 'url' => mb_substr($href, 0, 1000), 'description' => ''];
        }
        if (count($all) > 0) $groups[] = ['title' => '导入的书签', 'items' => $all];
    }

    $total = 0;
    foreach ($groups as $g) $total += count($g['items']);
    return ['groups' => $groups, 'total' => $total];
}

/* ================ 路由 ================ */

if ($action === 'bookmarks') {
    // multipart/form-data 上传
    if (!isset($_FILES['file'])) fail('未收到文件');
    $f = $_FILES['file'];
    if ((int)($f['error'] ?? 0) !== UPLOAD_ERR_OK) fail('文件上传失败');
    if (!is_uploaded_file($f['tmp_name'])) fail('非法上传');
    $size = filesize($f['tmp_name']);
    if ($size > 5 * 1024 * 1024) fail('文件超过 5MB');

    $content = (string)file_get_contents($f['tmp_name']);
    if (mb_detect_encoding($content) === false) fail('文件编码无法识别');

    $result = parse_netscape_bookmark($content);
    if ($result['total'] === 0) fail('文件里没有找到可导入的书签');

    ok($result);
}

if ($action === 'apply') {
    $body = json_body();
    $groups = $body['groups'] ?? [];
    if (!is_array($groups)) fail('参数格式错误');

    $pdo->beginTransaction();
    try {
        $maxSort = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) FROM item_groups')->fetchColumn();

        $gSt = $pdo->prepare('INSERT INTO item_groups (title, description, sort, is_visible, user_id) VALUES (?, \'浏览器书签导入\', ?, 1, ?)');
        $iSt = $pdo->prepare('INSERT INTO items (group_id, title, url, description, icon_type, icon_value, icon_bg, open_method, sort, user_id) VALUES (?, ?, ?, ?, \'favicon\', \'\', \'\', 2, ?, ?)');

        $gid = 0;
        $gCount = 0; $iCount = 0;
        foreach ($groups as $g) {
            if (!is_array($g)) continue;
            $gTitle = mb_substr(trim((string)($g['title'] ?? '')), 0, 50);
            if ($gTitle === '') continue;
            $items = $g['items'] ?? [];
            if (!is_array($items) || count($items) === 0) continue;

            $maxSort++;
            $gSt->execute([$gTitle, $maxSort, (int)$u['id']]);
            $gid = (int)$pdo->lastInsertId();
            $gCount++; $iSort = 0;

            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $t = mb_substr(trim((string)($it['title'] ?? '')), 0, 50);
                $url = mb_substr(trim((string)($it['url'] ?? '')), 0, 1000);
                if ($t === '' || $url === '') continue;
                if (!preg_match('#^(https?|ftp)://#i', $url)) continue;
                $desc = mb_substr(trim((string)($it['description'] ?? '')), 0, 1000);
                $iSt->execute([$gid, $t, $url, $desc, $iSort++, (int)$u['id']]);
                $iCount++;
            }
        }
        $pdo->commit();
        sp_log('import.bookmarks', "groups=$gCount items=$iCount", 'success', $u['username'] ?? '', $u['role'] ?? 'admin');
        ok(['groups' => $gCount, 'items' => $iCount]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        sp_log('import.bookmarks', $e->getMessage(), 'fail', $u['username'] ?? '', $u['role'] ?? 'admin');
        fail('导入失败：' . $e->getMessage());
    }
}

fail('未知操作');
