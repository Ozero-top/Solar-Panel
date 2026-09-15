package handler

import (
	"net/http"
	"strconv"

	"solarpanel/internal/auth"
	"solarpanel/internal/db"
	"solarpanel/internal/model"

	"github.com/gin-gonic/gin"
	"golang.org/x/crypto/bcrypt"
)

type SettingsHandler struct{}

func NewSettingsHandler() *SettingsHandler { return &SettingsHandler{} }

var allowedSettingKeys = map[string]struct{}{
	"site_title":         {},
	"site_logo":          {},
	"wallpaper":          {},
	"mask_opacity":       {},
	"wallpaper_blur":     {},
	"announcement":       {},
	"announcement_show":  {},
	"footer":             {},
	"clock_show":         {},
	"default_theme":      {},
	"theme_style":        {},
	"card_style":         {},
	"default_lan_mode":   {},
	"search_engines":     {},
	"search_default":     {},
	"site_url":           {},
	"content_maxwidth":   {},
	"content_pad_lr":     {},
	"content_pad_top":    {},
	"content_pad_bottom": {},
	"weather_show":       {},
	"weather_city":       {},
	"search_width":       {},
	"home_view":          {},
	"news_sources":       {},
	"news_order":         {},
	"icp_show":           {},
	"icp_number":         {},
	"icp_link":           {},
	"police_show":        {},
	"police_number":      {},
	"police_link":        {},
	"wallpaper_source":      {},
	"guest_access_enabled":  {},
	"guest_password_hash":   {},
	"search_bar_enabled":    {},
	"card_filter_enabled":   {},
}

func (h *SettingsHandler) Dispatch(c *gin.Context) {
	action := c.Query("action")
	method := c.Request.Method

	if method == "POST" {
		switch action {
		case "save":
			h.Save(c)
			return
		}
	}

	if action == "list" || method == "GET" {
		h.List(c)
		return
	}

	FailMsg(c, "未知操作")
}

func (h *SettingsHandler) List(c *gin.Context) {
	var rows []model.Setting
	if err := db.DB.Find(&rows).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "读取设置失败")
		return
	}
	m := make(map[string]string, len(rows))
	for _, r := range rows {
		m[r.ConfigName] = r.ConfigValue
	}
	Ok(c, m)
}

func (h *SettingsHandler) Save(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || sess.Role != "admin" {
		Fail(c, http.StatusForbidden, "仅管理员可修改站点设置")
		return
	}

	var body map[string]string
	if err := c.ShouldBindJSON(&body); err != nil {
		FailMsg(c, "参数错误")
		return
	}

	saved := 0
	for k, v := range body {
		if _, ok := allowedSettingKeys[k]; !ok {
			continue
		}
		// 前端传明文（留空表示不改）；后端 bcrypt hash 后存 settings.guest_password_hash
		if k == "guest_password_hash" {
			if v == "" {
				continue // 留空 = 不改
			}
			hashed, err := bcrypt.GenerateFromPassword([]byte(v), bcrypt.DefaultCost)
			if err != nil {
				continue // hash 失败跳过，不影响其他设置
			}
			v = string(hashed)
		}
		var existing model.Setting
		err := db.DB.Where("config_name = ?", k).First(&existing).Error
		if err == nil {
			existing.ConfigValue = v
			if db.DB.Save(&existing).Error == nil {
				saved++
			}
		} else {
			if db.DB.Create(&model.Setting{ConfigName: k, ConfigValue: v}).Error == nil {
				saved++
			}
		}
	}

	Ok(c, gin.H{"saved": saved})

	// —— 审计日志 ——
	if saved > 0 {
		auth.AuditLog(c.Writer, c.Request, "settings.save",
			"保存 "+strconv.Itoa(saved)+" 项", "success",
			usernameByUID(sess.UID), sess.Role, "")
	}
}
