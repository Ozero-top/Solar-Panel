# SolarPanel

[SolarPanel演示站](https://test.ozero.top/)

> 一款可自托管的个人导航 / 起始页面板。前端与后端完全分离，主页显示的**一切内容均由后台设置**——站点标题、Logo、壁纸、公告、时钟、天气、搜索引擎、分组与卡片，全部无需改动一行代码即可配置。

![版本](https://img.shields.io/badge/version-v2.0.11-blue)
![后端](https://img.shields.io/badge/go-1.22%2B-blue)
![License](https://img.shields.io/badge/license-MIT-green)

本项目同时提供 **Docker 镜像版** 与 **PHP 版**，功能一致，可根据部署环境任选其一：

| 版本 | 技术栈 | 数据库 | 部署方式 | 适用场景 |
| --- | --- | --- | --- | --- |
| **Docker 版**（推荐） | Go + Gin + GORM | SQLite 内置 | `docker compose up -d` | 快速部署、NAS、VPS、个人电脑 |
| **PHP 版** | PHP 7.4+，原生 PDO，无框架 | MySQL 5.7+ | 宝塔 / 1Panel / 虚拟主机 | 已有 PHP+MySQL 环境的主机 |

---

## 功能特性

### 核心功能

| 模块 | 说明 |
| --- | --- |
| 主页导航 | 壁纸（含模糊 / 遮罩调节）、Logo、站点标题、时钟、天气（纯文字随主题）、多引擎搜索框（下拉带站点 logo）、公告胶囊、页脚版本号；左侧分组目录条（滚动自动显示、停止后自动收起）、右下角常驻返回顶部按钮 |
| 视图切换 | 主页搜索框下方一键切换「🧭 导航站 / 📰 热点新闻」同页双视图，后台可选默认显示二者 / 仅导航 / 仅新闻 |
| 分组管理 | 多分组、名称与描述、后台拖拽排序、**前端显示 / 隐藏**（隐藏后仅登录管理员可见，访客与其他角色不可见） |
| 卡片管理 | 标题 / 链接 / 描述、图标（站点 favicon 服务端自动抓取并缓存、本地上传、文字色块）、内网地址、三种打开方式（当前页 / 新窗口 / iframe 弹层）、上下排序；**主页分组内拖拽排序**（管理员在主页直接拖动卡片，保存 / 取消有守卫确认） |
| 站点设置 | 二级导航 4 个分区独立保存：🧭 基础信息（含时钟与天气）/ 🎨 外观布局（含 Logo 与壁纸）/ 🔍 搜索引擎 / 📰 热点新闻；壁纸图库弹窗选用（内置系统预置壁纸，不可删除）、分目录上传；搜索引擎预置 13 个（百度 / Google / Bing / DuckDuckGo / Yandex / GitHub / 搜狗 / 360搜索 / 神马 / 夸克 / 头条搜索 / 中国搜索 / 抖音），支持增删 |
| 备份与更新 | 独立一级导航：配置与文件备份导出 / 导入、恢复初始状态、**在线升级**、更新日志（仅管理员） |

### 安全与权限

| 模块 | 说明 |
| --- | --- |
| 三种角色 | **管理员**（全部权限）/ **编辑者**（分组、卡片、上传；不能改设置与账号）/ **只读 / 访客**（仅查看，所有后台控件灰色禁用，仅历史更新按钮可点击） |
| 访客密码锁 | 后台可开启，未登录访问主页时需输入密码；**连续 8 次失败自动临时锁定 15 分钟** |
| 两步验证 (TOTP) | 管理员可在账号管理卡片为自己/他人开启 6 位动态码（Google Authenticator / 微软 Authenticator 等兼容），登录时追加校验 |
| 账号安全 | 会话登录鉴权、Cookie HttpOnly + SameSite、登录失败计数防爆破、**CSRF 令牌校验**（全部写接口）、多账号管理（增删 / 改密 / 改角色）、最后一个管理员保护 |
| 审计日志 | 自动记录登录 / 登出 / 2FA 启用关闭 / 设置保存 / 书签导入 / RSS 变更等敏感操作；后台可分页查看 + 一键清空 |
| 公开接口限流 | DB 持久化滑动窗口（重启不丢）：`public` 60/min、`news` 30/min、`weather` 10/min、`wallpaper` 10/min |
| SSRF 防护 | 服务端抓取站点信息 / 图标前，DNS 解析后校验 IP，拦截回环、私网、云元数据、CGNAT 等地址；重定向逐跳校验 |
| 文件上传 | 扩展名白名单 + 真实内容复核（magic byte 检测）+ 随机文件名；上传目录禁止执行脚本 |

### 新闻与天气

| 模块 | 说明 |
| --- | --- |
| 热点新闻 | 内置 24 个数据源（知乎 / 百度 / B站 / 微博 / HN / GitHub / 澎湃 / 今日头条 / 百度贴吧 / 豆瓣电影 / 虎扑 / 稀土掘金 / 少数派 / 牛客 / 腾讯新闻 / Product Hunt / Freebuf / IT之家 / Solidot / 华尔街见闻 / V2EX 等），卡片式热榜，前三名彩色徽章，支持单卡刷新、数据源勾选与自定义排序 |
| 自定义 RSS/Atom | 后台 CRUD + 启用/禁用开关，自动追加到新闻列表并支持 `custom_N` 单独抓取 |
| 天气 | 城市可后台指定，open-meteo 主源 + wttr.in 兜底定位，扩展天气代码映射 |

### PWA 与离线

| 模块 | 说明 |
| --- | --- |
| Service Worker | 网络优先（API / HTML）+ Stale-While-Revalidate（静态资源）+ LRU 缓存上限；离线自动兜底到 `offline.html` |
| PWA manifest | 完整 PWA 清单：3 种尺寸图标、管理后台/热点新闻快捷方式、浅色/深色主题色、standalone 独立窗口运行 |
| 每日壁纸（Bing） | 后台一键开启，首页自动每日覆盖背景层；服务端按日缓存 + 当日命中零回源；后台可手动切换或关闭 |

### 搜索与快捷操作

| 模块 | 说明 |
| --- | --- |
| 卡片快速筛选 | 首页搜索框下实时按**标题 / URL / 描述**过滤，空分组自动隐藏，Esc 一键清除 |
| 浏览器书签导入 | 支持 Netscape 格式 HTML（Chrome / Edge / Firefox 导出格式），自动识别 H3 分组与 DT>A 卡片结构，导入后进入待审核状态 |
| 最近常用 | 本地 frecency 排序（访问次数 DESC + 最近时间 DESC），上限 80 存 / 展示 12 |
| 键盘快捷键 | `/` 或 `Ctrl+K` 搜索、`f` 筛选、`t` 主题切换、`n`/`b`/`h` 视图切换、`g` 分组目录、`?` 帮助、`Esc` 逐级降级 |

### 视觉

**17 种风格主题**后台可切（Soft 柔和浮雕 / Nature 自然拟态 / Natural 自然大地 / Holo 全息渐变 / Gradient 渐变光晕 / Material 纸张层级 / Fabric 织物纹理 / Aurora 极光玻璃 / Scandi 斯堪的纳维亚 / Clay 黏土形态 / Spotlight 舞台聚光 / Neumorphism 新拟物 / Skeuomorphism 拟物设计 / Immersive Photo 沉浸摄影 / Ghibli 吉卜力 / Fluent 流利设计 / Warm Dashboard 暖色仪表盘），每种均适配浅色 / 深色 / 跟随系统三态。

### 在线升级

- 进后台**自动静默检测新版本**（每小时最多一次；顶栏「🆕 可更新」徽标提示）
- 一键下载完整包（full）、校验 MD5 + manifest、预览变更清单后原子替换
- 升级失败**自动回滚**（替换前备份原文件），站点不受影响
- 数据库结构有变更时自动迁移
- 同时保留手动上传升级包 / 导入配置文件 / 恢复初始状态作为兜底

---

## 目录结构

```
SolarPanel-go/
├── SolarPanel-Docker/           # Docker / Go 版（主入口，推荐）
│   ├── main.go                  # HTTP 路由 + HTTPS 双端口监听 + 自签名证书生成
│   ├── config.go                # 配置加载（SERVER_PORT / HTTPS_PORT / CERT_FILE / KEY_FILE）
│   ├── handler/                 # 20+ API handler（auth / groups / items / settings / backup / version / weather / news / wallpaper ...）
│   ├── middleware/              # 鉴权 / CSRF / 限流（DB 持久化滑动窗口）
│   ├── models/                  # GORM 数据模型
│   ├── frontend/                # 前端静态资源（embed 内嵌）
│   ├── Dockerfile               # 单阶段构建，46MB 多架构镜像（amd64 / arm64 / arm/v7）
│   └── docker-compose.yml
│
└── SolarPanel-web/              # PHP 版（宝塔 / 1Panel）
    ├── index.html               # 主页
    ├── manifest.json            # PWA 清单（同时含升级格式）
    ├── sw.js                    # Service Worker
    ├── backend/
    │   ├── config.php           # 数据库配置（安装向导自动写入）
    │   ├── lib/                 # 公共类库（db / auth / response / security / http / settings / sort / upgrade / news_sources）
    │   └── api/                 # 14 个 API 端点（含 version.php 在线更新检测 / 下载）
    ├── frontend/                # 前端（纯静态）
    ├── sql/init.sql             # 手动建表脚本（可选）
    └── tools/build_upgrade.php  # 升级包生成工具
```

---

## Docker 版部署（推荐）

单容器镜像，内置 SQLite 数据库，开箱即用，**支持内置 HTTPS + 自签名证书**（内网 PWA 安装必需）。

### 模式 A：内网直访（NAS / 家庭网络）

容器自动生成自签名 ECDSA 证书（SAN 包含本机所有内网 IP + localhost），浏览器访问 `https://NAS-IP:18443/` 即可。

```yaml
services:
  solarpanel:
    image: ovitor/solarpanel:latest
    container_name: solarpanel
    restart: unless-stopped
    ports:
      - "18080:18080"
      - "18443:18443"
    volumes:
      - ./data:/app/data
    environment:
      - TZ=Asia/Shanghai
      - HTTPS_PORT=18443
```

```bash
docker compose up -d
# 访问 http://NAS-IP:18080 或 https://NAS-IP:18443
```

### 模式 B：反代（宝塔 / VPS / Nginx / Caddy）

反代层做 HTTPS 终止，容器只跑内部 HTTP，**不要设置 HTTPS_PORT**。

```yaml
services:
  solarpanel:
    image: ovitor/solarpanel:latest
    container_name: solarpanel
    restart: unless-stopped
    ports:
      - "18080:18080"
    volumes:
      - ./data:/app/data
    environment:
      - TZ=Asia/Shanghai
```

```nginx
# 宝塔 / Nginx 反代配置
server {
    listen 443 ssl http2;
    server_name panel.example.com;
    ssl_certificate     /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;
    client_max_body_size 512m;

    location / {
        proxy_pass http://127.0.0.1:18080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### 模式 C：自定义证书

挂载自己的证书到容器内，自签名证书会被跳过：

```yaml
volumes:
  - ./data:/app/data
  - /path/to/fullchain.pem:/app/data/cert.pem:ro
  - /path/to/privkey.pem:/app/data/key.pem:ro
```

或用环境变量指定路径：

```yaml
environment:
  - HTTPS_PORT=18443
  - CERT_FILE=/app/data/cert.pem
  - KEY_FILE=/app/data/key.pem
```

### docker run 一行版

```bash
# 内网 HTTPS（含自签名证书自动生成）
docker run -d --name solarpanel --restart unless-stopped \
  -p 18080:18080 -p 18443:18443 \
  -e HTTPS_PORT=18443 \
  -v $(pwd)/data:/app/data \
  ovitor/solarpanel:latest

# 纯反代用（容器内不开 HTTPS）
docker run -d --name solarpanel --restart unless-stopped \
  -p 18080:18080 \
  -v $(pwd)/data:/app/data \
  ovitor/solarpanel:latest
```

### 部署后

- 主页：`http://服务器IP:18080/` 或 `https://服务器IP:18443/`（HTTPS 需先开 `HTTPS_PORT`）
- 后台：`https://服务器IP:18443/admin.html`
- 默认管理员账号：`admin` / `admin123`

> 🚨 **【必做】默认密码安全警告**
>
> 镜像内置默认密码 **`admin` / `admin123`**，任何人都知道。**首次登录后必须立刻**在「账号管理 → 修改密码」中改为强密码，否则面板可被任何人直接接管。
>
> 若服务暴露在公网，**务必同时**做到：
>
> 1. **不要用默认端口 18080**，映射为其他随机端口（如 `-p 49215:18080`）；
> 2. 前置 Nginx / Caddy 并启用 **HTTPS**；
> 3. 有条件的话限制来源 IP（防火墙 / 安全组只放行自己的 IP）；
> 4. 修改密码前不要开放公网访问。
>
> 未改默认密码导致的面板被入侵、数据被删改，属于部署配置问题。

### 数据持久化

- SQLite 数据库与上传文件存放在 `/app/data`，通过 volume 挂载持久化
- `docker compose down` 不丢数据（加 `-v` 才会删除卷，慎用）

### 版本升级

Docker 版通过拉取新镜像升级（数据在挂载卷中，不受影响）：

```bash
docker compose pull && docker compose up -d
```

或在后台「💾 备份与更新 → 🔍 检查更新」一键升级（需 v2.0.05+）。

### 从源码构建

```bash
cd SolarPanel-Docker
docker compose up -d --build
```

### 支持的环境变量

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `TZ` | — | 时区，如 `Asia/Shanghai` |
| `SERVER_PORT` | `18080` | HTTP 监听端口 |
| `HTTPS_PORT` | 空（不开） | 设了就自动开启 HTTPS；无证书时自动生成自签名 ECDSA P256 |
| `CERT_FILE` | `/app/data/cert.pem` | 自定义证书路径 |
| `KEY_FILE` | `/app/data/key.pem` | 自定义私钥路径 |

---

## Web 版部署（PHP + MySQL）

### 环境要求

- PHP **7.4 及以上**（推荐 8.x），必须启用 `pdo_mysql` 扩展
  - `curl` 推荐：站点信息 / 图标 / 热榜 / 天气出站抓取
  - `fileinfo` 推荐：上传文件 MIME 真实类型复核
  - `zlib` 推荐：在线升级解压升级包（一键下载升级需要）
- MySQL **5.7 及以上**（推荐 8.0）
- Nginx / Apache / OpenResty 任意 Web 服务器

### 通用部署流程（两步）

1. **上传代码**：把 `SolarPanel-web/` 目录内全部文件放到网站根目录（保证浏览器能访问到 `/index.html` 和 `/backend/api/install.php`）。
2. **安装向导**：浏览器访问 `http://你的域名/backend/api/install.php`，填写数据库信息与管理员账号，点击「开始安装」。

安装向导自动完成：测试连接 → 建库建表 → 写入默认数据 → 写回 `backend/config.php` → 删除安装文件。

登录入口：`http://你的域名/frontend/login.html`

### 宝塔面板部署

1. 安装 Nginx、MySQL 5.7/8.0、PHP 7.4+（启用 `pdo_mysql`、`curl`、`fileinfo`、`zlib`）
2. 创建数据库（字符集 utf8mb4）
3. 添加站点，上传 `SolarPanel-web/` 全部内容到根目录
4. 访问 `http://域名/backend/api/install.php` 完成安装
5. 权限设置：站点目录所有者设为 `www`（**递归应用到子目录**），目录 755 / 文件 644——`frontend/uploads` 用于上传，整站可写是在线一键升级的前提（升级失败时页面会列出不可写的具体目录）

### Nginx 安全加固（推荐）

```nginx
# 请求体大小上限：Nginx 默认仅 1MB，导入配置 / 上传壁纸 / 升级包都会超限（413）
client_max_body_size 512m;

# 禁止访问后端内部类库与 SQL 目录
location ^~ /backend/lib/ { deny all; }
location ^~ /sql/         { deny all; }
# 上传目录禁止执行脚本
location ~* ^/frontend/uploads/.*\.(php|phtml|pht|phps|phar|cgi|pl|py|jsp|asp|aspx)$ { deny all; }
```

> 宝塔面板：站点设置 → 配置文件，在 `server { }` 块内加入 `client_max_body_size 512m;` 后保存（自动重载 Nginx）。同时确认 PHP 的 `post_max_size` / `upload_max_filesize` 不小于备份体积（软件商店 → PHP 设置 → 配置修改）。

### 1Panel 部署

1. 安装 OpenResty + MySQL 5.7/8.0
2. 创建数据库（注意 1Panel 中数据库地址填 MySQL **容器名**）
3. 创建 PHP 运行环境（8.x，扩展勾选 `pdo_mysql`、`fileinfo`、`curl`）
4. 创建网站，上传代码
5. 访问安装向导完成部署

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
| `user.php` | `me` / `list` / `create` / `update` / `change_password` / `delete` | 登录（账号管理仅管理员） | 账号与权限 / 2FA 绑定 |
| `upload.php` | — | 管理员 / 编辑者 | 图标 / Logo / 壁纸上传 |
| `gallery.php` | `list` / `delete` | 管理员 / 编辑者 | 壁纸图库 |
| `backup.php` | `export` / `import` / `reset` | 仅管理员 | 配置与文件备份 / 恢复 / 恢复初始状态 |
| `upgrade.php` | `check` / `apply` / `cancel` | 仅管理员 | 手动上传升级包的校验 / 应用 / 取消 |
| `version.php` | `check` / `download` | 仅管理员 | 新版本自动检测与升级包下载（一键升级） |
| `weather.php` | `current` | 无 | 天气查询 |
| `news.php` | `meta` / `all` / `source` | 无 | 热点新闻 / 自定义 RSS |

> **CSRF 说明**：所有写请求（POST）必须携带 `X-CSRF-Token` 请求头。令牌由服务端在会话建立时通过 `csrf_token` Cookie 下发，前端自动读取并携带。

---

## 备份与恢复

- **导出**：后台「💾 备份与更新 → ⬇️ 导出配置」，下载单个 `.json` 文件，包含全部分组、卡片、站点设置、审计日志、RSS 配置及所有上传文件（base64 内嵌）。
- **导入**：选择备份文件后两次确认；服务端先解压到临时目录、配置写入事务，全部成功后才切换生效（失败自动回滚）。导入会清空现有分组、卡片、设置与用户上传文件，**用户账号与系统预置壁纸不受影响**。
- **恢复初始状态**：一键清空所有自定义数据，写回默认设置 + 示例分组卡片，用户账号与预置壁纸保留。

---

## 系统升级

### Docker 版

拉取新镜像后重建容器即可，数据在挂载卷中不受影响：

```bash
docker compose pull && docker compose up -d
```

或在后台「💾 备份与更新 → 🔍 检查更新」一键升级（v2.0.05+）。

### Web 版：自动检测 + 一键升级（推荐）

1. 登录后台后系统**自动静默检测新版本**（进后台时检测，每小时最多一次；也可在「💾 备份与更新」点「🔍 检查更新」立即检测）；
2. 发现新版本后，顶栏出现呼吸动效的「🆕 可更新」徽标，备份与更新页展示版本号、包大小与更新日志；
3. 点「**一键下载并升级**」：系统自动从更新源下载**完整包（full）**、校验 MD5 与 manifest、预览变更清单后原子替换；
4. 升级完成后自动刷新 OPcache 并重启后台页面，`Ctrl+F5` 强刷浏览器缓存即可。

完整包机制：每个发布版本都是全量包（`from: v0.0.0`），**任意旧版本都能一步直升最新版**，无需逐版升级。数据库数据与 `backend/config.php` 不受影响，数据库结构有变更时自动迁移。

升级安全机制：

- 替换前先**写权限预检**（真实探测目标目录，不可写时直接提示 PHP 运行用户与不可写目录清单）
- 路径白名单校验：仅允许授权路径，硬拒 `backend/config.php`、`backend/api/install.php`，并自动跳过面板环境文件（如宝塔 `.user.ini`）
- 替换的原文件先备份，**任何一个文件失败即自动回滚**到原版本，站点不受影响
- 升级成功自动 `opcache_reset()`，避免 OPcache 缓存旧代码
- 服务器异常会返回具体错误信息（文件 + 行号 / 失败文件清单），不再出现空白 500
- 单文件 64MB 上限；升级会话 1 小时未应用自动清理

> 前置条件：站点目录对 PHP 运行用户可写（宝塔：属主 `www` 并递归应用，目录 755 / 文件 644）；PHP 启用 `zlib` 扩展。

### Web 版：手动覆盖

下载完整包解压覆盖站点根目录（**切勿覆盖 `backend/config.php`**），重启 PHP（清 OPcache）后 `Ctrl+F5` 强刷缓存。

---

## 安全说明

- **SQL 注入**：PHP 版走 PDO 预处理（`EMULATE_PREPARES = false`）；Docker 版走 GORM 参数化查询，排序字段走白名单。
- **CSRF**：同步令牌模式，所有写请求必须携带 `X-CSRF-Token`，登录后令牌轮换。
- **SSRF**：服务端抓取站点信息 / 图标前，DNS 解析后校验 IP，拦截回环、私网、云元数据、CGNAT 等地址；重定向逐跳校验。
- **出站 TLS**：服务端出站请求（图标抓取 / 站点信息 / 热榜 / 天气）默认启用 TLS 证书严格校验，证书异常的目标站点将被拦截。
- **存储型 XSS**：卡片地址强制 `http(s)` 协议白名单；上传 SVG 自动净化；新闻标题纯文本渲染。
- **文件上传**：扩展名白名单 + 真实内容复核（magic byte 检测）+ 随机文件名；上传目录禁止执行脚本。
- **权限控制**：三级角色（管理员 / 编辑者 / 只读/访客），只读账号不可修改显示名称且后台控件灰色禁用；最后一个管理员保护。
- **限流**：Docker 版 DB 持久化滑动窗口（重启不丢）；PHP 版可通过宝塔 / Nginx 层实现。
- **暴力破解防护**：登录失败计数 + 8 次临时锁定 + 审计日志自动记录。

---

## 常见问题

**Q：默认管理员账号密码？**
- Docker 版：默认 `admin` / `admin123`——这是公开镜像里人人皆知的密码，首次登录后必须立刻修改。公网部署还必须改用非默认端口、配置 HTTPS、建议限制来源 IP，且改密码前不要开放公网访问。
- PHP 版：安装向导中自行设置（至少 6 位）。

**Q：主页空白或数据加载失败？**
直接访问 `/backend/api/public.php`（PHP 版）或 `/api/public`（Docker 版）查看，多数是未执行安装或数据库配置错误。

**Q：PWA 安装按钮点了没反应？**
Service Worker **强制要求 HTTPS 或 localhost**。内网 IP + HTTP 会被 Chrome 直接拒绝。
- Docker 版（内网直访）：docker-compose 里加 `HTTPS_PORT=18443` + `-p 18443:18443`，浏览器访问 `https://NAS-IP:18443`（首次自签名证书会警告，点「高级 → 继续」即可）。
- Docker 版（反代）：Nginx/Caddy 做 HTTPS 终止后，浏览器访问 `https://你的域名`。
- PHP 版：宝塔面板申请 Let's Encrypt 免费证书 → Nginx 站点启用 SSL。

**Q：上传图片失败？**
检查上传目录权限（需可写）；壁纸 ≤ 10MB、图标 / Logo ≤ 5MB；格式 jpg / png / gif / webp / ico / svg；文件真实内容须与扩展名一致。

**Q：「自动获取信息 / 获取站点图标」失败？**
服务器需能访问目标站点；境内服务器无法直连 Google 等境外站点属预期。部分目标站点证书过期或自签时会被安全策略拦截，属正常防护行为。

**Q：天气城市不对？**
后台「🧭 基础信息」中填写城市名保存；天气服务有每日限额时自动切换备用数据源。

**Q：忘记管理员密码？**
- Docker 版：进容器 SQLite 或 直接删 `/app/data/solarpanel.db`（会清空全部数据）
- PHP 版：通过数据库管理工具直接更新 `users` 表中的管理员密码字段

**Q：在线升级提示失败 / 版本不匹配？**
v2.0 起发布的都是**完整包**，任意旧版本可一步直升，无需逐版升级。若一键升级失败，页面会直接显示原因（不可写目录、失败文件清单或具体错误行）：权限问题请将站点目录属主递归设为 PHP 运行用户（宝塔为 `www`，目录 755 / 文件 644）；也可改用「手动上传升级包」或下载完整包解压覆盖（勿覆盖 `backend/config.php`）。

**Q：访客密码锁和 2FA 冲突？**
访客密码锁是独立的「查看主页」密码保护；2FA 是管理员登录后台的附加校验。开启访客密码锁后，未登录状态下主页只显示密码输入框（搜索框 / 分组 / 卡片均隐藏），登录后恢复正常。

**Q：Docker 版有内置 HTTPS，还能用宝塔 Nginx 反代吗？**
完全可以。反代场景下**不要设置 `HTTPS_PORT`**，容器只跑 HTTP 18080，Nginx 在容器外面做 HTTPS 终止。两种 HTTPS 方案不冲突：
- 容器内 HTTPS：适合 NAS 内网直访（省去反代层）
- Nginx 反代 HTTPS：适合公网域名（证书更正规，自动续期）

---

SolarPanel —— 柔软而精致的个人导航面板，数据完全自持。
