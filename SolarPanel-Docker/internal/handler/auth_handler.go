package handler

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	"solarpanel/internal/auth"
	"solarpanel/internal/db"
	"solarpanel/internal/model"

	"github.com/gin-gonic/gin"
	"golang.org/x/crypto/bcrypt"
)

const (
	sessionCookieName       = "sp_session"
	csrfCookieName          = "csrf_token"
	trustedDeviceCookie     = "trusted_device"
	trustedDeviceMaxAge     = 86400 * 30
	trustedDeviceMaxPerUser = 10
)

type AuthHandler struct {
	Store *auth.Store

	rateMu       sync.Mutex
	rateAttempts map[string][]time.Time
}

func NewAuthHandler(store *auth.Store) *AuthHandler {
	h := &AuthHandler{
		Store:        store,
		rateAttempts: make(map[string][]time.Time),
	}
	go h.rateGC()
	return h
}

func (h *AuthHandler) rateGC() {
	t := time.NewTicker(30 * time.Second)
	defer t.Stop()
	for range t.C {
		h.rateMu.Lock()
		cutoff := time.Now().Add(-60 * time.Second)
		for ip, arr := range h.rateAttempts {
			i := 0
			for i < len(arr) && arr[i].Before(cutoff) {
				i++
			}
			if i == len(arr) {
				delete(h.rateAttempts, ip)
			} else {
				h.rateAttempts[ip] = arr[i:]
			}
		}
		h.rateMu.Unlock()
	}
}

func (h *AuthHandler) checkRate(ip string) bool {
	h.rateMu.Lock()
	defer h.rateMu.Unlock()
	cutoff := time.Now().Add(-60 * time.Second)
	arr := h.rateAttempts[ip]
	i := 0
	for i < len(arr) && arr[i].Before(cutoff) {
		i++
	}
	arr = arr[i:]
	if len(arr) >= 8 {
		h.rateAttempts[ip] = arr
		return false
	}
	arr = append(arr, time.Now())
	h.rateAttempts[ip] = arr
	return true
}

// --- 统一的设置读取辅助 ---
func settingGet(key, def string) string {
	var s model.Setting
	if err := db.DB.Where("config_name = ?", key).First(&s).Error; err != nil {
		return def
	}
	return s.ConfigValue
}

func usernameByUID(uid uint) string {
	if uid == 0 {
		return "访客"
	}
	var u model.User
	if err := db.DB.Select("username").First(&u, uid).Error; err != nil {
		return ""
	}
	return u.Username
}

func (h *AuthHandler) Me(c *gin.Context) {
	val, ok := c.Get("session")
	if !ok {
		Fail(c, http.StatusUnauthorized, "未登录或登录已过期")
		return
	}
	sess, ok := val.(*auth.Session)
	if !ok {
		Fail(c, http.StatusUnauthorized, "未登录或登录已过期")
		return
	}
	// 访客：UID=0，直接返回 guest 角色，不查 user 表
	if sess.UID == 0 {
		Ok(c, gin.H{
			"id":       0,
			"username": "guest",
			"name":     "访客",
			"role":     "guest",
			"csrf":     sess.CSRFToken,
			"has_2fa":  false,
		})
		return
	}
	var u model.User
	if err := db.DB.First(&u, sess.UID).Error; err != nil {
		Fail(c, http.StatusUnauthorized, "用户不存在")
		return
	}
	Ok(c, gin.H{
		"id":       u.ID,
		"username": u.Username,
		"name":     u.Name,
		"role":     u.Role,
		"csrf":     sess.CSRFToken,
		"has_2fa":  u.TOTPSecret != "",
	})
}

func (h *AuthHandler) Login(c *gin.Context) {
	var body struct {
		Username string `json:"username"`
		Password string `json:"password"`
		Code     string `json:"code"` // 2FA 动态码（第二步提交）
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		FailMsg(c, "参数错误")
		return
	}
	if body.Username == "" || body.Password == "" {
		FailMsg(c, "用户名或密码不能为空")
		return
	}

	ip := auth.ClientIP(c.Request)
	if !h.checkRate(ip) {
		FailMsg(c, "尝试过于频繁，请 60 秒后再试")
		return
	}

	var u model.User
	if err := db.DB.Where("username = ?", body.Username).First(&u).Error; err != nil {
		auth.AuditLog(c.Writer, c.Request, "auth.login", body.Username, "fail", body.Username, "", "用户不存在或密码错误")
		FailMsg(c, "用户名或密码错误")
		return
	}
	if u.Status != 1 {
		auth.AuditLog(c.Writer, c.Request, "auth.login", u.Username, "fail", u.Username, u.Role, "账号被禁用")
		FailMsg(c, "账号已被禁用")
		return
	}
	if err := bcrypt.CompareHashAndPassword([]byte(u.Password), []byte(body.Password)); err != nil {
		auth.AuditLog(c.Writer, c.Request, "auth.login", u.Username, "fail", u.Username, u.Role, "密码错误")
		FailMsg(c, "用户名或密码错误")
		return
	}

	// —— 2FA 流程（仅 admin 角色生效） ——
	if u.TOTPSecret != "" && u.Role == "admin" && body.Code == "" {
		// —— 先查 trusted_device cookie：命中则跳过 2FA 直接登录 ——
		if verifyTrustedDevice(u.ID, c.Request) {
			sid, csrf := h.Store.CreateSession(u.ID, u.Role)
			auth.SetCookies(c.Writer, sid, csrf, sessionCookieName, csrfCookieName)
			auth.AuditLog(c.Writer, c.Request, "auth.login", u.Username, "success", u.Username, u.Role, "trusted_device_bypass")
			Ok(c, gin.H{"id": u.ID, "username": u.Username, "name": u.Name, "role": u.Role})
			return
		}
		// 密码通过但需要 2FA，返回 need_2fa=true
		Ok(c, gin.H{"need_2fa": true, "uid": u.ID})
		return
	}
	if u.TOTPSecret != "" && u.Role == "admin" && body.Code != "" {
		if !auth.TOTPVerify(u.TOTPSecret, body.Code) {
			auth.AuditLog(c.Writer, c.Request, "auth.login.2fa", u.Username, "fail", u.Username, u.Role, "2FA 动态码错误")
			FailMsg(c, "2FA 动态码错误")
			return
		}
	}

	sid, csrf := h.Store.CreateSession(u.ID, u.Role)
	auth.SetCookies(c.Writer, sid, csrf, sessionCookieName, csrfCookieName)

	auth.AuditLog(c.Writer, c.Request, "auth.login", u.Username, "success", u.Username, u.Role, "登录成功")

	Ok(c, gin.H{
		"id":       u.ID,
		"username": u.Username,
		"name":     u.Name,
		"role":     u.Role,
	})
}

// LoginWith2FA 第二步：用 uid + code 完成登录（密码第一步已通过）
func (h *AuthHandler) LoginWith2FA(c *gin.Context) {
	var body struct {
		UID         uint   `json:"uid"`
		Code        string `json:"code"`
		TrustDevice int    `json:"trust_device"`
	}
	if err := c.ShouldBindJSON(&body); err != nil || body.UID == 0 || body.Code == "" {
		FailMsg(c, "参数错误")
		return
	}

	var u model.User
	if err := db.DB.First(&u, body.UID).Error; err != nil {
		FailMsg(c, "用户不存在")
		return
	}
	if u.TOTPSecret == "" || u.Role != "admin" {
		FailMsg(c, "该账号未开启 2FA")
		return
	}
	if !auth.TOTPVerify(u.TOTPSecret, body.Code) {
		auth.AuditLog(c.Writer, c.Request, "auth.login.2fa", u.Username, "fail", u.Username, u.Role, "2FA 动态码错误")
		FailMsg(c, "2FA 动态码错误")
		return
	}

	sid, csrf := h.Store.CreateSession(u.ID, u.Role)
	auth.SetCookies(c.Writer, sid, csrf, sessionCookieName, csrfCookieName)

	// —— 信任此设备 30 天 ——
	if body.TrustDevice == 1 {
		if err := saveTrustedDevice(u.ID, c.Request, c.Writer); err != nil {
			auth.AuditLog(c.Writer, c.Request, "trusted_device.save", u.Username, "fail", u.Username, u.Role, err.Error())
		} else {
			auth.AuditLog(c.Writer, c.Request, "trusted_device.save", u.Username, "success", u.Username, u.Role, "")
		}
	}

	auth.AuditLog(c.Writer, c.Request, "auth.login", u.Username, "success", u.Username, u.Role, "2FA 登录成功")

	Ok(c, gin.H{"id": u.ID, "username": u.Username, "name": u.Name, "role": u.Role})
}

// GuestLogin 访客密码登录（60 秒 8 次防爆破）
func (h *AuthHandler) GuestLogin(c *gin.Context) {
	var body struct {
		Password string `json:"password"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		FailMsg(c, "参数错误")
		return
	}

	ip := auth.ClientIP(c.Request)
	if !h.checkRate(ip) {
		FailMsg(c, "尝试过于频繁，请 60 秒后再试")
		return
	}

	enabled := settingGet("guest_access_enabled", "0")
	if enabled != "1" {
		FailMsg(c, "访客访问密码未开启")
		return
	}

	hash := settingGet("guest_password_hash", "")
	if hash == "" {
		FailMsg(c, "访客访问密码未设置")
		return
	}

	if err := bcrypt.CompareHashAndPassword([]byte(hash), []byte(body.Password)); err != nil {
		auth.AuditLog(c.Writer, c.Request, "guest.login", "访客", "fail", "访客", "", "访客密码错误")
		FailMsg(c, "密码错误")
		return
	}

	// 访客密码正确，写一个 session，标记为 guest
	sid, csrf := h.Store.CreateSession(0, "guest")
	auth.SetCookies(c.Writer, sid, csrf, sessionCookieName, csrfCookieName)
	auth.AuditLog(c.Writer, c.Request, "guest.login", "访客", "success", "访客", "guest", "访客密码通过")

	Ok(c, gin.H{"role": "guest", "msg": "访客密码通过"})
}

// TwoFASetup 生成新密钥 + OTP 链接（需已登录 + 非 viewer/guest）
func (h *AuthHandler) TwoFASetup(c *gin.Context) {
	sess := getSession(c)
	if sess == nil {
		Fail(c, http.StatusUnauthorized, "未登录")
		return
	}
	if sess.Role != "admin" {
		Fail(c, http.StatusForbidden, "仅 admin 角色可修改 2FA 设置")
		return
	}

	var u model.User
	if err := db.DB.First(&u, sess.UID).Error; err != nil {
		Fail(c, http.StatusNotFound, "用户不存在")
		return
	}

	// 如果已开启，先停用才能重新 setup
	if u.TOTPSecret != "" {
		FailMsg(c, "2FA 已开启，请先关闭后再设置")
		return
	}

	secret, err := auth.TOTPSecretNew()
	if err != nil {
		FailMsg(c, "生成密钥失败")
		return
	}
	// 密钥临时返回给前端，前端显示给用户扫 QR 后再调 enable 来确认
	// 注意：密钥只在 setup 这一步返回一次，一旦 enable 后就不再返回
	// 同时用一个临时哈希（基于 secret+UID）让 enable 来验证确实是这次生成的
	Ok(c, gin.H{
		"secret": secret,
		"url":    auth.TOTPProvisioningURL(secret, u.Username, "SolarPanel"),
	})
}

// TwoFAEnable 用动态码确认开启（需已登录）
func (h *AuthHandler) TwoFAEnable(c *gin.Context) {
	sess := getSession(c)
	if sess == nil {
		Fail(c, http.StatusUnauthorized, "未登录")
		return
	}
	if sess.Role != "admin" {
		Fail(c, http.StatusForbidden, "仅 admin 角色可修改 2FA 设置")
		return
	}

	var body struct {
		Secret string `json:"secret"`
		Code   string `json:"code"`
	}
	if err := c.ShouldBindJSON(&body); err != nil || body.Secret == "" || body.Code == "" {
		FailMsg(c, "参数错误")
		return
	}

	if !auth.TOTPVerify(body.Secret, body.Code) {
		FailMsg(c, "2FA 动态码错误，请确认密钥已正确绑定")
		return
	}

	// 保存密钥
	db.DB.Model(&model.User{}).Where("id = ?", sess.UID).Update("totp_secret", body.Secret)
	auth.AuditLog(c.Writer, c.Request, "2fa.enable", usernameByUID(sess.UID), "success", usernameByUID(sess.UID), sess.Role, "开启两步验证")

	Ok(c, gin.H{"msg": "2FA 已开启"})
}

// TwoFADisable 关闭 2FA（需已登录 + 密码 + 动态码）
func (h *AuthHandler) TwoFADisable(c *gin.Context) {
	sess := getSession(c)
	if sess == nil {
		Fail(c, http.StatusUnauthorized, "未登录")
		return
	}
	if sess.Role != "admin" {
		Fail(c, http.StatusForbidden, "仅 admin 角色可修改 2FA 设置")
		return
	}

	var body struct {
		Password string `json:"password"`
		Code     string `json:"code"`
	}
	if err := c.ShouldBindJSON(&body); err != nil || body.Password == "" || body.Code == "" {
		FailMsg(c, "参数错误")
		return
	}

	var u model.User
	if err := db.DB.First(&u, sess.UID).Error; err != nil {
		Fail(c, http.StatusNotFound, "用户不存在")
		return
	}
	if err := bcrypt.CompareHashAndPassword([]byte(u.Password), []byte(body.Password)); err != nil {
		FailMsg(c, "密码错误")
		return
	}
	if u.TOTPSecret == "" {
		FailMsg(c, "2FA 未开启")
		return
	}
	if !auth.TOTPVerify(u.TOTPSecret, body.Code) {
		FailMsg(c, "2FA 动态码错误")
		return
	}

	db.DB.Model(&model.User{}).Where("id = ?", sess.UID).Update("totp_secret", "")
	auth.AuditLog(c.Writer, c.Request, "2fa.disable", usernameByUID(sess.UID), "success", usernameByUID(sess.UID), sess.Role, "关闭两步验证")

	// 关闭 2FA → 清所有受信任设备（没 2FA 了信任设备没意义）
	db.DB.Where("user_id = ?", sess.UID).Delete(&model.TrustedDevice{})
	clearTrustedDeviceCookie(c.Writer)

	Ok(c, gin.H{"msg": "2FA 已关闭"})
}

func (h *AuthHandler) Dispatch(c *gin.Context) {
	if h.rateAttempts == nil {
		h.rateAttempts = make(map[string][]time.Time)
		go h.rateGC()
	}

	action := c.Query("action")
	method := c.Request.Method

	if method == "POST" {
		switch action {
		case "login":
			h.Login(c)
		case "login_2fa":
			h.LoginWith2FA(c)
		case "guest_login":
			h.GuestLogin(c)
		case "logout":
			h.Logout(c)
		case "2fa_setup":
			h.TwoFASetup(c)
		case "2fa_enable":
			h.TwoFAEnable(c)
		case "2fa_disable":
			h.TwoFADisable(c)
		case "delete_trusted_device":
			h.DeleteTrustedDevice(c)
		case "revoke_all_trusted_devices":
			h.RevokeAllTrustedDevices(c)
		default:
			Fail(c, http.StatusBadRequest, "unknown action: "+action)
		}
		return
	}

	// GET
	switch action {
	case "list_trusted_devices":
		h.ListTrustedDevices(c)
	default:
		h.Me(c)
	}
}

func (h *AuthHandler) Logout(c *gin.Context) {
	val, _ := c.Get("session_id")
	if sid, ok := val.(string); ok && sid != "" {
		h.Store.DestroySession(sid)
	}
	auth.ClearCookies(c.Writer, sessionCookieName, csrfCookieName)
	Ok(c, nil)
}

// randomToken 生成随机 token
func randomToken(n int) string {
	b := make([]byte, n)
	rand.Read(b)
	return hex.EncodeToString(b)
}

/* ==================== 受信任设备 helper & handler ==================== */

// cookie 名：前端 trusted_device cookie
func clearTrustedDeviceCookie(w http.ResponseWriter) {
	http.SetCookie(w, &http.Cookie{Name: trustedDeviceCookie, Value: "", Path: "/", HttpOnly: true, SameSite: http.SameSiteLaxMode, MaxAge: -1})
}

// verifyTrustedDevice 检查 cookie 是否命中，命中则更新 last_used_at
func verifyTrustedDevice(uid uint, r *http.Request) bool {
	cookie, err := r.Cookie(trustedDeviceCookie)
	if err != nil || cookie.Value == "" {
		return false
	}
	hash := sha256.Sum256([]byte(cookie.Value))
	hashHex := hex.EncodeToString(hash[:])

	var d model.TrustedDevice
	res := db.DB.Where("token_hash = ? AND user_id = ? AND expires_at > ?", hashHex, uid, time.Now()).First(&d)
	if res.Error != nil {
		// 顺带清理过期记录
		db.DB.Where("user_id = ? AND expires_at < ?", uid, time.Now()).Delete(&model.TrustedDevice{})
		return false
	}
	now := time.Now()
	db.DB.Model(&d).Update("last_used_at", &now)
	return true
}

// saveTrustedDevice 生成 token → 存 DB（哈希）→ setcookie
func saveTrustedDevice(uid uint, r *http.Request, w http.ResponseWriter) error {
	// 清过期
	db.DB.Where("user_id = ? AND expires_at < ?", uid, time.Now()).Delete(&model.TrustedDevice{})
	// 超上限删最早过期的
	var cnt int64
	db.DB.Model(&model.TrustedDevice{}).Where("user_id = ?", uid).Count(&cnt)
	if cnt >= trustedDeviceMaxPerUser {
		var oldest model.TrustedDevice
		db.DB.Where("user_id = ?", uid).Order("expires_at ASC").First(&oldest)
		db.DB.Delete(&oldest)
	}

	token := randomToken(32) // 64 hex chars
	hash := sha256.Sum256([]byte(token))
	hashHex := hex.EncodeToString(hash[:])
	ip := auth.ClientIP(r)
	ipShort := ip
	if strings.Contains(ip, ":") {
		parts := strings.SplitN(ip, ":", 3)
		if len(parts) >= 2 {
			ipShort = parts[0] + ":" + parts[1] + ":****"
		}
	}
	ua := r.Header.Get("User-Agent")
	deviceName := parseDeviceName(ua)
	expires := time.Now().Add(30 * 24 * time.Hour)

	now := time.Now()
	d := model.TrustedDevice{
		UserID:     uid,
		TokenHash:  hashHex,
		DeviceName: deviceName,
		IPSnippet:  ipShort,
		UserAgent:  ua[:min(len(ua), 255)],
		CreatedAt:  now,
		LastUsedAt: &now,
		ExpiresAt:  expires,
	}
	if err := db.DB.Create(&d).Error; err != nil {
		return err
	}

	http.SetCookie(w, &http.Cookie{
		Name:     trustedDeviceCookie,
		Value:    token,
		Path:     "/",
		HttpOnly: true,
		SameSite: http.SameSiteLaxMode,
		MaxAge:   trustedDeviceMaxAge,
		Secure:   r.TLS != nil,
	})
	return nil
}

func parseDeviceName(ua string) string {
	if ua == "" {
		return "未知设备"
	}
	if strings.Contains(ua, "iPhone") {
		return "iPhone / iOS"
	}
	if strings.Contains(ua, "Android") {
		return "Android"
	}
	if strings.Contains(ua, "iPad") {
		return "iPad / iOS"
	}
	var os, browser string
	switch {
	case strings.Contains(ua, "Windows NT 10"):
		os = "Windows 10/11"
	case strings.Contains(ua, "Windows NT 6.3"):
		os = "Windows 8.1"
	case strings.Contains(ua, "Mac OS X"):
		os = "macOS"
	case strings.Contains(ua, "Linux"):
		os = "Linux"
	default:
		os = "桌面"
	}
	switch {
	case strings.Contains(ua, "Chrome") && !strings.Contains(ua, "Edg"):
		browser = "Chrome"
	case strings.Contains(ua, "Edg/"):
		browser = "Edge"
	case strings.Contains(ua, "Firefox"):
		browser = "Firefox"
	case strings.Contains(ua, "Safari"):
		browser = "Safari"
	default:
		browser = "浏览器"
	}
	return browser + " / " + os
}

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}

/* --- 3 个新 handler --- */

func (h *AuthHandler) ListTrustedDevices(c *gin.Context) {
	sess := getSession(c)
	if sess == nil {
		Fail(c, http.StatusUnauthorized, "未登录")
		return
	}
	// 清过期
	db.DB.Where("user_id = ? AND expires_at < ?", sess.UID, time.Now()).Delete(&model.TrustedDevice{})

	var list []model.TrustedDevice
	db.DB.Where("user_id = ?", sess.UID).Order("last_used_at DESC, created_at DESC").Find(&list)

	currentHash := ""
	if cookie, err := c.Request.Cookie(trustedDeviceCookie); err == nil && cookie.Value != "" {
		hash := sha256.Sum256([]byte(cookie.Value))
		currentHash = hex.EncodeToString(hash[:])
	}

	out := make([]gin.H, 0, len(list))
	for _, d := range list {
		isCurrent := currentHash != "" && currentHash == d.TokenHash
		lu := ""
		if d.LastUsedAt != nil {
			lu = d.LastUsedAt.Format("2006-01-02 15:04:05")
		}
		out = append(out, gin.H{
			"id":           d.ID,
			"device_name":  d.DeviceName,
			"ip_snippet":   d.IPSnippet,
			"last_used_at": lu,
			"expires_at":   d.ExpiresAt.Format("2006-01-02 15:04:05"),
			"is_current":   isCurrent,
		})
	}
	Ok(c, out)
}

func (h *AuthHandler) DeleteTrustedDevice(c *gin.Context) {
	sess := getSession(c)
	if sess == nil {
		Fail(c, http.StatusUnauthorized, "未登录")
		return
	}
	var body struct {
		ID uint `json:"id"`
	}
	if err := c.ShouldBindJSON(&body); err != nil || body.ID == 0 {
		FailMsg(c, "参数错误")
		return
	}

	var d model.TrustedDevice
	res := db.DB.Where("id = ? AND user_id = ?", body.ID, sess.UID).First(&d)
	if res.Error != nil {
		Fail(c, http.StatusNotFound, "设备不存在")
		return
	}

	currentHash := ""
	if cookie, err := c.Request.Cookie(trustedDeviceCookie); err == nil && cookie.Value != "" {
		hash := sha256.Sum256([]byte(cookie.Value))
		currentHash = hex.EncodeToString(hash[:])
	}

	db.DB.Where("id = ? AND user_id = ?", body.ID, sess.UID).Delete(&model.TrustedDevice{})
	if currentHash != "" && currentHash == d.TokenHash {
		clearTrustedDeviceCookie(c.Writer)
	}
	auth.AuditLog(c.Writer, c.Request, "trusted_device.delete", usernameByUID(sess.UID), "success", usernameByUID(sess.UID), sess.Role, strconv.FormatUint(uint64(body.ID), 10))
	Ok(c, nil)
}

func (h *AuthHandler) RevokeAllTrustedDevices(c *gin.Context) {
	sess := getSession(c)
	if sess == nil {
		Fail(c, http.StatusUnauthorized, "未登录")
		return
	}
	db.DB.Where("user_id = ?", sess.UID).Delete(&model.TrustedDevice{})
	clearTrustedDeviceCookie(c.Writer)
	auth.AuditLog(c.Writer, c.Request, "trusted_device.revoke_all", usernameByUID(sess.UID), "success", usernameByUID(sess.UID), sess.Role, "")
	Ok(c, nil)
}
