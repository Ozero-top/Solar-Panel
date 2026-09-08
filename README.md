# SolarPanel

> 一款可自托管的个人导航 / 起始页面板。前端与后端完全分离，主页显示的**一切内容均由后台设置**——站点标题、Logo、壁纸、公告、时钟、天气、搜索引擎、分组与卡片，全部无需改动一行代码即可配置。

![版本](https://img.shields.io/badge/version-v1.0.003-blue)
![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777bb4)
![License](https://img.shields.io/badge/license-MIT-green)

本项目以 **PHP + MySQL** 版本为主，同时提供 **Docker 镜像版**（功能一致，开箱即用），可根据部署环境任选其一：

| 版本 | 技术栈 | 部署方式 | 适用场景 |
| --- | --- | --- | --- |
| **Web 版** | PHP + MySQL，原生 PDO，无框架 / Composer 依赖 | 宝塔 / 1Panel / 虚拟主机 / 自建 Web 服务器 | 已有 PHP+MySQL 环境的主机 |
| **Docker 版** | 单容器镜像，内置数据库 | `docker compose up -d` 一条命令 | 快速部署、NAS、服务器、个人电脑 |

- **风格**：**16 种风格主题**后台可切（Soft 柔和浮雕 / Nature 自然拟态 / Natural 自然大地 / Holo 全息渐变 / Gradient 渐变光晕 / Material 纸张层级 / Fabric 织物纹理 / Aurora 极光玻璃 / Scandi 斯堪的纳维亚 / Clay 黏土形态 / Spotlight 舞台聚光 / Neumorphism 新拟物 / Skeuomorphism 拟物设计 / Immersive Photo 沉浸摄影 / Ghibli 吉卜力 / Fluent 流利设计），每种均适配浅色 / 深色 / 跟随系统三态

---

## 目录

- [功能特性](#功能特性)
- [目录结构](#目录结构)
- [Docker 版部署](#docker-版部署)
- [Web 版部署（PHP + MySQL）](#web-版部署php--mysql)
- [后端 API 一览](#后端-api-一览)
- [备份与恢复](#备份与恢复)
- [系统升级](#系统升级)
- [安全说明](#安全说明)
- [常见问题](#常见问题)

## 功能特性

| 模块 | 说明 |
| --- | --- |
| 主页导航 | 壁纸（含模糊 / 遮罩调节）、Logo、站点标题、时钟、天气（纯文字随主题）、多引擎搜索框（下拉带站点 logo）、公告胶囊、页脚版本号；左侧分组目录条（滚动自动显示、停止后自动收起）、右下角常驻返回顶部按钮 |
| 视图切换 | 主页搜索框下方一键切换「🧭 导航站 / 📰 热点新闻」同页双视图，后台可选默认显示二者 / 仅导航 / 仅新闻 |
| 分组管理 | 多分组、名称与描述、后台拖拽排序、**前端显示 / 隐藏**（隐藏后仅登录管理员可见，访客与其他角色不可见） |
| 卡片管理 | 标题 / 链接 / 描述、图标（站点 favicon 服务端自动抓取并缓存、本地上传、文字色块）、内网地址、三种打开方式（当前页 / 新窗口 / iframe 弹层）、上下排序；**主页分组内拖拽排序**（管理员在主页直接拖动卡片，保存 / 取消有守卫确认） |
| 站点设置 | 二级导航 4 个分区独立保存：🧭 基础信息（含时钟与天气）/ 🎨 外观布局（含 Logo 与壁纸）/ 🔍 搜索引擎 / 📰 热点新闻；壁纸图库弹窗选用（内置系统预置壁纸，不可删除）、分目录上传；搜索引擎预置 13 个（百度 / Google / Bing / DuckDuckGo / Yandex / GitHub / 搜狗 / 360搜索 / 神马 / 夸克 / 头条搜索 / 中国搜索 / 抖音），支持增删 |
| 备份与更新 | 独立一级导航：配置与文件备份导出 / 导入、恢复初始状态、**在线升级**、更新日志（仅管理员） |
| 热点新闻 | 内置 24 个数据源（知乎 / 百度 / B站 / 微博 / HN / GitHub / 澎湃 / 今日头条 / 百度贴吧 / 豆瓣电影 / 虎扑 / 稀土掘金 / 少数派 / 牛客 / 腾讯新闻 / Product Hunt / Freebuf / IT之家 / Solidot / 华尔街见闻 / V2EX 等），卡片式热榜，前三名彩色徽章，支持单卡刷新、数据源勾选与自定义排序 |
| 天气 | 城市可后台指定，open-meteo 主源 + wttr.in 兜底定位，扩展天气代码映射 |
| 权限系统 | 三种角色：**管理员**（全部权限）/ **编辑者**（分组、卡片、上传；不能改设置与账号）/ **只读**（仅查看，不可修改显示名称）；最后一个管理员保护 |
| 账号安全 | 会话登录鉴权、Cookie HttpOnly + SameSite、登录失败计数防爆破、**CSRF 令牌校验**（全部写接口）、多账号管理（增删 / 改密 / 改角色） |
| 在线升级 | 后台上传升级包（.zip）一键完成版本升级：自动校验并预览变更清单，确认后原子替换生效，数据库数据不受影响；被替换的原文件自动备份，可手动回滚 |

## 目录结构

```
SolarPanel-go/
└── SolarPanel-web/             # PHP 版（PHP + MySQL）
    ├── index.html              # 主页
    ├── backend/
    │   ├── config.php          # 数据库配置（安装向导自动写入）
    │   ├── lib/                # 公共类库（db / auth / response / security / http / settings / sort / upgrade / news_sources）
    │   └── api/                # 13 个 API 端点
    ├── frontend/               # 前端（纯静态）
    ├── sql/init.sql            # 手动建表脚本（可选）
    └── tools/build_upgrade.php # 升级包生成工具
```

## Docker 版部署

单容器镜像，内置数据库，开箱即用，无需额外安装数据库服务。

### 1. 拉取镜像启动

```bash
docker run -d \
  --name solarpanel \
  --restart unless-stopped \
  -p 18080:18080 \
  -v solarpanel_data:/app/data \
  -e TZ=Asia/Shanghai \
  ovitor/solarpanel:latest
```

或使用 `docker-compose.yml`（项目 `SolarPanel-Docker/` 目录下已自带）：

```yaml
services:
  solarpanel:
    image: ovitor/solarpanel:latest      # 拉取 DockerHub 镜像；本地构建改为 build: .
    container_name: solarpanel
    restart: unless-stopped
    ports:
      - "18080:18080"                     # 左侧为宿主机端口，按需修改
    volumes:
      - ./data:/app/data                  # 数据库 + 上传文件持久化
    environment:
      - TZ=Asia/Shanghai
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://localhost:18080/api/install.php"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 10s
```

```bash
cd SolarPanel-Docker
docker compose up -d
```

> 如需从源码构建镜像，将 `image: ovitor/solarpanel:latest` 改为 `build: .`，然后 `docker compose up -d --build`。

### 2. 访问

- 主页：`http://服务器IP:18080/`
- 后台：`http://服务器IP:18080/admin.html`
- 默认管理员账号：`admin` / `admin123`

> ⚠️ **安全提示**：首次登录后**必须立即**在「账号管理」中修改默认密码。若服务器暴露在公网，建议同时修改默认端口并配置 HTTPS 反向代理。

### 3. 数据持久化

- 数据库（SQLite）与上传文件存放在 `/app/data`，通过 volume 挂载持久化
- `docker compose down` 不丢数据（加 `-v` 才会删除卷，慎用）

### 4. 从源码构建

```bash
cd SolarPanel-Docker
docker compose up -d --build
```

---

## Web 版部署（PHP + MySQL）

### 环境要求

- PHP **7.4 及以上**（推荐 8.x），必须启用 `pdo_mysql` 扩展
  - `curl` 推荐：站点信息 / 图标 / 热榜 / 天气出站抓取
  - `fileinfo` 推荐：上传文件 MIME 真实类型复核
- MySQL **5.7 及以上**（推荐 8.0）
- Nginx / Apache / OpenResty 任意 Web 服务器

### 通用部署流程（两步）

1. **上传代码**：把 `SolarPanel-web/` 目录内全部文件放到网站根目录（保证浏览器能访问到 `/index.html` 和 `/backend/api/install.php`）。
2. **安装向导**：浏览器访问 `http://你的域名/backend/api/install.php`，填写数据库信息与管理员账号，点击「开始安装」。

安装向导自动完成：测试连接 → 建库建表 → 写入默认数据 → 写回 `backend/config.php` → 删除安装文件。

登录入口：`http://你的域名/frontend/login.html`

### 宝塔面板部署

1. 安装 Nginx、MySQL 5.7/8.0、PHP 7.4+（启用 `pdo_mysql`、`curl`、`fileinfo`）
2. 创建数据库（字符集 utf8mb4）
3. 添加站点，上传 `SolarPanel-web/` 全部内容到根目录
4. 访问 `http://域名/backend/api/install.php` 完成安装
5. 设置 `frontend/uploads` 目录权限为 755，所有者 `www`

### 1Panel 部署

1. 安装 OpenResty + MySQL 5.7/8.0
2. 创建数据库（注意 1Panel 中数据库地址填 MySQL **容器名**）
3. 创建 PHP 运行环境（8.x，扩展勾选 `pdo_mysql`、`fileinfo`、`curl`）
4. 创建网站，上传代码
5. 访问安装向导完成部署

### Nginx 安全加固（推荐）

```nginx
# 禁止访问后端内部类库与 SQL 目录
location ^~ /backend/lib/ { deny all; }
location ^~ /sql/         { deny all; }
# 上传目录禁止执行脚本
location ~* ^/frontend/uploads/.*\.(php|phtml|pht|phps|phar|cgi|pl|py|jsp|asp|aspx)$ { deny all; }
```

---

## 后端 API 一览

API 路径与响应格式：

| 端点 | 主要 action | 鉴权 | 说明 |
| --- | --- | --- | --- |
| `install.php` | GET 状态 / POST 安装 | 无 | 安装向导（安装后自删除） |
| `public.php` | — | 无 | 主页全部公开数据（隐藏分组对非管理员过滤） |
| `auth.php` | `me` / `login` / `logout` | 部分需要 | 会话检测 / 登录 / 登出 |
| `groups.php` | `list` / `edit` / `delete` / `sort` / `sort_batch` / `visible` | 登录 | 分组管理与前端显隐 |
| `items.php` | `list` / `edit` / `delete` / `sort` / `sort_batch` / `favicon` / `fetch_meta` | 登录 | 卡片管理、站点信息与图标抓取 |
| `settings.php` | `list` / `save` | 登录（保存仅管理员） | 站点设置（26 项白名单 + 分区保存） |
| `user.php` | `me` / `list` / `create` / `update` / `change_password` / `delete` | 登录（账号管理仅管理员） | 账号与权限 |
| `upload.php` | — | 管理员 / 编辑者 | 图标 / Logo / 壁纸上传 |
| `gallery.php` | `list` / `delete` | 管理员 / 编辑者 | 壁纸图库 |
| `backup.php` | `export` / `import` / `reset` | 仅管理员 | 配置与文件备份 / 恢复 / 恢复初始状态 |
| `upgrade.php` | `check` / `apply` / `cancel` | 仅管理员 | 在线升级 |
| `weather.php` | `current` | 无 | 天气查询 |
| `news.php` | `meta` / `all` / `source` | 无 | 热点新闻 |

> **CSRF 说明**：所有写请求（POST）必须携带 `X-CSRF-Token` 请求头。令牌由服务端在会话建立时通过 `csrf_token` Cookie 下发，前端自动读取并携带。

## 备份与恢复

- **导出**：后台「💾 备份与更新 → ⬇️ 导出配置」，下载单个 `.json` 文件，包含全部分组、卡片、站点设置及所有上传文件（base64 内嵌）。
- **导入**：选择备份文件后两次确认；服务端先解压到临时目录、配置写入事务，全部成功后才切换生效（失败自动回滚）。导入会清空现有分组、卡片、设置与用户上传文件，**用户账号与系统预置壁纸不受影响**。
- **恢复初始状态**：一键清空所有自定义数据，写回默认设置 + 示例分组卡片，用户账号与预置壁纸保留。

## 系统升级

新版本发布后，下载升级包（.zip）在后台一键完成升级，数据库数据不受影响。

### 后台在线升级（推荐）

1. 登录管理员，进入「💾 备份与更新 → 🚀 在线升级」，上传升级包；
2. 系统自动校验升级包（版本链、路径合法性、完整性）并预览变更清单，点击「确认升级」；
3. 原文件自动备份到服务器上传目录，可手动回滚；
4. 升级完成后 `Ctrl+F5` 强制刷新浏览器缓存。

升级安全机制：`from` 版本必须与当前版本一致（跨版本逐版升级）；路径白名单校验（仅允许授权路径）；单文件 64MB 上限；升级会话 1 小时未应用自动清理。

### 手动覆盖（Web 版）

下载完整包解压覆盖站点根目录（**切勿覆盖 `backend/config.php`**），`Ctrl+F5` 强刷缓存。数据库结构有变更时系统自动迁移。

## 安全说明

- **SQL 注入**：PDO 预处理（`EMULATE_PREPARES = false`），排序字段走白名单。
- **CSRF**：同步令牌模式，所有写请求必须携带 `X-CSRF-Token`，登录后令牌轮换。
- **SSRF**：服务端抓取站点信息 / 图标前，DNS 解析后校验 IP，拦截回环、私网、云元数据、CGNAT 等地址；重定向逐跳校验。
- **出站 TLS**：服务端出站请求（图标抓取 / 站点信息 / 热榜 / 天气）默认启用 TLS 证书严格校验，证书异常的目标站点将被拦截。
- **存储型 XSS**：卡片地址强制 `http(s)` 协议白名单；上传 SVG 自动净化；新闻标题纯文本渲染。
- **文件上传**：扩展名白名单 + 真实内容复核（magic byte 检测）+ 随机文件名；上传目录禁止执行脚本。
- **权限控制**：三级角色（管理员 / 编辑者 / 只读），只读账号不可修改显示名称；最后一个管理员保护。

## 常见问题

**Q：默认管理员账号密码？**
Web 版安装向导中自行设置（至少 6 位）；Docker 版默认 `admin` / `admin123`，**首次登录后必须立即修改**，公网部署请务必更改默认端口并配置 HTTPS。

**Q：主页空白或数据加载失败？**
直接访问 `/backend/api/public.php` 查看，多数是未执行安装或数据库配置错误。

**Q：上传图片失败？**
检查上传目录权限（需可写）；壁纸 ≤ 10MB、图标 / Logo ≤ 5MB；格式 jpg / png / gif / webp / ico / svg；文件真实内容须与扩展名一致。

**Q：「自动获取信息 / 获取站点图标」失败？**
服务器需能访问目标站点；境内服务器无法直连 Google 等境外站点属预期。部分目标站点证书过期或自签时会被安全策略拦截，属正常防护行为。

**Q：天气城市不对？**
后台「🧭 基础信息」中填写城市名保存；天气服务有每日限额时自动切换备用数据源。

**Q：忘记管理员密码？**
通过数据库管理工具直接更新 `users` 表中的管理员密码字段即可。

**Q：在线升级提示版本不匹配？**
升级包只能从标注版本（`from`）升到目标版本（`to`）。确认当前版本后按顺序逐版升级；跨版本过多时下载完整包覆盖。

---

SolarPanel —— 柔软精致的个人导航面板，数据完全自持。
