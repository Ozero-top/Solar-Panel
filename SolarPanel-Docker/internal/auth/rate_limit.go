package auth

import (
	"net/http"
	"strconv"
	"sync"
	"time"

	"solarpanel/internal/db"
	"solarpanel/internal/model"
)

// RateLimiter 基于 SQLite 表的 IP+端点 滑动窗口限流
// SolarPanel-Docker 用 DB 持久化（重启不丢失），而非内存 map
type RateLimiter struct {
	mu sync.Mutex
}

// NewRateLimiter 构造
func NewRateLimiter() *RateLimiter {
	// 后台每 30 秒清理过期窗口
	go func() {
		t := time.NewTicker(30 * time.Second)
		defer t.Stop()
		for range t.C {
			if db.DB != nil {
				cutoff := time.Now().Add(-5 * time.Minute)
				db.DB.Where("window_start < ?", cutoff).Delete(&model.RateLimit{})
			}
		}
	}()
	return &RateLimiter{}
}

// Guard 执行一次检查+计数；超限返回 false 并写 429 响应
// endpoint: public / news / weather / wallpaper（自定义标识）
// maxRequests: 窗口内最大请求数
// windowSec:  窗口秒数（通常 60）
func (rl *RateLimiter) Guard(w http.ResponseWriter, r *http.Request, endpoint string, maxRequests, windowSec int) bool {
	if db.DB == nil {
		return true // DB 不可用放行
	}

	ip := ClientIP(r)
	now := time.Now()

	rl.mu.Lock()
	defer rl.mu.Unlock()

	var record model.RateLimit
	err := db.DB.Where("ip = ? AND endpoint = ?", ip, endpoint).First(&record).Error

	if err != nil {
		// 无记录，创建
		if err := db.DB.Create(&model.RateLimit{
			IP:          ip,
			Endpoint:    endpoint,
			Count:       1,
			WindowStart: now,
		}).Error; err != nil {
			return true // 写 DB 失败也放行
		}
		return true
	}

	// 检查是否跨越窗口
	if now.Sub(record.WindowStart) > time.Duration(windowSec)*time.Second {
		// 开新窗口
		record.Count = 1
		record.WindowStart = now
		db.DB.Save(&record)
		return true
	}

	if record.Count >= maxRequests {
		// 超限
		retryAfter := int(time.Duration(windowSec)*time.Second - now.Sub(record.WindowStart))
		if retryAfter < 1 {
			retryAfter = 1
		}
		w.Header().Set("Retry-After", strconv.Itoa(retryAfter))
		w.WriteHeader(http.StatusTooManyRequests)
		w.Write([]byte(`{"code":429,"msg":"请求过于频繁，请稍后再试"}`))
		return false
	}

	record.Count++
	db.DB.Save(&record)
	return true
}
