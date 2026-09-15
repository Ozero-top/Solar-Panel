package handler

import (
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"solarpanel/internal/config"

	"github.com/gin-gonic/gin"
)

type GalleryHandler struct {
	cfg *config.Config
}

func NewGalleryHandler(cfg *config.Config) *GalleryHandler {
	return &GalleryHandler{cfg: cfg}
}

var galleryAllowedExt = map[string]bool{
	"jpg":  true,
	"jpeg": true,
	"png":  true,
	"gif":  true,
	"webp": true,
	"bmp":  true,
	"svg":  true,
}

type galleryItem struct {
	Name   string    `json:"name"`
	URL    string    `json:"url"`
	Size   int64     `json:"size"`
	Mtime  time.Time `json:"mtime"`
	Preset bool      `json:"preset"`
}

func (h *GalleryHandler) Dispatch(c *gin.Context) {
	method := c.Request.Method
	action := c.Query("action")

	if method == "GET" {
		h.List(c)
		return
	}

	if method == "POST" {
		if action == "delete" {
			h.Delete(c)
			return
		}
		FailMsg(c, "未知操作")
		return
	}

	FailMsg(c, "不支持的请求方法")
}

func galleryScan(dir, webPrefix string, preset bool) []galleryItem {
	var out []galleryItem
	entries, err := os.ReadDir(dir)
	if err != nil {
		return out
	}
	for _, e := range entries {
		if e.IsDir() {
			continue
		}
		name := e.Name()
		ext := strings.ToLower(filepath.Ext(name))
		ext = strings.TrimPrefix(ext, ".")
		if !galleryAllowedExt[ext] {
			continue
		}
		full := filepath.Join(dir, name)
		info, err := os.Stat(full)
		if err != nil {
			continue
		}
		out = append(out, galleryItem{
			Name:   name,
			URL:    webPrefix + name,
			Size:   info.Size(),
			Mtime:  info.ModTime(),
			Preset: preset,
		})
	}
	return out
}

func (h *GalleryHandler) List(c *gin.Context) {
	userDir := filepath.Join(h.cfg.UploadDir, "wallpapers")
	presetDir := filepath.Join(h.cfg.UploadDir, "weather")
	userWebBase := "/frontend/uploads/wallpapers/"
	presetWebBase := "/frontend/uploads/weather/"

	items := galleryScan(userDir, userWebBase, false)

	sort.SliceStable(items, func(i, j int) bool {
		return items[i].Mtime.After(items[j].Mtime)
	})

	presets := galleryScan(presetDir, presetWebBase, true)
	sort.SliceStable(presets, func(i, j int) bool {
		return strings.ToLower(presets[i].Name) < strings.ToLower(presets[j].Name)
	})

	items = append(items, presets...)

	if items == nil {
		Ok(c, []galleryItem{})
		return
	}
	Ok(c, items)
}

func (h *GalleryHandler) Delete(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || (sess.Role != "admin" && sess.Role != "editor") {
		Fail(c, http.StatusForbidden, "无操作权限")
		return
	}
	var body struct {
		URL string `json:"url"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		FailMsg(c, "参数错误")
		return
	}
	if body.URL == "" {
		FailMsg(c, "缺少壁纸地址")
		return
	}

	const presetPrefix = "/frontend/uploads/weather/"
	if strings.HasPrefix(body.URL, presetPrefix) {
		FailMsg(c, "该壁纸为系统预置，不可删除")
		return
	}

	const prefix = "/frontend/uploads/wallpapers/"
	if !strings.HasPrefix(body.URL, prefix) {
		FailMsg(c, "非法的壁纸地址")
		return
	}

	name := strings.TrimPrefix(body.URL, prefix)
	name = filepath.Base(strings.ReplaceAll(name, "\\", "/"))
	if name == "" || name == "." || name == ".." || strings.Contains(name, "/") {
		FailMsg(c, "非法的壁纸地址")
		return
	}
	ext := strings.ToLower(strings.TrimPrefix(filepath.Ext(name), "."))
	if !galleryAllowedExt[ext] {
		FailMsg(c, "非法的壁纸地址")
		return
	}

	target := filepath.Join(h.cfg.UploadDir, "wallpapers", name)
	absTarget, _ := filepath.Abs(target)
	absWallpapers, _ := filepath.Abs(filepath.Join(h.cfg.UploadDir, "wallpapers"))
	if !strings.HasPrefix(absTarget, absWallpapers) {
		FailMsg(c, "非法路径")
		return
	}

	if _, err := os.Stat(target); err != nil {
		if os.IsNotExist(err) {
			FailMsg(c, "壁纸不存在或已删除")
			return
		}
		Fail(c, http.StatusInternalServerError, "检查文件失败")
		return
	}

	if err := os.Remove(target); err != nil {
		Fail(c, http.StatusInternalServerError, "删除失败，请检查目录权限")
		return
	}

	Ok(c, gin.H{"deleted": name})
}
