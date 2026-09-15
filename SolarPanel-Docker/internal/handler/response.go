package handler

import (
	"net/http"

	"github.com/gin-gonic/gin"
)

// AppVersion 当前应用版本号（由 main.go 从内嵌 api.js 提取后注入）
var AppVersion = ""

// Ok 成功响应
func Ok(c *gin.Context, data interface{}) {
	c.JSON(http.StatusOK, gin.H{"code": 0, "data": data, "msg": "ok"})
}

// Fail 失败响应
func Fail(c *gin.Context, status int, msg string) {
	c.JSON(status, gin.H{"code": 1, "data": nil, "msg": msg})
}

// FailMsg 业务失败（HTTP 200，code != 0）
func FailMsg(c *gin.Context, msg string) {
	c.JSON(http.StatusOK, gin.H{"code": 1, "data": nil, "msg": msg})
}
