<?php
/**
 * 热点新闻数据源定义（news.php 与 settings.php 共用）
 *
 * 每个数据源：
 *   id       源标识（英文，用于缓存文件名 / 前后端传参）
 *   name     中文名
 *   color    主题色（前端卡片强调色，hex）
 *   type     hottest = 热榜（编号排名列表）/ realtime = 时间线
 *   home     源站点地址
 *   interval 刷新间隔（秒），间隔内直接用缓存不回源
 *   fetch    抓取闭包，返回 [['id','title','url','mobile_url'?,'info'?,'pub'(unix秒)?], ...]
 *
 * 安全：所有抓取地址均为内置固定地址，不含任何用户输入，无 SSRF 面。
 * 闭包内通过 news_http() 发请求（由 news.php 定义，settings.php 仅校验 id 不执行闭包）。
 */

/** 全部数据源定义 */
function news_source_defs(): array
{
    $defs = [
        [
            'id' => 'zhihu', 'name' => '知乎热榜', 'color' => '#0084ff', 'type' => 'hottest',
            'home' => 'https://www.zhihu.com/hot', 'interval' => 600,
            'fetch' => function (): array {
                $j = json_decode(news_http(
                    'https://www.zhihu.com/api/v3/feed/topstory/hot-list-web?limit=20&desktop=true',
                    8, ['Referer: https://www.zhihu.com/']
                ), true);
                $out = [];
                foreach (($j['data'] ?? []) as $k) {
                    $t = trim((string)($k['target']['title_area']['text'] ?? ''));
                    $u = trim((string)($k['target']['link']['url'] ?? ''));
                    if ($t === '' || $u === '') continue;
                    $out[] = [
                        'id' => 'zh' . substr(md5($u), 0, 12),
                        'title' => $t,
                        'url' => $u,
                        'info' => trim((string)($k['target']['metrics_area']['text'] ?? '')),
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'baidu', 'name' => '百度热搜', 'color' => '#315efb', 'type' => 'hottest',
            'home' => 'https://top.baidu.com/board?tab=realtime', 'interval' => 600,
            'fetch' => function (): array {
                $j = json_decode(news_http(
                    'https://top.baidu.com/api/board?platform=pc&tab=realtime',
                    8, ['Referer: https://top.baidu.com/']
                ), true);
                $out = [];
                foreach (($j['data']['cards'][0]['content'] ?? []) as $c) {
                    $word = trim((string)($c['word'] ?? ''));
                    if ($word === '') continue;
                    $hot = (int)($c['hotScore'] ?? 0);
                    $out[] = [
                        'id' => 'bd' . substr(md5($word), 0, 12),
                        'title' => $word,
                        'url' => 'https://www.baidu.com/s?wd=' . rawurlencode($word),
                        'info' => $hot > 10000 ? round($hot / 10000, 1) . '万' : ($hot > 0 ? (string)$hot : ''),
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'bilibili', 'name' => '哔哩哔哩热门', 'color' => '#fb7299', 'type' => 'hottest',
            'home' => 'https://www.bilibili.com/v/popular/all', 'interval' => 600,
            'fetch' => function (): array {
                $j = json_decode(news_http(
                    'https://api.bilibili.com/x/web-interface/popular?ps=20&pn=1',
                    8, ['Referer: https://www.bilibili.com/']
                ), true);
                $out = [];
                foreach (($j['data']['list'] ?? []) as $v) {
                    $title = trim((string)($v['title'] ?? ''));
                    $bvid = trim((string)($v['bvid'] ?? ''));
                    if ($title === '' || $bvid === '') continue;
                    $view = (int)($v['stat']['view'] ?? 0);
                    $out[] = [
                        'id' => 'bl' . $bvid,
                        'title' => $title,
                        'url' => 'https://www.bilibili.com/video/' . $bvid,
                        'mobile_url' => !empty($v['short_link_v2']) ? $v['short_link_v2'] : null,
                        'info' => $view > 10000 ? round($view / 10000, 1) . '万播放' : ($view > 0 ? $view . '播放' : ''),
                        'pub' => !empty($v['pubdate']) ? (int)$v['pubdate'] : null,
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'weibo', 'name' => '微博热搜', 'color' => '#ef4444', 'type' => 'hottest',
            'home' => 'https://s.weibo.com/top/summary', 'interval' => 300,
            'fetch' => function (): array {
                // 公开热搜页（需带一个访客 Cookie，与 NewsNow 项目同款公开 SUB）
                $html = news_http(
                    'https://s.weibo.com/top/summary?cate=realtimehot',
                    8,
                    [
                        'Referer: https://s.weibo.com/',
                        'Cookie: SUB=_2AkMWIuNSf8NxqwJRmP8dy2rhaoV2ygrEieKgfhKJJRMxHRl-yT9jqk86tRB6PaLNvQZR6zYUcYVT1zSjoSreQHidcUq7',
                    ]
                );
                $dom = news_dom($html);
                $xp = new DOMXPath($dom);
                $rows = $xp->query('//div[@id="pl_top_realtimehot"]//table//tbody/tr');
                $flagMap = ['新' => '🆕', '热' => '🔥', '爆' => '💥'];
                $out = [];
                foreach ($rows as $row) {
                    $a = $xp->query('.//td[contains(@class,"td-02")]//a', $row)->item(0);
                    if (!$a) continue;
                    $href = trim((string)$a->getAttribute('href'));
                    $title = trim($a->textContent);
                    if ($title === '' || $href === '' || strpos($href, 'javascript:') !== false) continue;
                    $flag = trim($xp->query('.//td[contains(@class,"td-03")]', $row)->item(0)?->textContent ?? '');
                    $out[] = [
                        'id' => 'wb' . substr(md5($href), 0, 12),
                        'title' => $title,
                        'url' => 'https://s.weibo.com' . $href,
                        'info' => $flagMap[$flag] ?? '',
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'hackernews', 'name' => 'Hacker News', 'color' => '#ff6600', 'type' => 'hottest',
            'home' => 'https://news.ycombinator.com/', 'interval' => 900,
            'fetch' => function (): array {
                // Algolia 官方搜索 API，单请求取首页热榜，无需 key
                $j = json_decode(news_http(
                    'https://hn.algolia.com/api/v1/search?tags=front_page&hitsPerPage=20',
                    8
                ), true);
                $out = [];
                foreach (($j['hits'] ?? []) as $h) {
                    $title = trim((string)($h['title'] ?? ''));
                    if ($title === '') continue;
                    $url = !empty($h['url']) ? $h['url'] : 'https://news.ycombinator.com/item?id=' . ($h['objectID'] ?? '');
                    $pts = (int)($h['points'] ?? 0);
                    $cmt = (int)($h['num_comments'] ?? 0);
                    $out[] = [
                        'id' => 'hn' . ($h['objectID'] ?? substr(md5($url), 0, 10)),
                        'title' => $title,
                        'url' => $url,
                        'info' => $pts . ' 分 · ' . $cmt . ' 评论',
                        'pub' => !empty($h['created_at_i']) ? (int)$h['created_at_i'] : null,
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'github', 'name' => 'GitHub Trending', 'color' => '#6e7681', 'type' => 'hottest',
            'home' => 'https://github.com/trending', 'interval' => 1800,
            'fetch' => function (): array {
                $html = news_http('https://github.com/trending?since=daily', 8);
                $dom = news_dom($html);
                $xp = new DOMXPath($dom);
                $out = [];
                foreach ($xp->query('//article[contains(@class,"Box-row")]') as $art) {
                    $a = $xp->query('.//h2//a', $art)->item(0);
                    if (!$a) continue;
                    $href = trim((string)$a->getAttribute('href'));
                    $name = trim(preg_replace('/\s+/', ' ', $a->textContent));
                    if ($href === '') continue;
                    $descNode = $xp->query('.//p', $art)->item(0);
                    $desc = $descNode ? trim(preg_replace('/\s+/', ' ', $descNode->textContent)) : '';
                    $starNode = $xp->query('.//a[contains(@href,"stargazers")]', $art)->item(0);
                    $stars = $starNode ? trim(preg_replace('/\s+/', '', $starNode->textContent)) : '';
                    $out[] = [
                        'id' => 'gh' . substr(md5($href), 0, 12),
                        'title' => $name !== '' ? $name : $href,
                        'url' => 'https://github.com' . $href,
                        'info' => $stars !== '' ? '★ ' . $stars : '',
                    ];
                    // $desc 暂不使用（保持条目结构统一）
                    unset($desc);
                }
                return $out;
            },
        ],
        [
            'id' => 'thepaper', 'name' => '澎湃新闻', 'color' => '#d43c33', 'type' => 'hottest',
            'home' => 'https://www.thepaper.cn/', 'interval' => 900,
            'fetch' => function (): array {
                $j = json_decode(news_http('https://cache.thepaper.cn/contentapi/wwwIndex/rightSidebar', 8), true);
                $out = [];
                foreach (($j['data']['hotNews'] ?? []) as $n) {
                    $title = trim((string)($n['name'] ?? ''));
                    $cid = trim((string)($n['contId'] ?? ''));
                    if ($title === '' || $cid === '') continue;
                    $num = (int)($n['interactionNum'] ?? 0);
                    $out[] = [
                        'id' => 'tp' . $cid,
                        'title' => $title,
                        'url' => 'https://www.thepaper.cn/newsDetail_forward_' . $cid,
                        'info' => $num > 0 ? $num . ' 互动' : '',
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'toutiao', 'name' => '今日头条', 'color' => '#f04142', 'type' => 'hottest',
            'home' => 'https://www.toutiao.com/', 'interval' => 600,
            'fetch' => function (): array {
                $j = json_decode(news_http(
                    'https://www.toutiao.com/hot-event/hot-board/?origin=toutiao_pc',
                    8, ['Referer: https://www.toutiao.com/']
                ), true);
                $out = [];
                foreach (($j['data'] ?? []) as $k) {
                    $title = trim((string)($k['Title'] ?? ''));
                    $cid = trim((string)($k['ClusterIdStr'] ?? ''));
                    if ($title === '' || $cid === '') continue;
                    $hot = (int)($k['HotValue'] ?? 0);
                    $out[] = [
                        'id' => 'tt' . $cid,
                        'title' => $title,
                        'url' => 'https://www.toutiao.com/trending/' . $cid . '/',
                        'info' => $hot > 10000 ? round($hot / 10000, 1) . '万热度' : ($hot > 0 ? $hot . '热度' : ''),
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'tieba', 'name' => '百度贴吧', 'color' => '#3177f6', 'type' => 'hottest',
            'home' => 'https://tieba.baidu.com/hottopic/browse/topicList', 'interval' => 900,
            'fetch' => function (): array {
                $j = json_decode(news_http(
                    'https://tieba.baidu.com/hottopic/browse/topicList',
                    8, ['Referer: https://tieba.baidu.com/']
                ), true);
                $out = [];
                foreach (($j['data']['bang_topic']['topic_list'] ?? []) as $k) {
                    $title = trim((string)($k['topic_name'] ?? ''));
                    $url = trim((string)($k['topic_url'] ?? ''));
                    if ($title === '' || $url === '') continue;
                    // 接口返回的 &amp; 实体解码
                    $url = htmlspecialchars_decode($url);
                    $out[] = [
                        'id' => 'tb' . ($k['topic_id'] ?? substr(md5($url), 0, 12)),
                        'title' => $title,
                        'url' => $url,
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'douban', 'name' => '豆瓣电影', 'color' => '#2e963f', 'type' => 'hottest',
            'home' => 'https://movie.douban.com/', 'interval' => 3600,
            'fetch' => function (): array {
                $j = json_decode(news_http(
                    'https://m.douban.com/rexxar/api/v2/subject/recent_hot/movie',
                    8, ['Referer: https://movie.douban.com/', 'Accept: application/json, text/plain, */*']
                ), true);
                $out = [];
                foreach (($j['items'] ?? []) as $m) {
                    $title = trim((string)($m['title'] ?? ''));
                    $mid = trim((string)($m['id'] ?? ''));
                    if ($title === '' || $mid === '') continue;
                    $rating = $m['rating']['value'] ?? 0;
                    $out[] = [
                        'id' => 'db' . $mid,
                        'title' => $title,
                        'url' => 'https://movie.douban.com/subject/' . $mid,
                        'info' => $rating ? '★ ' . $rating : '',
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'hupu', 'name' => '虎扑', 'color' => '#c81623', 'type' => 'hottest',
            'home' => 'https://bbs.hupu.com/all-gambia', 'interval' => 900,
            'fetch' => function (): array {
                $html = news_http('https://bbs.hupu.com/topic-daily-hot', 8);
                // 主干道热帖：<li class="bbs-sl-web-post-body"> ... <a href="/xxx.html" class="p-title">标题</a>
                if (!preg_match_all(
                    '#<li class="bbs-sl-web-post-body">[\s\S]*?<a href="(/[^"]+?\.html)"[^>]*?class="p-title"[^>]*>([^<]+)</a>#',
                    $html,
                    $mm,
                    PREG_SET_ORDER
                )) {
                    return [];
                }
                $out = [];
                foreach ($mm as $m) {
                    $path = $m[1];
                    $title = trim($m[2]);
                    if ($title === '' || $path === '') continue;
                    $out[] = [
                        'id' => 'hp' . substr(md5($path), 0, 12),
                        'title' => $title,
                        'url' => 'https://bbs.hupu.com' . $path,
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'juejin', 'name' => '稀土掘金', 'color' => '#1e80ff', 'type' => 'hottest',
            'home' => 'https://juejin.cn/hot/articles', 'interval' => 1800,
            'fetch' => function (): array {
                $j = json_decode(news_http(
                    'https://api.juejin.cn/content_api/v1/content/article_rank?category_id=1&type=hot&spider=0',
                    8, ['Referer: https://juejin.cn/']
                ), true);
                $out = [];
                foreach (($j['data'] ?? []) as $k) {
                    $title = trim((string)($k['content']['title'] ?? ''));
                    $cid = trim((string)($k['content']['content_id'] ?? ''));
                    if ($title === '' || $cid === '') continue;
                    $out[] = [
                        'id' => 'jj' . $cid,
                        'title' => $title,
                        'url' => 'https://juejin.cn/post/' . $cid,
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'sspai', 'name' => '少数派', 'color' => '#d71a1b', 'type' => 'hottest',
            'home' => 'https://sspai.com/', 'interval' => 1800,
            'fetch' => function (): array {
                $ts = (int)(microtime(true) * 1000);
                $j = json_decode(news_http(
                    'https://sspai.com/api/v1/article/tag/page/get?limit=20&offset=0&created_at=' . $ts
                    . '&tag=%E7%83%AD%E9%97%A8%E6%96%87%E7%AB%A0&released=false',
                    8, ['Referer: https://sspai.com/']
                ), true);
                $out = [];
                foreach (($j['data'] ?? []) as $k) {
                    $title = trim((string)($k['title'] ?? ''));
                    $aid = trim((string)($k['id'] ?? ''));
                    if ($title === '' || $aid === '') continue;
                    $out[] = [
                        'id' => 'sp' . $aid,
                        'title' => $title,
                        'url' => 'https://sspai.com/post/' . $aid,
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'nowcoder', 'name' => '牛客', 'color' => '#45a9f0', 'type' => 'hottest',
            'home' => 'https://www.nowcoder.com/discuss', 'interval' => 900,
            'fetch' => function (): array {
                $ts = (int)(microtime(true) * 1000);
                $j = json_decode(news_http(
                    'https://gw-c.nowcoder.com/api/sparta/hot-search/top-hot-pc?size=20&_=' . $ts . '&t=',
                    8, ['Referer: https://www.nowcoder.com/']
                ), true);
                $out = [];
                foreach (($j['data']['result'] ?? []) as $k) {
                    $title = trim((string)($k['title'] ?? ''));
                    $type = (int)($k['type'] ?? -1);
                    if ($title === '') continue;
                    if ($type === 74 && !empty($k['uuid'])) {
                        $url = 'https://www.nowcoder.com/feed/main/detail/' . $k['uuid'];
                        $nid = $k['uuid'];
                    } elseif ($type === 0 && !empty($k['id'])) {
                        $url = 'https://www.nowcoder.com/discuss/' . $k['id'];
                        $nid = $k['id'];
                    } else {
                        continue;
                    }
                    $out[] = [
                        'id' => 'nc' . $nid,
                        'title' => $title,
                        'url' => $url,
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'tencent', 'name' => '腾讯新闻', 'color' => '#147dff', 'type' => 'hottest',
            'home' => 'https://news.qq.com/', 'interval' => 1800,
            'fetch' => function (): array {
                $j = json_decode(news_http(
                    'https://i.news.qq.com/web_backend/v2/getTagInfo?tagId=aEWqxLtdgmQ%3D',
                    8, ['Referer: https://news.qq.com/']
                ), true);
                $out = [];
                foreach (($j['data']['tabs'][0]['articleList'] ?? []) as $n) {
                    $title = trim((string)($n['title'] ?? ''));
                    $url = trim((string)($n['link_info']['url'] ?? ''));
                    if ($title === '' || $url === '') continue;
                    $out[] = [
                        'id' => 'tx' . ($n['id'] ?? substr(md5($url), 0, 12)),
                        'title' => $title,
                        'url' => $url,
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'producthunt', 'name' => 'Product Hunt', 'color' => '#da552f', 'type' => 'hottest',
            'home' => 'https://www.producthunt.com/', 'interval' => 3600,
            'fetch' => function (): array {
                // 官方 Atom feed（每日新产品榜）
                return news_rss_items('https://www.producthunt.com/feed');
            },
        ],
        [
            'id' => 'freebuf', 'name' => 'Freebuf', 'color' => '#22a06b', 'type' => 'hottest',
            'home' => 'https://www.freebuf.com/', 'interval' => 1800,
            'fetch' => function (): array {
                // 网络安全资讯官方 RSS（按 feed 顺序即热度/时效）
                return news_rss_items('https://www.freebuf.com/feed');
            },
        ],
        [
            'id' => 'ithome', 'name' => 'IT之家', 'color' => '#d92626', 'type' => 'realtime',
            'home' => 'https://www.ithome.com/', 'interval' => 600,
            'fetch' => function (): array {
                return news_rss_items('https://www.ithome.com/rss/');
            },
        ],
        [
            'id' => 'solidot', 'name' => 'Solidot', 'color' => '#0d9488', 'type' => 'realtime',
            'home' => 'https://www.solidot.org/', 'interval' => 3600,
            'fetch' => function (): array {
                return news_rss_items('https://www.solidot.org/index.rss');
            },
        ],
        [
            'id' => 'wallstreetcn', 'name' => '华尔街见闻', 'color' => '#cf9b22', 'type' => 'realtime',
            'home' => 'https://wallstreetcn.com/live/global', 'interval' => 600,
            'fetch' => function (): array {
                $j = json_decode(news_http(
                    'https://api-one-wscn.awtmt.com/apiv1/content/lives?channel=global-channel&limit=20',
                    8
                ), true);
                $out = [];
                foreach (($j['data']['items'] ?? []) as $it) {
                    $title = trim((string)($it['title'] ?? ''));
                    $url = trim((string)($it['uri'] ?? ''));
                    if ($title === '' || $url === '') continue;
                    $out[] = [
                        'id' => 'ws' . ($it['id'] ?? substr(md5($url), 0, 12)),
                        'title' => $title,
                        'url' => $url,
                        'pub' => !empty($it['display_time']) ? (int)$it['display_time'] : null,
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'v2ex', 'name' => 'V2EX', 'color' => '#6b7a90', 'type' => 'realtime',
            'home' => 'https://www.v2ex.com/', 'interval' => 900,
            'fetch' => function (): array {
                $j = json_decode(news_http('https://www.v2ex.com/feed/share.json', 8), true);
                $out = [];
                foreach (($j['items'] ?? []) as $it) {
                    $title = trim((string)($it['title'] ?? ''));
                    $url = trim((string)($it['url'] ?? ''));
                    if ($title === '' || $url === '') continue;
                    $pub = strtotime((string)($it['date_published'] ?? $it['date_modified'] ?? ''));
                    $out[] = [
                        'id' => 'v2' . substr(md5($url), 0, 12),
                        'title' => $title,
                        'url' => $url,
                        'pub' => $pub ?: null,
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'douyin', 'name' => '抖音热点', 'color' => '#000000', 'type' => 'hottest',
            'home' => 'https://www.douyin.com/hot', 'interval' => 600,
            'fetch' => function (): array {
                $j = json_decode(news_http('https://www.iesdouyin.com/web/api/v2/hotsearch/billboard/word/', 8), true);
                $out = [];
                foreach (($j['word_list'] ?? []) as $w) {
                    $word = trim((string)($w['word'] ?? ''));
                    if ($word === '') continue;
                    $out[] = [
                        'id' => 'dy' . substr(md5($word), 0, 12),
                        'title' => $word,
                        'url' => 'https://www.douyin.com/search/' . rawurlencode($word),
                        'info' => isset($w['hot_value']) ? (string)$w['hot_value'] . ' 热度' : '',
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => '36kr', 'name' => '36氪热榜', 'color' => '#00b3ff', 'type' => 'hottest',
            'home' => 'https://36kr.com/hot-list/renqi', 'interval' => 1800,
            'fetch' => function (): array {
                $body = json_encode(['partner_id' => 'web', 'param' => ['siteId' => 1, 'platformId' => 2]]);
                $j = json_decode(news_http(
                    'https://gateway.36kr.com/api/mis/nav/home/nav/rank/hot',
                    10, ['Content-Type: application/json'], $body
                ), true);
                $out = [];
                foreach (($j['data']['hotRankList'] ?? []) as $it) {
                    $tm = $it['templateMaterial'] ?? [];
                    $title = trim((string)($tm['widgetTitle'] ?? ''));
                    $itemId = trim((string)($it['itemId'] ?? ''));
                    if ($title === '' || $itemId === '') continue;
                    $out[] = [
                        'id' => 'kr' . $itemId,
                        'title' => $title,
                        'url' => 'https://36kr.com/p/' . $itemId,
                        'info' => isset($tm['statPraise']) ? $tm['statPraise'] . ' 点赞' : '',
                    ];
                }
                return $out;
            },
        ],
        [
            'id' => 'csdn', 'name' => 'CSDN热榜', 'color' => '#fc5531', 'type' => 'hottest',
            'home' => 'https://blog.csdn.net/rank/list', 'interval' => 1800,
            'fetch' => function (): array {
                $j = json_decode(news_http(
                    'https://blog.csdn.net/phoenix/web/blog/hot-rank?page=0&pageSize=25',
                    10, ['Referer: https://blog.csdn.net/rank/list']
                ), true);
                $out = [];
                foreach (($j['data'] ?? []) as $it) {
                    $title = trim((string)($it['articleTitle'] ?? ''));
                    $url = trim((string)($it['articleDetailUrl'] ?? ''));
                    if ($title === '' || $url === '') continue;
                    $out[] = [
                        'id' => 'cs' . substr(md5($url), 0, 12),
                        'title' => $title,
                        'url' => $url,
                        'info' => isset($it['hotRankScore']) ? $it['hotRankScore'] . ' 热度' : '',
                    ];
                }
                return $out;
            },
        ],
    ];

    // 各平台 LOGO（使用平台官方 favicon，稳定可直连）
    $logos = [
        'zhihu'         => 'https://static.zhihu.com/heifetz/favicon.ico',
        'baidu'         => 'https://www.baidu.com/favicon.ico',
        'bilibili'      => 'https://www.bilibili.com/favicon.ico',
        'weibo'         => 'https://weibo.com/favicon.ico',
        'hackernews'    => 'https://news.ycombinator.com/favicon.ico',
        'github'        => 'https://github.githubassets.com/favicons/favicon.svg',
        'thepaper'      => 'https://www.thepaper.cn/favicon.ico',
        'toutiao'       => 'https://www.toutiao.com/favicon.ico',
        'tieba'         => 'https://tieba.baidu.com/favicon.ico',
        'douban'        => 'https://www.douban.com/favicon.ico',
        'hupu'          => 'https://bbs.hupu.com/favicon.ico',
        'juejin'        => 'https://juejin.cn/favicon.ico',
        'sspai'         => 'https://cdn.sspai.com/sspai/assets/img/favicon/favicon.ico',
        'nowcoder'      => 'https://www.nowcoder.com/favicon.ico',
        'tencent'       => 'https://news.qq.com/favicon.ico',
        'producthunt'   => 'https://www.producthunt.com/favicon.ico',
        'freebuf'       => 'https://www.freebuf.com/favicon.ico',
        'ithome'        => 'https://www.ithome.com/favicon.ico',
        'solidot'       => 'https://www.solidot.org/favicon.ico',
        'wallstreetcn'  => 'https://wallstreetcn.com/favicon.ico',
        'v2ex'          => 'https://www.v2ex.com/favicon.ico',
        'douyin'        => 'https://www.douyin.com/favicon.ico',
        '36kr'          => 'https://36kr.com/favicon.ico',
        'csdn'          => 'https://www.csdn.net/favicon.ico',
    ];
    foreach ($defs as &$d) {
        $d['logo'] = $logos[$d['id']] ?? '';
    }
    unset($d);

    return $defs;
}

/** 按 id 取单个数据源定义 */
function news_source_def(string $id): ?array
{
    foreach (news_source_defs() as $d) {
        if ($d['id'] === $id) return $d;
    }
    return null;
}

/**
 * RSS 2.0 订阅 → 统一条目
 * 依赖 news_http()；SimpleXML 不可用或解析失败返回空数组（由调用方决定抛错）
 */
function news_rss_items(string $url): array
{
    $xml = news_http($url, 10);
    if ($xml === '' || !function_exists('simplexml_load_string')) return [];
    $sx = @simplexml_load_string($xml);
    if (!$sx) return [];
    $items = [];
    if (isset($sx->channel) && $sx->channel->item) {
        $items = $sx->channel->item;
    } elseif (isset($sx->entry)) {
        // Atom 兜底
        $out = [];
        foreach ($sx->entry as $e) {
            $link = (string)($e->link['href'] ?? '');
            $title = trim((string)$e->title);
            if ($title === '' || $link === '') continue;
            $out[] = [
                'id' => 'rs' . substr(md5($link), 0, 12),
                'title' => $title,
                'url' => $link,
                'pub' => strtotime((string)($e->published ?? $e->updated ?? '')) ?: null,
            ];
        }
        return $out;
    }
    $out = [];
    foreach ($items as $it) {
        $title = trim((string)$it->title);
        $link = trim((string)$it->link);
        if ($title === '' || $link === '') continue;
        $out[] = [
            'id' => 'rs' . substr(md5($link), 0, 12),
            'title' => $title,
            'url' => $link,
            'pub' => strtotime((string)($it->pubDate ?? $it->date ?? '')) ?: null,
        ];
    }
    return $out;
}

/** HTML → DOMDocument（UTF-8 安全） */
function news_dom(string $html): DOMDocument
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    return $dom;
}
