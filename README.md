<div align=center>
<img src="https://updates.ozero.top/SolarPanel.ico" width="64" height="64"> 
</div>

<h1 align=center>SolarPanel - 柔软而精致的个人导航面板</h1>

[SolarPanel 演示站](https://test.ozero.top) ｜ [DockerHub 镜像](https://hub.docker.com/r/ovitor/solarpanel) ｜ [📱 SolarPanel安卓客户端](https://github.com/qiancheng817/Solarpanel)：部署在 NAS / VPS 上的 SolarPanel 面板，变手机上的独立 App

> 一款可自托管的个人导航 / 主站页面板。前端与后端完全分离，主页显示的**一切内容均由后台设置**——站点标题、Logo、壁纸、公告、时钟、天气、搜索引擎、分组与卡片，全部无需改动一行代码即可配置。

![版本](https://img.shields.io/badge/version-v2.1.07-blue)
![Go](https://img.shields.io/badge/go-1.26%2B-blue)
![Docker](https://img.shields.io/badge/docker-multi--arch-orange)
![License](https://img.shields.io/badge/license-MIT-green)

---

## 版本 2.1.07 · 2026-09-18

- 🎨 **主题优化**：Claymorphism、Natural Organic、Glassmorphism、Material、Scandinavian、Holographic 六套深度重绘 + Soft UI 基线校准
- 📝 **前端优化**：移除筛选卡片搜索框；增加分组导航栏模式(站点设置-卡片样式 选择 导航栏模式)，右键编辑卡片菜单，前端内外网一键切换

详见下方「系统升级」章节了解历史版本。

---

本项目同时提供 **Docker 镜像版** 与 **PHP 版**，功能一致，可根据部署环境任选其一：

| 版本 | 技术栈 | 数据库 | 部署方式 | 适用场景 |
| --- | --- | --- | --- | --- |
| **Docker 版**（推荐） | Go 1.26 + Gin + GORM | SQLite 内置 | `docker compose up -d` | 快速部署、NAS、VPS、个人电脑 |
| **PHP 版** | PHP 7.4+，原生 PDO，无框架 | MySQL 5.7+ | 宝塔 / 1Panel / 虚拟主机 | 已有 PHP+MySQL 环境的主机 |

多架构镜像：**linux/amd64 · linux/arm64 · linux/arm/v7**（x86 服务器 / ARM 开发板 / 树莓派全兼容）。

---

## 功能特性

### 核心功能

| 模块 | 说明 |
| --- | --- |
| 主页导航 | 壁纸（含模糊 / 遮罩调节）、Logo、站点标题、时钟、天气（纯文字随主题）、多引擎搜索框（下拉带站点 logo）、公告胶囊、页脚版本号；**顶栏向下滚动自动隐藏、到顶才恢复**；左侧分组目录条（滚动自动显示、停止后自动收起）、右下角常驻返回顶部按钮 |
| 视图切换 | 主页搜索框下方一键切换「🧭 导航站 / 📰 热点新闻」同页双视图，后台可选默认显示二者 / 仅导航 / 仅新闻 |
| 分组管理 | 多分组、名称与描述、后台拖拽排序、**前端显示 / 隐藏**（隐藏后仅登录管理员可见）；分组 header 排序按钮后新增「+」快速添加卡片按钮 |
| 卡片管理 | 标题 / 链接 / 描述、图标（站点 favicon 服务端自动抓取并缓存、本地上传、文字色块）、内网地址、三种打开方式（当前页 / 新窗口 / iframe 弹层）、上下排序；**主页分组内拖拽排序**（管理员在主页直接拖动卡片，保存 / 取消有守卫确认） |
| 快速添加 | 主页直接点「+」弹出快速添加卡片弹窗（与后台 itemModal 1:1 对齐）：三种图标类型 + 实时预览 + 颜色面板 + fetch_meta 自动获取站点信息与图标，无需进后台即可添加 |
| 站点设置 | 二级导航 4 个分区独立保存：🧭 基础信息（含时钟与天气）/ 🎨 外观布局（含 Logo 与壁纸、搜索栏显隐开关、卡片筛选显隐开关）/ 🔍 搜索引擎 / 📰 热点新闻；壁纸图库弹窗选用（内置系统预置壁纸，不可删除）、分目录上传；搜索引擎预置 13 个，支持增删 |
| 备份与更新 | 独立一级导航：配置与文件备份导出 / 导入、恢复初始状态、**PHP 版专属在线升级**（Docker 版通过拉取镜像升级）、更新日志（仅管理员） |

### 视觉：18 种风格主题

每种均适配浅色 / 深色 / 跟随系统三态：

| 主题 | 关键词 |
| --- | --- |
| Soft / Nature / Natural | 柔和浮雕 · 自然拟态 · 自然大地（Sage 绿 + warm cream + 轻阴影） |
| Holo / Gradient | 全息渐变 · 渐变光晕 |
| Material | 纸张层级（Material 3 紫 + 平面阴影） |
| Fabric / Aurora | 织物纹理 · **极光玻璃**（毛玻璃 backdrop-filter + 虹彩） |
| Scandi | 斯堪的纳维亚（极轻雾蓝阴影 + 大量留白） |
| **Clay** | **黏土形态**（对角双 inset + 玫红 + 32px 大圆角 + hover 下沉） |
| Spotlight / Neumorphism / Skeuomorphism | 舞台聚光 · 新拟物 · 拟物设计 |
| Immersive Photo / Ghibli / Fluent | 沉浸摄影 · 吉卜力 · 流利设计 |
| Warm Dashboard / **Blueprint** | 暖色仪表盘 · **工程蓝图**（网格背景 + 等宽字体 + 虚线辅助线） |

### 安全与权限

| 模块 | 说明 |
| --- | --- |
| 四种角色 | **管理员**（全部权限）/ **编辑者**（分组、卡片、上传；不能改设置与账号）/ **只读**（仅查看，后台控件灰色禁用）/ **访客**（需访客密码登录才能查看主页） |
| 访客密码锁 | 后台可开启，未登录访问主页时需输入密码；**连续 8 次失败自动临时锁定 15 分钟** |
| 两步验证 (TOTP) | 管理员可在账号管理卡片为自己/他人开启 6 位动态码 |
| CSRF 防护 | 同步令牌模式，所有写请求必须携带 `X-CSRF-Token` 请求头 |
| SQL 注入 | PHP 版 PDO 预处理（`EMULATE_PREPARES = false`）；Docker 版 GORM 参数化查询，排序字段走白名单 |
| SSRF 防护 | 服务端抓取站点信息 / 图标前，DNS 解析后校验 IP，拦截回环、私网、云元数据、CGNAT 等地址；重定向逐跳校验 |
| 暴力破解防护 | 登录失败计数 + 8 次临时锁定 + 审计日志自动记录 |
| 公开接口限流 | DB 持久化滑动窗口（重启不丢）：`public` 60/min、`news` 30/min、`weather` 10/min、`wallpaper` 10/min |
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
| 每日壁纸（Bing） | 后台一键开启，首页自动每日覆盖背景层；服务端按日缓存 + 当日命中零回源 |

### 搜索与快捷操作

| 模块 | 说明 |
| --- | --- |
| 卡片快速筛选 | 首页搜索框下实时按**标题 / URL / 描述**过滤，空分组自动隐藏，Esc 一键清除 |
| 浏览器书签导入 | 支持 Netscape 格式 HTML（Chrome / Edge / Firefox 导出格式），自动识别 H3 分组与 DT>A 卡片结构 |
| 最近常用 | 本地 frecency 排序（访问次数 DESC + 最近时间 DESC），上限 80 存 / 展示 12 |
| 键盘快捷键 | `/` 或 `Ctrl+K` 搜索、`f` 筛选、`t` 主题切换、`n`/`b`/`h` 视图切换、`g` 分组目录、`?` 帮助、`Esc` 逐级降级 |

### 在线升级（PHP 版）

- 进后台**自动静默检测新版本**（每小时最多一次；顶栏「🆕 可更新」徽标提示）
- 一键下载完整包（full）、校验 MD5 + manifest、预览变更清单后原子替换
- 升级失败**自动回滚**（替换前备份原文件），站点不受影响
- 数据库结构有变更时自动迁移
- 完整包机制：每个发布版本都是全量包（`from: v0.0.0`），**任意旧版本都能一步直升最新版**

---

## 目录结构

```
SolarPanel-go/
├── SolarPanel-Docker/           # Docker / Go 版（推荐，multi-arch）
│   ├── main.go                  # HTTP 路由 + HTTPS 双端口监听 + 自签名证书生成
│   ├── go.mod / go.sum          # Go 模块（Go 1.26 · Gin 1.12 · GORM 1.31 · x/crypto 0.57）
│   ├── internal/
│   │   ├── config/config.go     # 配置加载（SERVER_PORT / HTTPS_PORT / CERT_FILE / KEY_FILE）
│   │   ├── auth/                # 会话管理 / CSRF 校验 / 限流（DB 持久化）/ TOTP / 审计
│   │   ├── db/db.go             # SQLite 初始化 + 首次安装默认数据 + 版本迁移
│   │   ├── model/models.go      # GORM 数据模型
│   │   └── handler/             # 业务 handler（public / auth / groups / items / settings / backup ...）
│   ├── frontend/                # 前端静态资源（embed 内嵌，零运行时依赖）
│   ├── Dockerfile               # 多阶段：go:1.26-alpine builder → alpine:3.22 runtime
│   └── docker-compose.yml
│
└── SolarPanel-web/              # PHP 版（宝塔 / 1Panel）
    ├── index.html               # 主页（Nginx 入口）
    ├── manifest.json            # PWA 清单（同时含升级格式）
    ├── sw.js                    # Service Worker
    ├── backend/
    │   ├── config.php           # 数据库配置（安装向导自动写入）
    │   ├── lib/                 # 公共类库（db / auth / response / security / http / settings / sort / upgrade / news_sources / totp）
    │   └── api/                 # 18 个 API 端点（含在线升级 version.php / upgrade.php）
    ├── frontend/                # 前端（纯静态，两版共用同一套 HTML/CSS/JS）
    ├── sql/init.sql             # 手动建表脚本（可选）
    └── tools/build_upgrade.php  # 升级包生成工具（PHP ZipArchive）
```

---

## Docker 版部署（推荐）

单容器镜像，内置 SQLite 数据库，开箱即用，**支持内置 HTTPS + 自签名证书**（内网 PWA 安装必需）。

### 模式 A：内网直访（NAS / 家庭网络）

容器自动生成自签名 ECDSA 证书（SAN 包含本机所有内网 IP + localhost）。

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
# Nginx 反代配置
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

```yaml
volumes:
  - ./data:/app/data
  - /path/to/fullchain.pem:/app/data/cert.pem:ro
  - /path/to/privkey.pem:/app/data/key.pem:ro
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

- 主页：`http://服务器IP:18080/` 或 `https://服务器IP:18443/`
- 后台：`https://服务器IP:18443/admin.html`
- 默认管理员账号：`admin` / `admin123`

> 🚨 **必做：默认密码安全警告**
>
> 镜像内置默认密码 **`admin` / `admin123`**，任何人都知道。**首次登录后必须立刻**在「账号管理 → 修改密码」中改为强密码，否则面板可被任何人直接接管。
>
> 若服务暴露在公网，**务必同时**：
>
> 1. **不要用默认端口 18080**，映射为其他随机端口（如 `-p 49215:18080`）；
> 2. 前置 Nginx / Caddy 并启用 **HTTPS**；
> 3. 有条件的话限制来源 IP（防火墙 / 安全组只放行自己的 IP）；
> 4. 修改密码前不要开放公网访问。

### 数据持久化

SQLite 数据库与上传文件存放在 `/app/data`，通过 volume 挂载持久化。`docker compose down` 不丢数据（加 `-v` 才会删除卷，慎用）。

### 版本升级

```bash
docker compose pull && docker compose up -d
```

### 从源码构建

```bash
cd SolarPanel-Docker
docker buildx build --platform linux/amd64,linux/arm64,linux/arm/v7 \
  -t ovitor/solarpanel:local --push .
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
  - `zlib` 推荐：在线升级解压
- MySQL **5.7 及以上**（推荐 8.0）
- Nginx / Apache / OpenResty

### 通用部署流程

1. **上传代码**：把 `SolarPanel-web/` 目录内全部文件放到网站根目录。
2. **安装向导**：浏览器访问 `http://你的域名/backend/api/install.php`，填写数据库信息与管理员账号。

登录入口：`http://你的域名/frontend/login.html`

### 宝塔面板部署

1. 安装 Nginx、MySQL 5.7/8.0、PHP 7.4+（启用 `pdo_mysql`、`curl`、`fileinfo`、`zlib`）
2. 创建数据库（字符集 utf8mb4）
3. 添加站点，上传 `SolarPanel-web/` 全部内容到根目录
4. 访问 `http://域名/backend/api/install.php` 完成安装
5. 权限设置：站点目录所有者设为 `www`（递归），目录 755 / 文件 644

### Nginx 安全加固（推荐）

```nginx
client_max_body_size 512m;

# 禁止访问后端内部类库与 SQL 目录
location ^~ /backend/lib/ { deny all; }
location ^~ /sql/         { deny all; }
# 上传目录禁止执行脚本
location ~* ^/frontend/uploads/.*\.(php|phtml|pht|phps|phar|cgi|pl|py|jsp|asp|aspx)$ { deny all; }
```

---

## 后端 API 一览

PHP 版端点与 Docker 版路径对齐，Docker 版通过 Gin 路由 `/api/<endpoint>` 分发到对应 handler。

| 端点 | 主要 action | 鉴权 | 版本 | 说明 |
| --- | --- | --- | --- | --- |
| `install.php` | GET 状态 / POST 安装 | 无 | 两版 | 安装向导（安装后自删除） |
| `public.php` | — | 无 | 两版 | 主页全部公开数据（隐藏分组对非管理员过滤） |
| `auth.php` | `me` / `login` / `logout` | 部分需要 | 两版 | 会话检测 / 登录 / 登出 |
| `groups.php` | `list` / `edit` / `delete` / `sort` / `visible` | 登录 | 两版 | 分组管理与前端显隐 |
| `items.php` | `list` / `edit` / `delete` / `sort` / `favicon` / `fetch_meta` | 登录 | 两版 | 卡片管理、站点信息与图标抓取 |
| `settings.php` | `list` / `save` | 登录（保存仅管理员） | 两版 | 站点设置（26+ 项白名单） |
| `user.php` | `me` / `list` / `create` / `update` / `change_password` / `delete` / `set_role` | 登录（账号管理仅管理员） | 两版 | 账号与权限 / 2FA |
| `upload.php` | — | 管理员 / 编辑者 | 两版 | 图标 / Logo / 壁纸上传 |
| `gallery.php` | `list` / `delete` | 管理员 / 编辑者 | 两版 | 壁纸图库 |
| `backup.php` | `export` / `import` / `reset` | 仅管理员 | 两版 | 配置与文件备份 / 恢复 |
| `wallpaper.php` | — | 无 | 两版 | 每日壁纸（Bing 源）公开缓存接口 |
| `weather.php` | `current` | 无 | 两版 | 天气查询 |
| `news.php` | `meta` / `all` / `source` | 无 | 两版 | 热点新闻 / 自定义 RSS |
| `feeds.php` | `list` / `save` / `delete` / `toggle` | 登录（仅管理员） | 两版 | 自定义 RSS/Atom 源管理 |
| `audit.php` | `list` / `clear` | 登录（仅管理员） | 两版 | 审计日志 |
| `import.php` | — | 登录（管理员 / 编辑者） | 两版 | 浏览器书签导入 |
| `upgrade.php` | `check` / `apply` / `cancel` | 仅管理员 | **PHP 独有** | 手动升级包校验 / 应用 |
| `version.php` | `check` / `download` | 仅管理员 | **PHP 独有** | 自动检测新版本与下载 |

> **CSRF 说明**：所有写请求（POST）必须携带 `X-CSRF-Token` 请求头。令牌由服务端在会话建立时通过 `csrf_token` Cookie 下发，前端自动读取并携带。

---

## 备份与恢复

- **导出**：后台「💾 备份与更新 → ⬇️ 导出配置」，下载单个 `.json` 文件，包含全部分组、卡片、站点设置、审计日志、RSS 配置及所有上传文件（base64 内嵌）。
- **导入**：选择备份文件后两次确认；服务端先解压到临时目录、配置写入事务，全部成功后才切换生效（失败自动回滚）。
- **恢复初始状态**：一键清空所有自定义数据，写回默认设置 + 示例分组卡片。

---

## 系统升级

### Docker 版

```bash
docker compose pull && docker compose up -d
```

### Web 版：自动检测 + 一键升级（推荐）

1. 登录后台后系统**自动静默检测新版本**（进后台时检测，每小时最多一次）；
2. 发现新版本后，顶栏出现呼吸动效的「🆕 可更新」徽标；
3. 点「**一键下载并升级**」：系统自动从更新源下载**完整包（full）**、校验 MD5 与 manifest、原子替换；
4. 升级完成后自动刷新 OPcache 并重启后台页面，`Ctrl+F5` 强刷浏览器缓存即可。

完整包机制：每个发布版本都是全量包（`from: v0.0.0`），**任意旧版本都能一步直升最新版**。

升级安全机制：
- 替换前先**写权限预检**（真实探测目标目录，不可写时直接提示 PHP 运行用户与不可写目录清单）
- 替换的原文件先备份，**任何一个文件失败即自动回滚**到原版本

---

## 安全架构

| 层 | 防护措施 |
| --- | --- |
| SQL 注入 | PHP 版 PDO 预处理（EMULATE_PREPARES = false）；Docker 版 GORM 参数化查询，排序字段白名单 |
| CSRF | 同步令牌模式，登录后令牌轮换，写请求强制校验 |
| SSRF | DNS 解析 + IP 校验（拦截回环、私网、云元数据、CGNAT），重定向逐跳校验 |
| 存储型 XSS | 卡片地址协议白名单（http/https）；SVG 上传自动净化；新闻标题纯文本渲染 |
| 文件上传 | 扩展名白名单 + magic byte 真实内容复核 + 随机文件名；上传目录禁执行脚本 |
| 暴力破解 | 登录失败计数 + 8 次临时锁定 15 分钟 |
| 限流 | DB 持久化滑动窗口（重启不丢）：public 60/min、news 30/min、weather 10/min、wallpaper 10/min |
| 权限控制 | 四级角色 + 最后一个管理员保护；只读账号控件灰色禁用 |
| 审计 | 登录 / 登出 / 2FA / 设置保存 / 书签导入 / RSS 变更自动记录 |
| 容器镜像 | Go 1.26-alpine builder → alpine:3.22 runtime，多阶段最小化攻击面；镜像漏洞 **165 → 1** |

---

## 常见问题

**Q：默认管理员账号密码？**
- Docker 版：默认 `admin` / `admin123`——这是公开镜像里人人皆知的密码，首次登录后必须立刻修改。公网部署还必须改用非默认端口、配置 HTTPS、建议限制来源 IP。
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

**Q：天气城市不对？**
后台「🧭 基础信息」中填写城市名保存；天气服务有每日限额时自动切换备用数据源。

**Q：忘记管理员密码？**
- Docker 版：进容器 SQLite 或直接删 `/app/data/solarpanel.db`（会清空全部数据）
- PHP 版：通过数据库管理工具直接更新 `users` 表中的管理员密码字段

**Q：访客密码锁和 2FA 冲突？**
访客密码锁是独立的「查看主页」密码保护；2FA 是管理员登录后台的附加校验。开启访客密码锁后，未登录状态下主页只显示密码输入框。

**Q：Docker 版有内置 HTTPS，还能用宝塔 Nginx 反代吗？**
完全可以。反代场景下**不要设置 `HTTPS_PORT`**，容器只跑 HTTP 18080，Nginx 在容器外面做 HTTPS 终止。

---

## 技术栈

### Docker / Go 版

| 组件 | 版本 |
| --- | --- |
| Go | **1.26** |
| Gin | 1.12.0 |
| GORM | 1.31.2 |
| SQLite | glebarez/sqlite (纯 Go, CGO_ENABLED=0) |
| x/crypto | 0.57.0 |
| x/net | 0.59.0 |
| Runtime | alpine:3.22（~16MB 压缩） |
| 架构 | linux/amd64 · linux/arm64 · linux/arm/v7 |
| 前端 | HTML/CSS/JS 原生（无构建工具链，embed 内嵌） |

### PHP 版

| 组件 | 版本 |
| --- | --- |
| PHP | 7.4+（推荐 8.x） |
| 数据库 | MySQL 5.7+（推荐 8.0） |
| 后端 | 原生 PDO，无框架 |
| 前端 | 同 Docker 版（两版共用） |

---

## 致谢

- **[qiancheng817](https://github.com/qiancheng817)** — 制作 Solarpanel 安卓客户端（WebView 外壳）

---

SolarPanel —— 柔软而精致的个人导航面板，数据完全自持。

