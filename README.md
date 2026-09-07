# SolarPanel

> 一款可自托管的个人导航 / 起始页面板。前端与后端完全分离，主页显示的**一切内容均由后台设置**——站点标题、Logo、壁纸、公告、时钟、天气、搜索引擎、分组与卡片，全部无需改动一行代码即可配置。

![版本](https://img.shields.io/badge/version-v1.0.001-blue)
![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777bb4)
![MySQL](https://img.shields.io/badge/MySQL-%E2%89%A5%205.7-4479a1)
![License](https://img.shields.io/badge/license-MIT-green)

- **后端**：PHP（≥ 7.4，推荐 8.x）+ MySQL 5.7+，原生 PDO，**无任何框架 / Composer 依赖**
- **前端**：纯静态 HTML / CSS / JS，单请求渲染
- **风格**：**16 种风格主题**后台可切（Soft 柔和浮雕 / Nature 自然拟态 / Natural 自然大地 / Holo 全息渐变 / Gradient 渐变光晕 / Material 纸张层级 / Fabric 织物纹理 / Aurora 极光玻璃 / Scandi 斯堪的纳维亚 / Clay 黏土形态 / Spotlight 舞台聚光 / Neumorphism 新拟物派 / Skeuomorphism 拟物设计 / Immersive Photo 沉浸摄影 / Ghibli 吉卜力 / Fluent 流利设计），每种均适配浅色 / 深色 / 跟随系统三态

---

## 目录

- [功能特性](#功能特性)
- [环境要求](#环境要求)
- [目录结构](#目录结构)
- [通用部署流程](#通用部署流程两步)
- [宝塔面板部署教程](#宝塔面板部署教程)
- [1Panel 部署教程](#1panel-部署教程)
- [Docker 部署](#docker-部署)
- [配置说明](#配置说明backendconfigphp)
- [后端 API 一览](#后端-api-一览)
- [备份与恢复](#备份与恢复)
- [系统升级](#系统升级)
- [安全说明](#安全说明)
- [常见问题](#常见问题)

## 功能特性

| 模块 | 说明 |
| --- | --- |
| 主页导航 | 壁纸（含模糊 / 遮罩调节）、Logo、站点标题、粗黑体时钟、天气（纯文字随主题）、多引擎搜索框（下拉带站点 logo）、公告胶囊、页脚版本号；左侧分组目录条（滚动自动显示、停止后自动收起）、右下角常驻返回顶部按钮 |
| 视图切换 | 主页搜索框下方一键切换「🧭 导航站 / 📰 热点新闻」同页双视图，后台可选默认显示二者 / 仅导航 / 仅新闻 |
| 分组管理 | 多分组、名称与描述、后台拖拽排序、**前端显示 / 隐藏**（隐藏后仅登录管理员可见，访客与其他角色不可见） |
| 卡片管理 | 标题 / 链接 / 描述、图标（站点 favicon 服务端自动抓取并缓存、本地上传、文字色块）、内网地址、三种打开方式（当前页 / 新窗口 / iframe 弹层）、上下排序；**主页分组内拖拽排序**（管理员在主页直接拖动卡片，保存 / 取消有守卫确认） |
| 站点设置 | 二级导航 6 个分区独立保存：🧭 基础信息 / 🎨 外观布局 / 🖼️ Logo 与壁纸 / 🕐 时钟与天气 / 🔍 搜索引擎 / 📰 热点新闻；壁纸图库弹窗选用（内置系统预置壁纸，不可删除）、分目录上传；搜索引擎预置 13 个（百度 / Google / Bing / DuckDuckGo / Yandex / GitHub / 搜狗 / 360搜索 / 神马 / 夸克 / 头条搜索 / 中国搜索 / 抖音），支持增删 |
| 备份与更新 | 独立一级导航：配置与文件备份导出 / 导入、恢复初始状态、**在线升级**、更新日志（仅管理员，编辑者不可见） |
| 热点新闻 | 内置 21 个数据源（知乎 / 百度 / B站 / 微博 / HN / GitHub / 澎湃 / 今日头条 / 百度贴吧 / 豆瓣电影 / 虎扑 / 稀土掘金 / 少数派 / 牛客 / 腾讯新闻 / Product Hunt / Freebuf / IT之家 / Solidot / 华尔街见闻 / V2EX），卡片式热榜，前三名彩色徽章，支持单卡刷新、数据源勾选与自定义排序 |
| 天气 | 城市可后台指定，open-meteo 主源 + wttr.in 兜底定位，扩展天气代码映射 |
| 权限系统 | 三种角色：**管理员**（全部权限）/ **编辑者**（分组、卡片、上传；不能改设置与账号）/ **只读**（仅查看）；旧库自动升级、最后一个管理员保护 |
| 账号安全 | 会话登录鉴权、`session_regenerate_id` 防固定、Cookie HttpOnly + SameSite + HTTPS 下自动 Secure、登录失败计数防爆破、**CSRF 令牌校验**（全部写接口）、多账号管理（增删 / 改密 / 改角色） |
| 备份与恢复 | 一键导出全部分组、卡片、设置及所有图标 / Logo / 壁纸 / favicon 为单个 JSON（显式列名导出）；导入时清空现有数据后完整恢复（用户账号与系统预置壁纸不受影响），临时目录 + 数据库事务双重保障；另提供「恢复初始状态」一键回到刚安装时的默认设置与示例内容 |
| 在线升级 | 后台上传官方升级包（.zip，仅含变更文件）一键完成版本升级：自动校验并预览变更清单，确认后原子替换生效，数据库数据不受影响；被替换 / 删除的原文件自动备份，可手动回滚；zip 解析为内置纯 PHP 实现，无 ZipArchive 扩展的主机也可使用 |
| 安全防护 | 全部 SQL 预处理；服务端抓取 SSRF 防护（DNS 解析后校验私有 / 保留 / 云元数据 / CGNAT 地址，重定向逐跳检查）+ **TLS 证书严格校验**；卡片与搜索引擎地址 http(s) 协议白名单；上传 SVG 自动净化、图片类型与扩展名交叉复核 + finfo MIME 检测；上传目录禁止执行脚本；公开接口不泄露错误详情（仅记服务端日志） |
| 其他 | 设置项服务端白名单校验、上传类型与大小校验（壁纸 ≤ 10MB，图标 / Logo ≤ 5MB）、后台版本号与更新日志 |

## 环境要求

- PHP **7.4 及以上**（推荐 8.x），必须启用 `pdo_mysql` 扩展
  - `curl` **强烈推荐**：站点信息 / 图标 / 热榜 / 天气的出站抓取均已启用 TLS 证书严格校验，无 curl 时自动降级为 stream 方式（需 OpenSSL）
  - `fileinfo` 推荐：上传文件的 MIME 真实类型复核（缺失时自动跳过该项检查）
  - `zlib` 用于在线升级（PHP 默认自带）
- MySQL **5.7 及以上**（推荐 8.0）
- Nginx / Apache / OpenResty 任意一种 Web 服务器

## 目录结构

```
SolarPanel/
├── index.html               # 主页（站点根目录直接访问）
├── backend/                 # 后端 API（PHP）
│   ├── config.php           # 数据库配置（安装向导自动写入）
│   ├── lib/                 # 公共类库
│   │   ├── db.php           # PDO 连接、表结构自动迁移
│   │   ├── auth.php         # 会话鉴权、角色权限、CSRF 令牌
│   │   ├── response.php     # 统一 JSON 响应
│   │   ├── security.php     # SSRF 判定、SVG 净化、上传目录防护
│   │   ├── http.php         # 出站 HTTP 抓取（TLS 严格校验 + SSRF 防护）
│   │   ├── settings.php     # 设置项白名单、预置资源目录登记
│   │   ├── sort.php         # 排序辅助
│   │   ├── upgrade.php      # 在线升级：zip 解析、路径校验、原子替换
│   │   └── news_sources.php # 热点新闻数据源定义
│   └── api/
│       ├── install.php      # 一键安装：建表 + 默认数据（成功后自删除）
│       ├── public.php       # 主页公开数据（隐藏分组过滤）
│       ├── auth.php         # 登录 / 登出 / 会话检测
│       ├── groups.php       # 分组增删改 / 排序 / 显示隐藏
│       ├── items.php        # 卡片增删改 / 排序 / 站点信息与图标抓取
│       ├── settings.php     # 站点设置读写（键名白名单 + 分区保存）
│       ├── user.php         # 账号与权限组管理
│       ├── upload.php       # 图标 / Logo / 壁纸上传（分目录、类型复核）
│       ├── gallery.php      # 壁纸图库列表 / 删除（系统预置壁纸自动入图库且不可删除）
│       ├── backup.php       # 配置与文件备份导出 / 导入 / 恢复初始状态
│       ├── upgrade.php      # 在线升级：升级包校验预览 / 应用 / 放弃（仅管理员）
│       ├── weather.php      # 天气查询
│       └── news.php         # 热点新闻聚合（21 个数据源）
├── frontend/                # 前端（纯静态，与后端分离）
│   ├── login.html           # 登录页
│   ├── admin.html           # 管理后台
│   ├── assets/              # 样式（css）与脚本（js）
│   └── uploads/             # 上传文件（需可写）：icons/ logos/ wallpapers/ favicon/（weather/ 为系统预置壁纸，随包分发；news/ 为热榜缓存；upgrade_staging/ upgrade_backup/ 为升级会话暂存与回滚备份，运行时自动生成）
├── sql/
│   └── init.sql             # 手动建表脚本（可选，推荐用 install.php）
├── tools/
│   └── build_upgrade.php    # 升级包生成工具（开发者，CLI 运行）
├── docker/
│   └── nginx.conf           # nginx 站点配置参考（compose 内联版已在 docker-compose.yml 的 configs 中）
├── Dockerfile               # Docker 部署：PHP-FPM 运行环境镜像
├── docker-compose.yml       # Docker 部署：一键编排 app_init + PHP-FPM + nginx + MySQL（镜像部署版）
└── up/                      # 升级包输出目录（开发者本地，部署无需上传）
```

## 通用部署流程（两步）

无论使用哪种环境，核心流程都一样：

1. **上传代码**：把项目目录内的全部文件放到网站根目录（保证浏览器能访问到 `/index.html` 和 `/backend/api/install.php`）。
2. **安装向导**：浏览器访问 `http://你的域名/backend/api/install.php`，在表单中填写数据库地址 / 端口 / 数据库名 / 用户名 / 密码（数据库名仅允许字母、数字、下划线）、**站点地址**（域名或内网地址，用于「退出登录」跳转），并**自行设置管理员账号与密码（至少 6 位，系统不提供默认口令）**，点击「开始安装」。

安装向导会自动完成：测试连接（连不上时按错误码给出中文提示）→ 建库建表 → 写入默认数据 → **把连接信息自动写回 `backend/config.php`** → **自动删除安装文件（install.php）与 sql 目录**，全程无需手动编辑配置文件。仅当 `config.php` 不可写时，页面会显示配置内容，按提示手动保存一次即可（此时安装文件不会被自动删除，请手动删除）。

登录入口：`http://你的域名/frontend/login.html`，使用安装时设置的管理员账号登录。**安装完成后请立即确认密码强度，并定期在后台「账号管理」中修改。**

安装完成后访问 `http://你的域名/` 即可直接打开主页。

---

## 宝塔面板部署教程

以宝塔 Linux 面板 + Nginx + MySQL 8.0 + PHP 8.x 为例。

### 1. 安装基础环境

1. 登录宝塔面板，进入 **软件商店**。
2. 安装以下软件（如已安装可跳过）：
   - **Nginx**（任意 1.18+ 版本）
   - **MySQL** 5.7 或 8.0
   - **PHP** 7.4 / 8.0 / 8.1 / 8.2 均可，推荐 8.x

### 2. 安装 PHP 扩展

1. 进入 **软件商店 → 已安装 → PHP → 设置 → 安装扩展**。
2. 确认 `pdo_mysql`、`fileinfo` 已安装（宝塔一般默认已装）；建议同时启用 `curl`（站点信息抓取更稳定，且支持 TLS 证书严格校验）。

### 3. 创建数据库

1. 进入 **数据库** 页面，点击 **添加数据库**。

   | 项目 | 填写内容 |
   | --- | --- |
   | 数据库名 | 例如 `solarpanel`（仅字母 / 数字 / 下划线；宝塔会自动创建同名用户） |
   | 用户名 | 与数据库名相同（自动带出） |
   | 密码 | 使用宝塔生成的随机密码即可 |
   | 访问权限 | 本地服务器 |
   | 字符集 | utf8mb4 |

2. 记下 **数据库名、用户名、密码**，稍后要用。

### 4. 创建站点并上传代码

1. 进入 **网站 → 添加站点**：

   | 项目 | 填写内容 |
   | --- | --- |
   | 域名 | 你的域名（或 `IP:端口`） |
   | 根目录 | 默认即可，如 `/www/wwwroot/solarpanel` |
   | PHP 版本 | 选择 7.4+ / 8.x |
   | 数据库 | 不勾选（上一步已单独创建） |

2. 打开 **文件**，进入站点根目录，**清空默认文件**。
3. 把本项目文件夹内的**全部内容**（`backend/`、`frontend/`、`sql/`、`index.html`）上传到站点根目录，推荐本地打包成 zip 上传后在线解压。
4. 确认目录结构正确：站点根目录下应能看到 `index.html`、`backend` 和 `frontend`。

### 5. 打开安装向导并登录

1. 浏览器访问 `http://你的域名/backend/api/install.php`，进入安装向导。

   | 项目 | 填写内容 |
   | --- | --- |
   | 数据库地址 | `localhost`（保持默认） |
   | 端口 | `3306`（保持默认） |
   | 数据库名 / 用户名 | 宝塔创建的库名与用户名（通常相同） |
   | 密码 | 宝塔数据库密码（面板数据库列表中点眼睛图标可查看） |
   | 管理员账号 / 密码 | **自行设置**（密码至少 6 位，不提供默认口令） |

2. 点击 **开始安装**，看到「✓ 安装成功」即部署完成。
3. 访问 `http://你的域名/` 查看主页，登录后台后在 **站点设置** 中配置标题、Logo、壁纸、公告、天气、搜索引擎；在 **分组 / 卡片管理** 中添加导航内容。

### 6. 设置目录权限

1. **文件** 中右键 `frontend/uploads` 目录 → **权限**，设置为 `755`，所有者 `www`，并勾选「应用到子目录」。
2. 如上传功能报错，可将整个站点目录权限重置为 `www` 所有。
3. 在线升级需要 `backend/`、`frontend/assets/`、`tools/` 等目录对 PHP 运行用户可写（升级失败提示权限问题时，将站点目录所有者改为 `www` 即可）。

### 7. 宝塔安全加固（推荐）

- **安装后删除安装入口**：安装成功后向导会**自动删除** `install.php` 与 `sql/`；若因权限未能自动删除（成功页有提示），请手动删除 `backend/api/install.php`。
- **屏蔽敏感目录**：站点 → 设置 → 配置文件，在 `server` 块中加入：

  ```nginx
  # 禁止访问后端内部类库与 SQL 目录
  location ^~ /backend/lib/ { deny all; }
  location ^~ /sql/         { deny all; }
  # 上传目录禁止执行脚本（应用内置 .htaccess 仅对 Apache 生效，Nginx 需手动配置）
  location ~* ^/frontend/uploads/.*\.(php|phtml|pht|phps|phar|cgi|pl|py|jsp|asp|aspx)$ { deny all; }
  ```

- **强制 HTTPS**：站点 → SSL → 申请证书并开启「强制 HTTPS」。开启后系统会自动给会话 Cookie 附加 Secure 标志（兼容反向代理 SSL 卸载）。

---

## 1Panel 部署教程

以 1Panel + OpenResty + MySQL 8 + PHP 运行环境为例。

### 1. 安装应用

1. 登录 1Panel，进入 **应用商店**。
2. 安装 **OpenResty** 与 **MySQL** 5.7 / 8.0（如已安装可跳过）。

### 2. 创建数据库

1. 进入 **数据库**，选择 MySQL 实例，点击 **创建数据库**：

   | 项目 | 填写内容 |
   | --- | --- |
   | 名称 | `solarpanel` |
   | 用户名 | `solarpanel` |
   | 密码 | 自定义或随机生成 |
   | 权限 | 本地（localhost） |

2. 注意：1Panel 中数据库运行在容器内，安装向导的「数据库地址」需填写 **应用商店中 MySQL 容器名**（通常是 `应用名-mysql`，可在应用详情「容器」中查看），而不是 `localhost`。

### 3. 创建 PHP 运行环境

1. 进入 **网站 → 运行环境 → 创建运行环境**：

   | 项目 | 填写内容 |
   | --- | --- |
   | 名称 | `solarpanel-php` |
   | 类型 | PHP |
   | 版本 | 8.x（7.4+ 均可） |

2. 在 **扩展** 选项中确认勾选/添加 `pdo_mysql`、`fileinfo`（建议加 `curl`）。
3. 若你的 1Panel 版本建站向导直接支持「PHP 网站」，可在创建网站时一步完成。

### 4. 创建网站并上传代码

1. 进入 **网站 → 创建网站**，填写域名，类型选择 PHP 运行环境（或先建静态站点再配置反向代理到 PHP 运行环境的 `127.0.0.1:端口`）。
2. 进入 **文件**，导航到网站根目录，上传本项目全部内容，保证根目录下直接能看到 `index.html`、`backend`、`frontend`。

### 5. 打开安装向导并登录

1. 浏览器访问 `http://你的域名/backend/api/install.php`。

   | 项目 | 填写内容 |
   | --- | --- |
   | 数据库地址 | MySQL **容器名**（如 `mysql`；PHP 跑在宿主机时填 `127.0.0.1` 并确认端口已映射） |
   | 端口 | `3306`（保持默认） |
   | 数据库名 / 用户名 / 密码 | 第 2 步创建的值 |
   | 管理员账号 / 密码 | **自行设置**（密码至少 6 位） |

2. 看到「✓ 安装成功」即部署完成，安装文件与 `sql/` 已自动删除。
3. 访问 `http://你的域名/` 查看主页，使用自设账号登录后台开始配置。

### 6. 1Panel 安全加固（推荐）

- **网站 → 设置 → HTTPS**：申请证书并开启强制 HTTPS。
- **配置文件**（OpenResty）中加入与宝塔章节相同的敏感目录屏蔽规则（`/backend/lib/`、`/sql/`、`/frontend/uploads/` 脚本执行）。
- 确认 `frontend/uploads` 目录对 PHP 容器运行用户（通常 `www-data`）可写。

---

## Docker 部署

一条命令拉起 PHP-FPM + nginx + MySQL 三个容器，业务代码与宝塔 / 1Panel 部署完全一致，安装向导照常使用。镜像已发布至 Docker Hub：[`ovitor/solarpanel`](https://hub.docker.com/r/ovitor/solarpanel)。

### 1. 启动

**方式一：Docker Hub 镜像部署（推荐，无需下载完整项目代码）**

新建一个空目录，在其中创建 `docker-compose.yml`，内容如下（nginx 配置已内联，单文件即可启动）：

```yaml
services:
  # 一次性初始化容器：把镜像中的整站代码释放到共享卷，避免 nginx / php 容器启动顺序不同导致卷被空目录填充
  app_init:
    image: ovitor/solarpanel:latest
    container_name: solarpanel-init
    entrypoint: ["sh", "-c", "cp -a /var/www/html/. /app/ && echo 'app files populated'"]
    volumes:
      - solarpanel_app:/app
    restart: "no"

  solarpanel:
    image: ovitor/solarpanel:latest
    container_name: solarpanel-php
    restart: unless-stopped
    environment:
      TZ: Asia/Shanghai
    volumes:
      - solarpanel_app:/var/www/html
    depends_on:
      app_init:
        condition: service_completed_successfully
      mysql:
        condition: service_healthy

  nginx:
    image: nginx:alpine
    container_name: solarpanel-nginx
    restart: unless-stopped
    ports:
      - "16823:80"        # 左侧为宿主机端口，按需修改
    volumes:
      - solarpanel_app:/var/www/html:ro
    configs:
      - source: nginx_conf
        target: /etc/nginx/conf.d/default.conf
    depends_on:
      app_init:
        condition: service_completed_successfully

  mysql:
    image: mysql:8.0
    container_name: solarpanel-mysql
    restart: unless-stopped
    environment:
      TZ: Asia/Shanghai
      MYSQL_ROOT_PASSWORD: solarpanel_root   # 建议修改
      MYSQL_DATABASE: solarpanel
      MYSQL_USER: solarpanel
      MYSQL_PASSWORD: solarpanel             # 建议修改（改后同步更新安装向导中的密码）
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
    volumes:
      - solarpanel_mysql:/var/lib/mysql
    healthcheck:
      test: ["CMD-SHELL", "mysqladmin ping -h 127.0.0.1 -uroot -p$$MYSQL_ROOT_PASSWORD --silent"]
      interval: 5s
      timeout: 5s
      retries: 20

configs:
  nginx_conf:
    content: |
      server {
          listen 80;
          server_name _;
          root /var/www/html;
          index index.html index.php;
          client_max_body_size 300m;
          location ^~ /backend/lib/ { deny all; }
          location ^~ /sql/         { deny all; }
          location ~* ^/frontend/uploads/.*\.(php|phtml|pht|phps|phar|cgi|pl|py|jsp|asp|aspx)$ { deny all; }
          location / { try_files $$uri $$uri/ =404; }
          location ~ \.php$ {
              try_files $$uri =404;
              fastcgi_pass solarpanel:9000;
              fastcgi_index index.php;
              fastcgi_param SCRIPT_FILENAME $$document_root$$fastcgi_script_name;
              include fastcgi_params;
          }
      }

volumes:
  solarpanel_app:
  solarpanel_mysql:
```

> **关键设计**：`app_init` 是一次性初始化容器，先把镜像中的整站代码释放到 `solarpanel_app` 共享卷；`solarpanel`（PHP-FPM）与 `nginx` 都通过 `depends_on.app_init.condition: service_completed_successfully` 等它成功退出后才启动。这样无论 Docker 编排启动顺序如何，nginx 与 PHP 容器看到的 `/var/www/html` 永远含完整代码，避免空目录导致 404。**首次启动后** `app_init` 不会再运行（卷已填充），重启容器时它会被跳过。

然后启动：

```bash
docker compose up -d
```

> 说明：首次启动时，`app_init` 容器先把镜像中的整站代码释放到 `solarpanel_app` 共享卷；之后 `solarpanel`（PHP-FPM）与 `nginx` 才启动。`backend/config.php`、上传的图标 / 壁纸、在线升级产生的文件变更都保存在该卷中，重建容器不丢失。**站点代码更新请使用后台「在线升级」功能**（Docker Hub 镜像仅作为运行环境，数据卷中的代码不会被拉取新镜像覆盖）。

**方式二：克隆仓库直接用（与方式一相同）**

仓库根目录的 `docker-compose.yml` 与方式一完全一致（镜像部署 + 命名卷 + app_init 初始化），克隆后直接启动即可：

```bash
git clone <仓库地址> && cd SolarPanel
docker compose up -d
```

如需本地改代码调试，在 `docker-compose.yml` 的 `app_init` 与 `solarpanel` 服务下各加一行 `build: .`，然后 `docker compose up -d --build` 用本地代码构建镜像。

两种方式均会自动创建 MySQL 8.0 容器与数据卷（PHP 8.2-FPM，已装 pdo_mysql / curl / opcache）。

### 2. 安装向导

浏览器访问 `http://服务器IP:16823/backend/api/install.php`：

| 项目 | 填写内容 |
| --- | --- |
| 数据库地址 | `mysql`（compose 服务名，**不要填 localhost**） |
| 端口 | `3306` |
| 数据库名 | `solarpanel` |
| 用户名 / 密码 | `solarpanel` / `solarpanel`（可在 docker-compose.yml 的 environment 中修改） |
| 管理员账号 / 密码 | **自行设置**（密码至少 6 位） |

看到「✓ 安装成功」即部署完成。访问 `http://服务器IP:16823/` 查看主页。

### 3. 数据持久化与目录权限

- **网站文件**：
  - 方式一（镜像部署）：整站代码与上传文件存放在命名卷 `solarpanel_app` 中，重建容器不丢失；
  - 方式二（仓库构建）：项目目录整体 bind mount 进容器，文件变更直接落在宿主机项目目录内。
- **MySQL 数据**：存放在命名卷 `solarpanel_mysql` 中，`docker compose down` 不丢数据（加 `-v` 才会删除卷，慎用）。
- **Linux 宿主机权限**：方式一使用命名卷，文件属主继承镜像内的 `www-data`，无需处理；方式二（bind mount）若安装向导 / 上传报「不可写」，执行：

  ```bash
  sudo chown -R 33:33 .
  ```

  Docker Desktop（Windows / macOS）的 bind mount 默认可写，无需处理。

### 4. 常用命令

```bash
docker compose pull             # 方式一：拉取最新镜像
docker compose logs -f          # 查看日志（加 solarpanel / nginx / mysql 可看单容器）
docker compose restart          # 重启
docker compose down             # 停止（数据保留）
docker compose up -d --build    # 方式二：代码更新后重建镜像并启动
```

### 5. 端口与配置修改

- **对外端口**：修改 docker-compose.yml 中 nginx 的 `"16823:80"` 左侧宿主机端口。
- **数据库密码**：修改 mysql 服务的 `MYSQL_PASSWORD` / `MYSQL_ROOT_PASSWORD`（已安装的站点还需在后台重新安装或手动同步 `backend/config.php`）。
- **时区**：默认 `Asia/Shanghai`，修改 compose 与 Dockerfile 中的 `TZ` / `date.timezone`。
- **HTTPS**：建议在 Docker 外层挂一层反向代理（如宿主机 nginx / 宝塔 / Caddy）做证书终止，反代到 `127.0.0.1:16823`；系统会自动识别 `X-Forwarded-Proto` 为会话 Cookie 附加 Secure 标志。

### 6. 在线升级与迁移

- **在线升级**：后台「备份与更新 → 在线升级」在 Docker 下照常可用——项目目录为 bind mount，升级文件变更持久保存在宿主机。
- **迁移到新机器**：打包整个项目目录（含 `backend/config.php` 与 `frontend/uploads/`），新机 `docker compose up -d` 后执行 `docker volume` 数据恢复或将 MySQL 数据卷一并迁移；也可用后台「导出配置」备份 + 新机安装向导导入的方式迁移。

---

## 配置说明（backend/config.php）

```php
return [
    'host'    => 'localhost', // 数据库地址
    'port'    => 3306,        // 端口
    'dbname'  => '',          // 数据库名（仅字母 / 数字 / 下划线，≤64 字符）
    'user'    => '',          // 用户名
    'pass'    => '',          // 密码
    'charset' => 'utf8mb4',
];
```

> 此文件通常**无需手动编辑**：安装向导会自动写入数据库信息。仅在自动写入失败或日后更换数据库时才需手动修改。管理员账号在安装向导中设置，仅写入数据库，不保存于此文件。

## 后端 API 一览

| 端点 | 主要 action | 鉴权 | 说明 |
| --- | --- | --- | --- |
| `install.php` | — | 无（安装后自删除） | 安装向导：建表、初始化、写回配置 |
| `public.php` | — | 无 | 主页全部公开数据（隐藏分组对非管理员过滤） |
| `auth.php` | `me` / `login` / `logout` | 部分需要 | 会话检测 / 登录 / 登出 |
| `groups.php` | `list` / `edit` / `delete` / `sort` / `sort_batch` / `visible` | 登录（写操作需管理员或编辑者） | 分组管理与前端显隐 |
| `items.php` | `list` / `edit` / `delete` / `sort` / `sort_batch` / `favicon` / `fetch_meta` | 登录（写操作需管理员或编辑者） | 卡片管理、站点信息与图标抓取 |
| `settings.php` | `list` / `save` | 登录（保存仅管理员） | 站点设置（键名白名单 + 分区保存） |
| `user.php` | `me` / `list` / `create` / `update` / `change_password` / `set_password` / `set_role` / `delete` | 登录（账号管理仅管理员） | 账号与权限组 |
| `upload.php` | — | 管理员 / 编辑者 | 图标 / Logo / 壁纸上传（分目录、类型与大小校验、MIME 复核） |
| `gallery.php` | `list` / `delete` | 管理员 / 编辑者 | 壁纸图库 |
| `backup.php` | `export` / `import` / `reset` | 仅管理员 | 配置与全部上传文件备份 / 恢复 / 恢复初始状态 |
| `upgrade.php` | `check` / `apply` / `cancel` | 仅管理员 | 在线升级：上传升级包校验预览、确认应用、放弃会话 |
| `weather.php` | `current` | 无 | 天气查询 |
| `news.php` | `meta` / `all` / `source` | 无 | 热点新闻数据源定义、聚合列表、单源刷新 |

未登录访问管理接口返回业务码 `401`，权限不足返回 `403`，CSRF 校验失败返回业务码 `403`。

> **CSRF 说明**：所有写请求（POST）必须携带 `X-CSRF-Token` 请求头。令牌由服务端在会话建立时通过 `csrf_token` Cookie 下发，前端 `API.request` 封装自动读取并携带，**正常使用前端界面无需任何额外处理**；仅自行开发第三方调用时需要注意（先 GET `auth.php?action=me` 获取令牌 Cookie）。

## 备份与恢复

- **导出**：后台「💾 备份与更新 → ⬇️ 导出配置」，下载单个 `.json` 文件，包含全部分组、卡片、站点设置，以及 `uploads/` 下所有图标 / Logo / 壁纸 / favicon（文件以 base64 内嵌，流式输出不占内存）。
- **导入**：选择备份文件后需**两次确认**；服务端先将文件解压到临时目录、配置写入数据库事务，全部成功后才切换生效（失败自动回滚，不破坏现有数据）。导入会清空现有分组、卡片、设置与用户上传文件，**用户账号与系统预置壁纸不受影响**。
- **恢复初始状态**：一键清空所有分组、卡片、自定义设置与用户上传文件，写回默认设置 + 示例分组卡片（与全新安装一致），用户账号与系统预置壁纸保留。
- 备份文件可跨服务器迁移；导入时会自动清洗非法链接协议与危险 SVG 内容，未知设置键与引用失效分组的孤儿卡片会被自动跳过。

## 系统升级

新版本发布后，只需下载官方 **升级包**（.zip，仅含变更文件）在后台一键完成升级，分组 / 卡片 / 设置等数据库数据不受影响。

### 方式一：后台在线升级（推荐）

1. 登录管理员账号，进入「💾 备份与更新 → 🚀 在线升级」，上传升级包；
2. 系统自动校验升级包并预览变更文件清单（版本链、路径合法性、包完整性），点击「确认升级」应用；
3. 被替换 / 删除的原文件自动备份到 `frontend/uploads/upgrade_backup/{会话}/`，可随时手动复制回原位置回滚；
4. 升级完成后页面自动刷新，建议再按 `Ctrl+F5` 强制刷新浏览器缓存。

升级安全机制：升级包 `from` 版本必须与站点当前版本一致（跨版本请逐版升级）；路径三层校验（穿越 / 盘符 / 恶意条目过滤 + 清单白名单复核）；`backend/config.php`（数据库配置）与 `backend/api/install.php`（安装入口）永不参与升级；用户上传文件不受影响；单文件 20MB / 总量 200MB / 解压炸弹防护；升级会话 1 小时未应用自动清理。zip 解析为内置纯 PHP 实现（零扩展依赖），无 ZipArchive 扩展的主机也可使用。

### 方式二：手动覆盖

下载完整包解压覆盖站点根目录（**切勿覆盖 `backend/config.php`**），浏览器 `Ctrl+F5` 强刷缓存。数据库结构有变更时无需手工操作，系统会自动迁移。

### 生成升级包（开发者）

```bash
# old/ 为上一版完整代码目录或完整包 zip，new/ 为当前版本目录（默认当前项目）
php -d phar.readonly=0 tools/build_upgrade.php /path/to/old-version /path/to/new-version output.zip
```

工具逐文件 md5 对比新旧版本，仅打包变更 / 新增文件，自动排除 `backend/config.php`、`backend/api/install.php`、`project_memory.md` 与 `frontend/uploads/` 运行态文件，并按基线（from 版本）的升级白名单复核清单，包内含 `manifest.json`（在线升级依据）与 `升级说明.txt`。

## 安全说明

- **SQL 注入**：全部数据库操作使用 PDO 预处理（`EMULATE_PREPARES = false`），排序字段走白名单。
- **CSRF**：同步令牌模式——服务端会话内生成 256 位随机令牌，经 `csrf_token` Cookie 交付前端，所有写请求（POST / PUT / DELETE）必须以 `X-CSRF-Token` 请求头回传并恒定时间比对，跨站伪造请求无法携带合法令牌；登录成功后令牌轮换（防会话固定），登出即清除；会话 Cookie 本身带 `SameSite=Lax` 双重防护。
- **SSRF**：服务端抓取站点信息 / 图标前，先将主机名 DNS 解析为真实 IP 再校验，拦截回环、私网、云元数据（169.254.169.254）、CGNAT（100.64/10）、代理 fake-ip（198.18/15）等地址；HTTP 重定向逐跳校验目标。
- **出站 TLS**：全部服务端出站请求（图标抓取 / 站点信息 / 热榜 / 天气）启用 TLS 证书严格校验（curl `VERIFYPEER` + stream `verify_peer`），防中间人篡改响应内容；抓取逻辑统一收敛在 `backend/lib/http.php`。
- **存储型 XSS**：卡片地址、内网地址、搜索引擎地址强制 `http(s)` 协议白名单（后端校验 + 前端跳转兜底 + 备份导入清洗）；上传与抓取的 SVG 自动剥离 `<script>`、事件属性与危险协议；新闻标题一律按纯文本渲染。
- **文件上传**：扩展名白名单 + **真实内容复核**（getimagesize 图片类型须与扩展名同族 + finfo MIME 检测非图片内容一律拒绝）+ 随机文件名；上传目录自动写入 `.htaccess` 禁止执行脚本并对 SVG 沙箱化（**Nginx 用户需按上文部署教程手动添加等价规则**）。
- **信息泄露**：安装向导、备份导入 / 恢复等环节的异常只返回通用提示，数据库主机 / 账号 / SQL 等敏感详情仅写入服务端错误日志；安装时数据库名强制 `[A-Za-z0-9_]{1,64}` 白名单（该标识符无法参数化）后才可拼接建库语句；安装页不预填任何默认管理员口令。
- **会话**：登录成功后 `session_regenerate_id(true)`；Cookie 带 HttpOnly 与 SameSite=Lax，HTTPS 访问时自动附加 Secure（兼容反向代理 SSL 卸载的 `X-Forwarded-Proto`，请确保该请求头仅由可信反向代理设置）。
- **在线升级**：升级包 `from` 版本必须与站点当前版本一致；路径三层校验（穿越 / 盘符 / 恶意条目过滤 + manifest 白名单）；`backend/config.php` 与 `backend/api/install.php` 永不参与升级；条目数 / 单文件 / 总量 / 解压输出多级上限防护；应用阶段逐文件临时写入 + 校验 + 原子替换。

## 常见问题

**Q：安装向导提示数据库连接失败？**
依次检查：数据库名 / 用户名 / 密码是否与面板一致（密码复制时别带空格；数据库名仅允许字母、数字、下划线）；MySQL 服务是否启动；1Panel 容器部署时「数据库地址」是否填了 MySQL 容器名而非 `localhost`。向导会按错误码给出提示：1045 为用户名或密码不正确，1044 为账号无权访问该库，1049 为数据库不存在。

**Q：安装向导默认管理员账号密码是什么？**
系统**不提供默认口令**。安装表单中必须自行设置管理员账号与密码（至少 6 位），请妥善保管；忘记密码参见下文重置方法。

**Q：主页一片空白或提示数据加载失败？**
说明后端不可达。直接在浏览器打开 `/backend/api/public.php` 查看；多数是未执行 `install.php` 或数据库配置错误。

**Q：操作时提示「CSRF 令牌无效或已过期，请刷新页面后重试」？**
令牌与会话绑定且登录后会轮换。刷新页面（必要时退出重登）即可恢复；多标签页同时长时间挂着后台后操作也会触发，属正常防护。

**Q：上传图片失败？**
检查 `frontend/uploads` 目录权限（需可写）；壁纸不超过 10MB、图标 / Logo 不超过 5MB；格式需为 jpg / jpeg / png / gif / webp / ico / bmp / svg；文件真实内容必须与扩展名一致（伪装成图片的脚本会被拒绝）。

**Q：「自动获取信息 / 获取站点图标」失败？**
该功能需要服务器能访问目标站点（境内服务器无法直连 Google 等境外站点属预期）；接口返回中文错误提示（超时、DNS、拒连、SSL 等）。证书自签或证书链异常的目标站会被 TLS 严格校验拦截，属预期安全行为。建议安装 PHP curl 扩展。

**Q：天气显示的城市不对？**
在后台「站点设置 → 🕐 时钟与天气」中手动填写城市名保存；天气服务有每日限额时会自动切换备用数据源。

**Q：忘记管理员密码？**
删除数据库中所有表后重新访问 `install.php` 即可重置（会清空已有数据，请先用后台备份功能导出），或直接改库 `users` 表中的密码哈希（bcrypt）。

**Q：在线升级提示「升级包要求当前版本为 …」？**
升级包只能从其标注的版本（`from`）升到目标版本（`to`）。请确认站点当前版本（后台顶栏可见），按顺序逐版下载对应升级包升级；跨多个版本或版本过旧时，直接下载最新完整包覆盖安装（**切勿覆盖 `backend/config.php`**）。

**Q：在线升级后页面样式错乱或功能异常？**
多为浏览器缓存了旧版静态资源。按 `Ctrl+F5` 强制刷新；仍异常时退出登录重新登录，或清空浏览器缓存后再试。

**Q：可以放在子目录吗？**
可以。前端全部使用相对路径引用 API，例如把整个项目放到 `/nav/` 下，访问 `/nav/index.html`、安装页为 `/nav/backend/api/install.php` 即可。

**Q：在线更新失败？**
在 Web 服务器将 `backend/`、`frontend/`、`tools/` 目录及其子目录权限改为 755 / 所有者 `www`（站点运行用户）即可。

**Q：Docker 部署时安装向导提示数据库连接失败？**
「数据库地址」必须填 compose 服务名 `mysql`（容器网络内互通），不是 `localhost`；确认 mysql 容器状态为 healthy（`docker compose ps`），密码与 docker-compose.yml 中 `MYSQL_PASSWORD` 一致。

**Q：Docker 部署后上传 / 安装报「不可写」？**
镜像部署（方式一，命名卷）无需处理；仓库 bind mount 部署（方式二）在 Linux 宿主机执行 `sudo chown -R 33:33 .` 将项目目录属主交给容器内 www-data 用户；Docker Desktop（Windows / macOS）无需处理。

---

SolarPanel —— 柔软精致的个人导航面板，数据完全自持。
