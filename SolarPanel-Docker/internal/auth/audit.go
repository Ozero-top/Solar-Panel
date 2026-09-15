package auth

import (
	"log"
	"net/http"
	"strings"
	"time"

	"solarpanel/internal/db"
	"solarpanel/internal/model"
)

// AuditLog 写一条审计日志（失败静默，不阻断主流程）
// action: auth.login / item.edit / settings.save / audit.clear / ...
// target: 对象（用户名 / 卡片标题 / 分组名 / ...）
// result: success / fail
// actor/actorRole: 操作人（可空）
func AuditLog(w http.ResponseWriter, r *http.Request, action, target, result, actor, actorRole, detail string) {
	if db.DB == nil {
		return
	}
	ip := ClientIP(r)
	ua := r.UserAgent()
	entry := model.AuditLog{
		Action:    action,
		Target:    target,
		Result:    result,
		Actor:     actor,
		ActorRole: actorRole,
		IP:        ip,
		UserAgent: ua,
		Detail:    detail,
		CreatedAt: time.Now(),
	}
	if err := db.DB.Create(&entry).Error; err != nil {
		log.Printf("[audit] write failed: %v", err)
	}
}

// ClientIP 从请求中解析真实客户端 IP（支持反向代理 X-Forwarded-For）
func ClientIP(r *http.Request) string {
	if r == nil {
		return ""
	}
	if xff := r.Header.Get("X-Forwarded-For"); xff != "" {
		// 取第一个 IP
		parts := strings.Split(xff, ",")
		return strings.TrimSpace(parts[0])
	}
	if xri := r.Header.Get("X-Real-IP"); xri != "" {
		return strings.TrimSpace(xri)
	}
	// RemoteAddr 可能是 host:port
	addr := r.RemoteAddr
	if i := strings.LastIndex(addr, ":"); i >= 0 {
		return addr[:i]
	}
	return addr
}

// AuditCleanup 清理 7 天前的审计日志（可后台定时调用）
func AuditCleanup() {
	if db.DB == nil {
		return
	}
	cutoff := time.Now().AddDate(0, 0, -7)
	db.DB.Where("created_at < ?", cutoff).Delete(&model.AuditLog{})
}
