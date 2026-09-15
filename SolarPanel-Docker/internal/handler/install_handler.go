package handler

import (
	"net/http"

	"solarpanel/internal/db"
	"solarpanel/internal/model"

	"github.com/gin-gonic/gin"
)

// InstallHandler 检查数据库是否已初始化
type InstallHandler struct{}

// Status 返回安装状态：installed=true 表示已有管理员账号
func (InstallHandler) Status(c *gin.Context) {
	var count int64
	db.DB.Model(&model.User{}).Count(&count)
	Ok(c, gin.H{
		"installed":   count > 0,
		"app_version": AppVersion,
		"db_engine":   "SQLite",
		"php_rewrite": "1", // 标识这是 Go 重写版
	})
}

// Setup 手动触发安装（仅用于手动重置后）
func (InstallHandler) Setup(c *gin.Context) {
	var count int64
	db.DB.Model(&model.User{}).Count(&count)
	if count > 0 {
		c.JSON(http.StatusConflict, gin.H{"code": 1, "data": nil, "msg": "已安装，如要重置请使用 /api/backup.php?action=reset"})
		return
	}
	Ok(c, gin.H{"ok": true, "msg": "安装已触发，默认管理员 admin / admin123"})
}
