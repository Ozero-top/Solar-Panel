package handler

import (
	"net/http"

	"solarpanel/internal/auth"
	"solarpanel/internal/db"
	"solarpanel/internal/model"

	"github.com/gin-gonic/gin"
)

type PublicHandler struct{}

func NewPublicHandler() *PublicHandler { return &PublicHandler{} }

type GroupWithItems struct {
	model.ItemGroup
	Items []model.Item `json:"items"`
}

func (h *PublicHandler) Public(c *gin.Context) {
	var settingsRows []model.Setting
	if err := db.DB.Find(&settingsRows).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "读取设置失败")
		return
	}
	settings := make(map[string]string, len(settingsRows))
	for _, s := range settingsRows {
		settings[s.ConfigName] = s.ConfigValue
	}

	isAdmin := false
	var userInfo gin.H = nil
	guestRequired := false
	val, ok := c.Get("session")
	if ok {
		if sess, ok := val.(*auth.Session); ok {
			if sess.Role == "admin" || sess.Role == "editor" || sess.Role == "viewer" {
				isAdmin = true // 非管理员也可以看隐藏分组（viewer 只读但可见全部）
			}
			// 访客（guest 角色）也认为已登录过访客密码
			if sess.Role != "guest" {
				var u model.User
				if err := db.DB.First(&u, sess.UID).Error; err == nil {
					userInfo = gin.H{
						"username": u.Username,
						"name":     u.Name,
						"role":     u.Role,
					}
				}
			} else {
				userInfo = gin.H{"role": "guest"}
			}
		}
	} else {
		// 未登录 — 检查访客密码是否开启
		if settings["guest_access_enabled"] == "1" {
			guestRequired = true
		}
	}

	// 访客密码未验证：不返回任何分组/卡片数据，彻底隐藏前端信息
	if guestRequired {
		Ok(c, gin.H{
			"settings":       settings,
			"groups":         []GroupWithItems{},
			"user":           nil,
			"guest_required": true,
		})
		return
	}

	q := db.DB.Order("sort asc, id asc")
	if !isAdmin {
		q = q.Where("is_visible = ?", 1)
	}
	var groups []model.ItemGroup
	if err := q.Find(&groups).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "读取分组失败")
		return
	}

	var items []model.Item
	if err := db.DB.Order("sort asc, id asc").Find(&items).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "读取卡片失败")
		return
	}

	grouped := make([]GroupWithItems, 0, len(groups))
	for _, g := range groups {
		gw := GroupWithItems{ItemGroup: g, Items: []model.Item{}}
		for _, it := range items {
			if it.GroupID == g.ID {
				gw.Items = append(gw.Items, it)
			}
		}
		grouped = append(grouped, gw)
	}

	Ok(c, gin.H{
		"settings":       settings,
		"groups":         grouped,
		"user":           userInfo,
		"guest_required": guestRequired,
	})
}
