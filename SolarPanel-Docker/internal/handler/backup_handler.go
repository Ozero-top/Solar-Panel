package handler

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"solarpanel/internal/auth"
	"solarpanel/internal/config"
	"solarpanel/internal/db"
	"solarpanel/internal/model"

	"github.com/gin-gonic/gin"
)

type BackupHandler struct {
	cfg *config.Config
}

func NewBackupHandler(cfg *config.Config) *BackupHandler {
	return &BackupHandler{cfg: cfg}
}

type backupPayload struct {
	Version  string            `json:"version"`
	Time     string            `json:"time"`
	Settings []model.Setting   `json:"settings"`
	Groups   []model.ItemGroup `json:"groups"`
	Items    []model.Item      `json:"items"`
	Files    map[string]string `json:"files"`
}

const maxFileSize = 20 * 1024 * 1024

func (h *BackupHandler) Dispatch(c *gin.Context) {
	method := c.Request.Method
	action := c.Query("action")

	if method == "GET" {
		h.Export(c)
		return
	}

	if method == "POST" {
		switch action {
		case "import":
			h.Import(c)
		case "reset":
			h.Reset(c)
		default:
			FailMsg(c, "未知操作")
		}
		return
	}

	FailMsg(c, "不支持的请求方法")
}

func (h *BackupHandler) Export(c *gin.Context) {
	var settings []model.Setting
	var groups []model.ItemGroup
	var items []model.Item

	if err := db.DB.Find(&settings).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "导出设置失败")
		return
	}
	if err := db.DB.Find(&groups).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "导出分组失败")
		return
	}
	if err := db.DB.Find(&items).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "导出卡片失败")
		return
	}

	files := make(map[string]string)
	skipDirs := map[string]bool{"weather": true}

	err := filepath.Walk(h.cfg.UploadDir, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return nil
		}
		if info.IsDir() {
			return nil
		}

		rel, err := filepath.Rel(h.cfg.UploadDir, path)
		if err != nil {
			return nil
		}
		rel = filepath.ToSlash(rel)

		topDir := strings.SplitN(rel, "/", 2)[0]
		if skipDirs[topDir] {
			return nil
		}

		if info.Size() > maxFileSize {
			log.Printf("[backup] 跳过过大文件: %s (%d bytes)", rel, info.Size())
			return nil
		}

		data, err := os.ReadFile(path)
		if err != nil {
			log.Printf("[backup] 读取文件失败: %s: %v", rel, err)
			return nil
		}

		files[rel] = base64.StdEncoding.EncodeToString(data)
		return nil
	})
	if err != nil {
		log.Printf("[backup] 遍历上传目录失败: %v", err)
	}

	now := time.Now()
	payload := backupPayload{
		Version:  "1.0",
		Time:     now.Format("2006-01-02 15:04:05"),
		Settings: settings,
		Groups:   groups,
		Items:    items,
		Files:    files,
	}

	raw, err := json.Marshal(payload)
	if err != nil {
		Fail(c, http.StatusInternalServerError, "序列化失败")
		return
	}

	filename := fmt.Sprintf("solarpanel-backup-%s.json", now.Format("20060102-150405"))
	c.Header("Content-Disposition", fmt.Sprintf(`attachment; filename="%s"`, filename))
	c.Header("Content-Type", "application/json; charset=utf-8")
	c.Header("X-Content-Type-Options", "nosniff")
	c.Header("Cache-Control", "no-store")
	c.Data(http.StatusOK, "application/json; charset=utf-8", raw)
}

func (h *BackupHandler) Import(c *gin.Context) {
	// PHP 原版 admin.js 用 fd.append('file', file) — 字段名是 file，不是 backup
	file, _, err := c.Request.FormFile("file")
	if err != nil {
		// 兼容旧备份工具（字段名 backup）
		file, _, err = c.Request.FormFile("backup")
	}
	if err != nil {
		FailMsg(c, "未找到备份文件: "+err.Error())
		return
	}
	defer file.Close()

	body, err := io.ReadAll(file)
	if err != nil {
		Fail(c, http.StatusInternalServerError, "读取文件失败")
		return
	}

	text := strings.TrimSpace(string(body))
	var payload backupPayload

	if decoded, err := base64.StdEncoding.DecodeString(text); err == nil {
		if err := json.Unmarshal(decoded, &payload); err != nil {
			FailMsg(c, "备份文件内容不是有效的 JSON")
			return
		}
	} else {
		if err := json.Unmarshal(body, &payload); err != nil {
			FailMsg(c, "备份文件格式无效（base64 或纯 JSON）")
			return
		}
	}

	tx := db.DB.Begin()
	if tx.Error != nil {
		Fail(c, http.StatusInternalServerError, "开启事务失败")
		return
	}

	if err := tx.Exec("DELETE FROM items").Error; err != nil {
		tx.Rollback()
		Fail(c, http.StatusInternalServerError, "清空卡片失败")
		return
	}
	if err := tx.Exec("DELETE FROM item_groups").Error; err != nil {
		tx.Rollback()
		Fail(c, http.StatusInternalServerError, "清空分组失败")
		return
	}
	if err := tx.Exec("DELETE FROM settings").Error; err != nil {
		tx.Rollback()
		Fail(c, http.StatusInternalServerError, "清空设置失败")
		return
	}

	for _, s := range payload.Settings {
		tx.Create(&model.Setting{ConfigName: s.ConfigName, ConfigValue: s.ConfigValue})
	}

	var groupIDMap = make(map[uint]uint)
	for _, g := range payload.Groups {
		newG := model.ItemGroup{
			Title:       g.Title,
			Description: g.Description,
			Sort:        g.Sort,
			IsVisible:   g.IsVisible,
			UserID:      1,
		}
		tx.Create(&newG)
		groupIDMap[g.ID] = newG.ID
	}

	for _, it := range payload.Items {
		newGroupID, ok := groupIDMap[it.GroupID]
		if !ok {
			continue
		}
		tx.Create(&model.Item{
			GroupID:     newGroupID,
			Title:       it.Title,
			URL:         it.URL,
			LanURL:      it.LanURL,
			Description: it.Description,
			IconType:    it.IconType,
			IconValue:   it.IconValue,
			IconBG:      it.IconBG,
			OpenMethod:  it.OpenMethod,
			Sort:        it.Sort,
			UserID:      1,
		})
	}

	if err := tx.Commit().Error; err != nil {
		Fail(c, http.StatusInternalServerError, "导入失败: "+err.Error())
		return
	}

	writtenFiles := 0
	if len(payload.Files) > 0 {
		skipPrefixes := []string{"weather/", "weather\\"}
		for relPath, b64Content := range payload.Files {
			relPath = filepath.ToSlash(relPath)

			skip := false
			for _, p := range skipPrefixes {
				if strings.HasPrefix(relPath, p) {
					skip = true
					break
				}
			}
			if skip {
				continue
			}

			if strings.Contains(relPath, "..") {
				log.Printf("[backup] 跳过可疑路径: %s", relPath)
				continue
			}

			absPath := filepath.Join(h.cfg.UploadDir, filepath.FromSlash(relPath))
			if !strings.HasPrefix(filepath.Clean(absPath), h.cfg.UploadDir) {
				log.Printf("[backup] 路径越界: %s", relPath)
				continue
			}

			content, err := base64.StdEncoding.DecodeString(b64Content)
			if err != nil {
				log.Printf("[backup] 解码文件失败 %s: %v", relPath, err)
				continue
			}

			if int64(len(content)) > maxFileSize {
				log.Printf("[backup] 跳过过大文件: %s", relPath)
				continue
			}

			if ext := strings.ToLower(filepath.Ext(relPath)); ext == ".svg" {
				content = sanitizeSVG(content)
			}

			if err := os.MkdirAll(filepath.Dir(absPath), 0755); err != nil {
				log.Printf("[backup] 创建目录失败 %s: %v", relPath, err)
				continue
			}

			if err := os.WriteFile(absPath, content, 0644); err != nil {
				log.Printf("[backup] 写入文件失败 %s: %v", relPath, err)
				continue
			}
			writtenFiles++
		}
	}

	// PHP 原版：返回数字字段 {settings:N, groups:N, items:N, files:N, orphan_items:N}
	Ok(c, gin.H{
		"settings":     len(payload.Settings),
		"groups":       len(payload.Groups),
		"items":        len(payload.Items),
		"files":        writtenFiles,
		"orphan_items": 0,
	})
}

func (h *BackupHandler) Reset(c *gin.Context) {
	val, _ := c.Get("session")
	sess, _ := val.(*auth.Session)
	if sess == nil {
		Fail(c, http.StatusUnauthorized, "未登录")
		return
	}
	if sess.Role != "admin" {
		FailMsg(c, "仅管理员可执行重置")
		return
	}

	tx := db.DB.Begin()
	if tx.Error != nil {
		Fail(c, http.StatusInternalServerError, "开启事务失败")
		return
	}

	if err := tx.Exec("DELETE FROM items").Error; err != nil {
		tx.Rollback()
		Fail(c, http.StatusInternalServerError, "清空卡片失败")
		return
	}
	if err := tx.Exec("DELETE FROM item_groups").Error; err != nil {
		tx.Rollback()
		Fail(c, http.StatusInternalServerError, "清空分组失败")
		return
	}
	if err := tx.Exec("DELETE FROM settings").Error; err != nil {
		tx.Rollback()
		Fail(c, http.StatusInternalServerError, "清空设置失败")
		return
	}

	defaults := map[string]string{
		"site_title":         "SolarPanel",
		"site_logo":          "",
		"wallpaper":          "",
		"mask_opacity":       "0.35",
		"wallpaper_blur":     "6",
		"announcement":       "欢迎使用 SolarPanel！所有展示内容均可在后台设置。",
		"announcement_show":  "0",
		"footer":             "Powered by SolarPanel",
		"clock_show":         "1",
		"default_theme":      "dark",
		"theme_style":        "soft",
		"card_style":         "detail",
		"default_lan_mode":   "public",
		"site_url":           "",
		"content_maxwidth":   "1200",
		"content_pad_lr":     "20",
		"content_pad_top":    "0",
		"content_pad_bottom": "40",
		"weather_show":       "1",
		"weather_city":       "",
		"search_width":       "640",
		"home_view":          "both",
		"news_sources":       "",
		"news_order":         "",
		"search_engines":     `[{"name":"百度","url":"https://www.baidu.com/s?wd=%s"},{"name":"Google","url":"https://www.google.com/search?q=%s"},{"name":"Bing","url":"https://www.bing.com/search?q=%s"},{"name":"DuckDuckGo","url":"https://duckduckgo.com/?q=%s"},{"name":"Yandex","url":"https://yandex.com/search/?text=%s"},{"name":"GitHub","url":"https://github.com/search?q=%s"},{"name":"搜狗","url":"https://www.sogou.com/web?query=%s"},{"name":"360搜索","url":"https://www.so.com/s?q=%s"},{"name":"神马","url":"https://m.sm.cn/s?q=%s"},{"name":"夸克","url":"https://www.quark.cn/s?q=%s"},{"name":"头条搜索","url":"https://so.toutiao.com/search?keyword=%s"},{"name":"中国搜索","url":"https://www.chinaso.com/search/all?q=%s"},{"name":"抖音","url":"https://www.douyin.com/search/%s"}]`,
		"search_default":     "百度",
		// —— 补齐与 install.php / init.sql 一致的 9 项设置键 ——
		"icp_show":           "0",
		"icp_number":         "",
		"icp_link":           "",
		"police_show":        "0",
		"police_number":      "",
		"police_link":        "",
		"wallpaper_source":      "",
		"guest_access_enabled":  "0",
		"guest_password_hash":   "",
		"search_bar_enabled":    "1",
		"card_filter_enabled":   "1",
	}
	for k, v := range defaults {
		tx.Create(&model.Setting{ConfigName: k, ConfigValue: v})
	}

	g := model.ItemGroup{Title: "常用推荐", Description: "点击卡片即可跳转，可在后台管理", Sort: 0, IsVisible: 1, UserID: 1}
	tx.Create(&g)

	items := []model.Item{
		{GroupID: g.ID, Title: "GitHub", URL: "https://github.com", Description: "全球最大代码托管平台", IconType: "favicon", OpenMethod: 2, Sort: 0},
		{GroupID: g.ID, Title: "哔哩哔哩", URL: "https://www.bilibili.com", Description: "视频弹幕网站", IconType: "favicon", OpenMethod: 2, Sort: 1},
		{GroupID: g.ID, Title: "Docker Hub", URL: "https://hub.docker.com", Description: "容器镜像仓库", IconType: "favicon", OpenMethod: 2, Sort: 2},
	}
	for _, it := range items {
		tx.Create(&it)
	}

	if err := tx.Commit().Error; err != nil {
		Fail(c, http.StatusInternalServerError, "重置失败")
		return
	}

	h.clearUploadDirPreserveWeather()

	Ok(c, nil)
}

func (h *BackupHandler) clearUploadDirPreserveWeather() {
	entries, err := os.ReadDir(h.cfg.UploadDir)
	if err != nil {
		log.Printf("[backup] 读取上传目录失败: %v", err)
		return
	}
	for _, e := range entries {
		name := e.Name()
		if name == "weather" {
			continue
		}
		fullPath := filepath.Join(h.cfg.UploadDir, name)
		if err := os.RemoveAll(fullPath); err != nil {
			log.Printf("[backup] 删除 %s 失败: %v", fullPath, err)
		}
	}
}
