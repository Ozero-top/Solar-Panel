package handler

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"time"

	"solarpanel/internal/config"

	"github.com/gin-gonic/gin"
)

// WallpaperHandler 每日壁纸（Bing）
type WallpaperHandler struct {
	cfg *config.Config
}

func NewWallpaperHandler(cfg *config.Config) *WallpaperHandler {
	return &WallpaperHandler{cfg: cfg}
}

// BingImageArchive Bing API 返回结构（简化）
type bingImageArchive struct {
	Images []struct {
		URLBase string `json:"urlbase"`
		Title   string `json:"title"`
		Copyright string `json:"copyright"`
	} `json:"images"`
}

// Dispatch 路由分发（当前仅 daily）
func (h *WallpaperHandler) Dispatch(c *gin.Context) {
	action := c.DefaultPostForm("action", c.Query("action"))
	switch action {
	case "daily", "":
		h.Daily(c)
	default:
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "unknown action: " + action})
	}
}

// Daily 获取 Bing 每日壁纸（缓存命中零回源，失败回退旧缓存）
func (h *WallpaperHandler) Daily(c *gin.Context) {
	// 先从 settings 读 wallpaper_source，如果为空则说明每日壁纸禁用
	// 但 wallpaper.php 是公开接口，前端自己会读 settings 决定是否调用
	// 这里始终返回 Bing 壁纸（让前端自由选择）

	cacheDir := filepath.Join(h.cfg.UploadDir, "daily_wallpaper")
	os.MkdirAll(cacheDir, 0755)

	today := time.Now().Format("2006-01-02")
	cacheFile := filepath.Join(cacheDir, fmt.Sprintf("bing_%s.json", today))
	imageFile := filepath.Join(cacheDir, fmt.Sprintf("bing_%s.jpg", today))

	// 1. 当日缓存命中 → 零回源
	if data, err := os.ReadFile(cacheFile); err == nil {
		var meta map[string]interface{}
		if json.Unmarshal(data, &meta) == nil {
			// 检查图片文件也存在
			if _, err := os.Stat(imageFile); err == nil {
				meta["cache"] = "hit"
				// 返回 URL 路径（PHP 原版用 frontend/uploads，Go 版用 /uploads）
				imgURL := fmt.Sprintf("/uploads/daily_wallpaper/bing_%s.jpg", today)
				meta["url"] = imgURL
				c.JSON(http.StatusOK, gin.H{"code": 0, "data": meta})
				go h.cleanupOldCache(cacheDir) // 异步清理
				return
			}
		}
	}

	// 2. 回退：找最近一次缓存
	if fallback := h.findLatestCache(cacheDir); fallback != "" {
		if data, err := os.ReadFile(fallback + ".json"); err == nil {
			var meta map[string]interface{}
			if json.Unmarshal(data, &meta) == nil {
				meta["cache"] = "fallback"
				// 从文件名提取日期
				re := regexp.MustCompile(`bing_(\d{4}-\d{2}-\d{2})\.json`)
				m := re.FindStringSubmatch(fallback)
				date := today
				if len(m) > 1 {
					date = m[1]
				}
				imgURL := fmt.Sprintf("/uploads/daily_wallpaper/bing_%s.jpg", date)
				meta["url"] = imgURL
				c.JSON(http.StatusOK, gin.H{"code": 0, "data": meta})
				go h.cleanupOldCache(cacheDir)
				return
			}
		}
	}

	// 3. 回源 Bing
	bingURL := "https://cn.bing.com/HPImageArchive.aspx?format=js&idx=0&n=1&mkt=zh-CN"
	client := &http.Client{Timeout: 10 * time.Second}
	resp, err := client.Get(bingURL)
	if err != nil {
		c.JSON(http.StatusBadGateway, gin.H{"code": 1, "msg": "获取 Bing 壁纸失败: " + err.Error()})
		return
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		c.JSON(http.StatusBadGateway, gin.H{"code": 1, "msg": "读取 Bing 响应失败"})
		return
	}

	var archive bingImageArchive
	if err := json.Unmarshal(body, &archive); err != nil || len(archive.Images) == 0 {
		c.JSON(http.StatusBadGateway, gin.H{"code": 1, "msg": "Bing 返回格式异常"})
		return
	}

	img := archive.Images[0]
	if img.URLBase == "" {
		c.JSON(http.StatusBadGateway, gin.H{"code": 1, "msg": "Bing 无图片"})
		return
	}

	// Bing 返回的是 URLBase（不含扩展名），拼上 _1920x1080.jpg
	imgFullURL := "https://cn.bing.com" + img.URLBase + "_1920x1080.jpg"

	// 下载图片
	imgResp, err := client.Get(imgFullURL)
	if err != nil {
		c.JSON(http.StatusBadGateway, gin.H{"code": 1, "msg": "下载壁纸图片失败"})
		return
	}
	defer imgResp.Body.Close()

	imgData, err := io.ReadAll(imgResp.Body)
	if err != nil {
		c.JSON(http.StatusBadGateway, gin.H{"code": 1, "msg": "读取壁纸图片失败"})
		return
	}

	if err := os.WriteFile(imageFile, imgData, 0644); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"code": 1, "msg": "保存壁纸失败"})
		return
	}

	// 写元数据
	meta := map[string]interface{}{
		"title":     img.Title,
		"copyright": img.Copyright,
		"date":      today,
		"source":    "bing",
		"cache":     "miss",
		"url":       fmt.Sprintf("/uploads/daily_wallpaper/bing_%s.jpg", today),
	}
	metaBytes, _ := json.Marshal(meta)
	os.WriteFile(cacheFile, metaBytes, 0644)

	go h.cleanupOldCache(cacheDir)
	c.JSON(http.StatusOK, gin.H{"code": 0, "data": meta})
}

// findLatestCache 找最近一次缓存（非今日），返回不带扩展名的 base
func (h *WallpaperHandler) findLatestCache(cacheDir string) string {
	entries, err := filepath.Glob(filepath.Join(cacheDir, "bing_*.json"))
	if err != nil || len(entries) == 0 {
		return ""
	}
	sort.Sort(sort.Reverse(sort.StringSlice(entries)))
	for _, e := range entries {
		if _, err := os.Stat(e[:len(e)-5] + ".jpg"); err == nil {
			return e[:len(e)-5] // 去掉 .json
		}
	}
	return ""
}

// cleanupOldCache 清理 3 天前的缓存
func (h *WallpaperHandler) cleanupOldCache(cacheDir string) {
	cutoff := time.Now().AddDate(0, 0, -3).Format("2006-01-02")
	entries, _ := os.ReadDir(cacheDir)
	for _, e := range entries {
		name := e.Name()
		// bing_2025-09-08.json / .jpg
		re := regexp.MustCompile(`bing_(\d{4}-\d{2}-\d{2})`)
		m := re.FindStringSubmatch(name)
		if len(m) < 2 {
			continue
		}
		if m[1] < cutoff {
			os.Remove(filepath.Join(cacheDir, name))
		}
	}
}
