## 自即日起对 SolarPanel v2.1 系列版本停止维护，以 Go 语言进行轻量化重构并编译发布 Docker 版本，确立「单二进制零依赖、镜像体积更小、启动与并发性能更优」为新基线，全面收敛维护到 Go-only 单版本主线。
<div align=center>
<img src="https://updates.ozero.top/SolarPanel.ico" width="64" height="64"> 
</div>

<h1 align=center>SolarPanel - 轻量自托管导航面板</h1>

[![Version](https://img.shields.io/badge/Version-v3.1.2-5B8DEF?logo=semanticrelease)](#)
[![Go](https://img.shields.io/badge/Go-1.23-00ADD8?logo=go)](https://go.dev)
[![Architecture](https://img.shields.io/badge/Architecture-chi%2Bsqlite-50C878)](https://github.com/go-chi/chi)
[![Multi-Arch](https://img.shields.io/badge/Arch-amd64__arm64__armv7-7A51F5)](https://hub.docker.com/r/ovitor/solarpanel-v3)
[![Image](https://img.shields.io/badge/Docker-14MB-2496ED?logo=docker)](https://hub.docker.com/r/ovitor/solarpanel-v3)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE)

> 轻量自托管导航面板。Go 纯后端 · 零 CGO 单二进制 · 前端内嵌 · 三架构 Docker 镜像约 14MB。

- GitHub：<https://github.com/Ozero-top/SolarPanel-v3>
- Docker Hub：<https://hub.docker.com/r/ovitor/solarpanel-v3>

## ⬆️ 从 V2.1 系列升级到 V3

V3 不自动迁移 V2.1 的数据库，请按「先导出、后导入」的顺序升级：

1. 在 **V2.1 系列后台 → 系统备份 → 导出配置**，保存导出的备份文件
2. 部署 V3（建议使用新的数据目录），首次启动为空库，使用 `admin` / `admin123` 登录
3. 在 **V3 后台 → 系统备份 → 导入配置**，选择第 1 步的备份文件完成恢复

注意事项：

- 导入为**整体替换**：V3 现有的分组、卡片与设置会被备份内容覆盖
- 备份中的上传文件（图标 / Logo / 壁纸）随备份一起导入，无需单独拷贝 `uploads`
- 升级后立即修改默认管理员密码；旧版浏览器缓存建议强制刷新一次

## ✨ 特性

- **零依赖单二进制** — Go + `modernc.org/sqlite`（纯 Go SQLite 实现），无 CGO、无外部数据库、无 PHP
- **空库启动** — 首次启动只建表 + 写入默认设置 + 管理员账号，**不注入任何预置分组与卡片**，内容完全由用户自建
- **两级分组导航** — 支持父子嵌套，侧边栏自动展开/折叠，实时搜索过滤
- **九主题** — `soft` 柔和浮雕（默认）· `blueprint` 工程蓝图 · `clarity` 清透极简 · `soft-utility` 柔和工具 · `aurora` 动态极光 · `iris` 深空 HUD · 铅笔手绘 · `retro-vintage` 复古怀旧（羊皮纸 · 粗棕框 · 衬线大写）· `corporate-clean` 企业简洁（白卡细边 · 蓝主色 · 焦点环）
- **热点新闻聚合** — 24 个内置源（微博 / 知乎 / B站 / GitHub Trending / IT之家 / V2EX / 少数派 / 掘金 等）+ 自定义 RSS
- **天气 + 每日壁纸** — 顶部时钟、天气组件；壁纸源支持 `bing_cn`（默认）/ `nasa` / `picsum`
- **卡片图标智能获取** — 手输域名自动抓取 favicon，也可上传本地图标
- **favicon 三级缓存** — 内存 inflight 去重 → 磁盘 MD5 缓存 → 三源并发抓取（主站 `favicon.ico` / Google s2 / DuckDuckGo），响应 `ETag` + `Cache-Control: immutable`，二次访问 304 零 body
- **安全** — Session + CSRF 双令牌 + 接口限流 + 角色权限 + TOTP 双因子 + 受信设备 + 审计日志
- **PWA** — Service Worker 离线缓存，可安装为应用
- **有序幂等迁移** — `schema_versions` 表追踪，新增 schema 变更只需在迁移列表末尾追加
- **备份迁移** — 一键导出/导入 JSON（含上传文件 base64），分组按 `parent_id` 排序 + 新旧 ID 重映射
- **浏览器书签导入** — 直接上传 Chrome/Edge 导出的 `bookmarks.html`

