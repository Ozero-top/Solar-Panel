package handler

import (
	"net/http"
	"strings"

	"solarpanel/internal/auth"
	"solarpanel/internal/db"
	"solarpanel/internal/model"

	"github.com/gin-gonic/gin"
	"golang.org/x/crypto/bcrypt"
)

type UserHandler struct{}

func NewUserHandler() *UserHandler { return &UserHandler{} }

// 角色白名单：admin 管理员 / editor 编辑者 / viewer 只读 / guest 访客（只读）
var validRoles = map[string]bool{
	"admin":  true,
	"editor": true,
	"viewer": true,
	"guest":  true,
}

func isValidRole(role string) bool { return validRoles[role] }

func getSession(c *gin.Context) *auth.Session {
	val, ok := c.Get("session")
	if !ok {
		return nil
	}
	sess, _ := val.(*auth.Session)
	return sess
}

func (h *UserHandler) Dispatch(c *gin.Context) {
	method := c.Request.Method
	action := c.Query("action")

	if method == "GET" {
		if action == "list" || action == "" {
			h.List(c)
			return
		}
		FailMsg(c, "未知操作")
		return
	}

	if method == "POST" {
		switch action {
		case "create":
			h.Create(c)
		case "update":
			h.Update(c)
		case "delete":
			h.Delete(c)
		case "change_password":
			h.ChangePassword(c)
		case "set_role":
			h.SetRole(c)
		case "set_password":
			h.SetPassword(c)
		default:
			FailMsg(c, "未知操作")
		}
		return
	}

	FailMsg(c, "不支持的请求方法")
}

func (h *UserHandler) List(c *gin.Context) {
	sess := getSession(c)
	if sess == nil {
		Fail(c, http.StatusUnauthorized, "未登录")
		return
	}
	// PHP 原版 require_roles('admin')：仅管理员可查看用户列表
	if sess.Role != "admin" {
		FailMsg(c, "权限不足")
		return
	}

	var users []model.User
	if err := db.DB.Find(&users).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "读取用户失败")
		return
	}

	out := make([]gin.H, 0, len(users))
	for _, u := range users {
		out = append(out, gin.H{
			"id":       u.ID,
			"username": u.Username,
			"name":     u.Name,
			"status":   u.Status,
			"role":     u.Role,
		})
	}
	// PHP 原版结构：{users: [...], current_id: int}
	Ok(c, gin.H{
		"users":      out,
		"current_id": sess.UID,
	})
}

func (h *UserHandler) Create(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || sess.Role != "admin" {
		Fail(c, http.StatusForbidden, "仅管理员可创建用户")
		return
	}

	var body struct {
		Username string `json:"username"`
		Password string `json:"password"`
		Name     string `json:"name"`
		Role     string `json:"role"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		FailMsg(c, "参数错误")
		return
	}
	body.Username = strings.TrimSpace(body.Username)
	body.Password = strings.TrimSpace(body.Password)
	body.Name = strings.TrimSpace(body.Name)
	body.Role = strings.TrimSpace(body.Role)
	if body.Username == "" || body.Password == "" {
		FailMsg(c, "用户名和密码不能为空")
		return
	}
	// 密码长度 6~64（PHP: strlen < 6 || > 64）
	if len(body.Password) < 6 || len(body.Password) > 64 {
		FailMsg(c, "密码至少 6 位，至多 64 位")
		return
	}
	// 默认角色 admin（PHP: param('role', 'admin')）
	if body.Role == "" {
		body.Role = "admin"
	}
	if !isValidRole(body.Role) {
		FailMsg(c, "权限组不合法")
		return
	}
	// name 为空时回退为 username（PHP: $name !== '' ? $name : $username）
	if body.Name == "" {
		body.Name = body.Username
	}

	var count int64
	db.DB.Model(&model.User{}).Where("username = ?", body.Username).Count(&count)
	if count > 0 {
		FailMsg(c, "用户名已存在")
		return
	}

	hashed, err := bcrypt.GenerateFromPassword([]byte(body.Password), bcrypt.DefaultCost)
	if err != nil {
		Fail(c, http.StatusInternalServerError, "加密密码失败")
		return
	}

	u := model.User{
		Username: body.Username,
		Password: string(hashed),
		Name:     body.Name,
		Status:   1,
		Role:     body.Role,
	}
	if err := db.DB.Create(&u).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "创建用户失败")
		return
	}

	Ok(c, gin.H{"id": u.ID})
}

func (h *UserHandler) Update(c *gin.Context) {
	sess := getSession(c)
	if sess == nil {
		Fail(c, http.StatusUnauthorized, "未登录")
		return
	}
	// 只读账号不可修改显示名称（前端已禁用输入，后端强拦截）
	if sess.Role == "viewer" {
		Fail(c, http.StatusForbidden, "只读账号无权修改显示名称")
		return
	}

	var body struct {
		ID     uint   `json:"id"`
		Name   string `json:"name"`
		Status int    `json:"status"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		FailMsg(c, "参数错误")
		return
	}

	// PHP 原版 update 只改自己（用当前 session uid），不管前端传不传 id
	targetID := sess.UID
	// 管理员如果显式传了 id，也可以改别人
	if body.ID != 0 && body.ID != sess.UID && sess.Role == "admin" {
		targetID = body.ID
	}

	var target model.User
	if err := db.DB.First(&target, targetID).Error; err != nil {
		FailMsg(c, "用户不存在")
		return
	}

	// 管理员可以改 status，非管理员只允许改自己的 name
	if sess.Role == "admin" && body.Status != 0 {
		target.Status = body.Status
	}
	if body.Name != "" {
		target.Name = body.Name
	}

	if err := db.DB.Save(&target).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "更新用户失败")
		return
	}

	Ok(c, gin.H{"name": target.Name})
}

func (h *UserHandler) Delete(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || sess.Role != "admin" {
		Fail(c, http.StatusForbidden, "仅管理员可删除用户")
		return
	}

	var body struct {
		ID uint `json:"id"`
	}
	if err := c.ShouldBindJSON(&body); err != nil || body.ID == 0 {
		FailMsg(c, "参数错误")
		return
	}

	var target model.User
	if err := db.DB.First(&target, body.ID).Error; err != nil {
		FailMsg(c, "用户不存在")
		return
	}
	if target.ID == sess.UID {
		FailMsg(c, "不能删除自己")
		return
	}
	// PHP 原版：至少保留一个用户
	var totalCount int64
	db.DB.Model(&model.User{}).Count(&totalCount)
	if totalCount <= 1 {
		FailMsg(c, "至少需要保留一个用户")
		return
	}
	if target.Role == "admin" {
		var adminCount int64
		db.DB.Model(&model.User{}).Where("role = ?", "admin").Count(&adminCount)
		if adminCount <= 1 {
			FailMsg(c, "至少保留一个管理员账号")
			return
		}
	}

	if err := db.DB.Delete(&target).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "删除用户失败")
		return
	}
	Ok(c, nil)
}

func (h *UserHandler) ChangePassword(c *gin.Context) {
	sess := getSession(c)
	if sess == nil {
		Fail(c, http.StatusUnauthorized, "未登录")
		return
	}

	var body struct {
		OldPassword string `json:"old_password"`
		NewPassword string `json:"new_password"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		FailMsg(c, "参数错误")
		return
	}
	if body.OldPassword == "" || body.NewPassword == "" {
		FailMsg(c, "旧密码和新密码不能为空")
		return
	}
	// 密码长度 6~64（PHP: mb_strlen < 6 || > 64）
	if len(body.NewPassword) < 6 || len(body.NewPassword) > 64 {
		FailMsg(c, "新密码至少 6 位，至多 64 位")
		return
	}

	var u model.User
	if err := db.DB.First(&u, sess.UID).Error; err != nil {
		FailMsg(c, "用户不存在")
		return
	}
	// PHP 原版：只读账号不可修改密码
	if u.Role == "viewer" {
		Fail(c, http.StatusForbidden, "只读账号无权修改密码")
		return
	}
	if err := bcrypt.CompareHashAndPassword([]byte(u.Password), []byte(body.OldPassword)); err != nil {
		FailMsg(c, "旧密码错误")
		return
	}

	hashed, err := bcrypt.GenerateFromPassword([]byte(body.NewPassword), bcrypt.DefaultCost)
	if err != nil {
		Fail(c, http.StatusInternalServerError, "加密密码失败")
		return
	}
	u.Password = string(hashed)
	if err := db.DB.Save(&u).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "更新密码失败")
		return
	}
	Ok(c, nil)
}

func (h *UserHandler) SetRole(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || sess.Role != "admin" {
		Fail(c, http.StatusForbidden, "仅管理员可修改角色")
		return
	}

	var body struct {
		ID   uint   `json:"id"`
		Role string `json:"role"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		FailMsg(c, "参数错误")
		return
	}
	if !isValidRole(body.Role) {
		FailMsg(c, "权限组不合法")
		return
	}
	if body.ID == sess.UID && body.Role != "admin" {
		FailMsg(c, "不能修改当前登录账号的权限组")
		return
	}

	var u model.User
	if err := db.DB.First(&u, body.ID).Error; err != nil {
		FailMsg(c, "用户不存在")
		return
	}
	if u.Role == "admin" && body.Role != "admin" {
		var adminCount int64
		db.DB.Model(&model.User{}).Where("role = ?", "admin").Count(&adminCount)
		if adminCount <= 1 {
			FailMsg(c, "至少保留一个管理员")
			return
		}
	}

	u.Role = body.Role
	if err := db.DB.Save(&u).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "更新角色失败")
		return
	}
	Ok(c, nil)
}

func (h *UserHandler) SetPassword(c *gin.Context) {
	sess := getSession(c)
	if sess == nil {
		Fail(c, http.StatusUnauthorized, "未登录")
		return
	}

	var body struct {
		ID       uint   `json:"id"`
		Password string `json:"password"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		FailMsg(c, "参数错误")
		return
	}
	// 密码长度 6~64（PHP: strlen < 6 || > 64）
	if len(body.Password) < 6 || len(body.Password) > 64 {
		FailMsg(c, "新密码至少 6 位，至多 64 位")
		return
	}

	var target model.User
	if err := db.DB.First(&target, body.ID).Error; err != nil {
		FailMsg(c, "用户不存在")
		return
	}

	// PHP 原版 require_roles('admin')：仅管理员可重置密码
	if sess.Role != "admin" {
		FailMsg(c, "仅管理员可重置密码")
		return
	}

	hashed, err := bcrypt.GenerateFromPassword([]byte(body.Password), bcrypt.DefaultCost)
	if err != nil {
		Fail(c, http.StatusInternalServerError, "加密密码失败")
		return
	}
	target.Password = string(hashed)
	if err := db.DB.Save(&target).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "更新密码失败")
		return
	}
	Ok(c, nil)
}
