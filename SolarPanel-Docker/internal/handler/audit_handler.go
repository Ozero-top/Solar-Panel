package handler

import (
	"net/http"
	"strconv"

	"solarpanel/internal/db"
	"solarpanel/internal/model"

	"github.com/gin-gonic/gin"
)

// AuditHandler 审计日志接口
type AuditHandler struct{}

func NewAuditHandler() *AuditHandler { return &AuditHandler{} }

// Dispatch 路由分发（JSON body / query / form 都支持）
func (h *AuditHandler) Dispatch(c *gin.Context) {
	action := c.DefaultPostForm("action", c.Query("action"))
	switch action {
	case "list", "":
		h.List(c)
	case "clear":
		h.Clear(c)
	default:
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "unknown action: " + action})
	}
}

// List 分页查询审计日志（仅 admin 可见）
func (h *AuditHandler) List(c *gin.Context) {
	// 权限检查：只有 admin 可以查审计
	sess := getSession(c)
	if sess == nil || sess.Role != "admin" {
		c.JSON(http.StatusForbidden, gin.H{"code": 403, "msg": "仅管理员可查看审计日志"})
		return
	}

	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	pageSize, _ := strconv.Atoi(c.DefaultQuery("pagesize", "50"))
	if pageSize <= 0 || pageSize > 500 {
		pageSize = 50
	}
	if page < 1 {
		page = 1
	}

	var total int64
	db.DB.Model(&model.AuditLog{}).Count(&total)

	var logs []model.AuditLog
	offset := (page - 1) * pageSize
	db.DB.Order("created_at DESC").Offset(offset).Limit(pageSize).Find(&logs)

	c.JSON(http.StatusOK, gin.H{
		"code": 0,
		"data": gin.H{
			"total":    total,
			"page":     page,
			"pagesize": pageSize,
			"list":     logs,
		},
	})
}

// Clear 清空审计日志
func (h *AuditHandler) Clear(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || sess.Role != "admin" {
		c.JSON(http.StatusForbidden, gin.H{"code": 403, "msg": "仅管理员可清空"})
		return
	}

	db.DB.Exec("DELETE FROM audit_logs")
	c.JSON(http.StatusOK, gin.H{"code": 0, "msg": "已清空全部审计日志"})
}
