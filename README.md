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

