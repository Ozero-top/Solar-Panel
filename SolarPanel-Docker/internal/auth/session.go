package auth

import (
	"crypto/rand"
	"encoding/hex"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/gin-gonic/gin"
)

// Session 内存会话
type Session struct {
	UID       uint      `json:"uid"`
	CSRFToken string    `json:"csrf_token"`
	Role      string    `json:"role"`
	Expires   time.Time `json:"exp"`
}

type Store struct {
	TTL      time.Duration
	mu       sync.RWMutex
	sessions map[string]*Session
	csrfMap  map[string]string
}

func NewSessionStore(ttlSeconds int) *Store {
	s := &Store{
		TTL:      time.Duration(ttlSeconds) * time.Second,
		sessions: make(map[string]*Session),
		csrfMap:  make(map[string]string),
	}
	go s.gcLoop()
	return s
}

func (s *Store) gcLoop() {
	t := time.NewTicker(5 * time.Minute)
	defer t.Stop()
	for range t.C {
		s.gc()
	}
}
func (s *Store) gc() {
	s.mu.Lock()
	defer s.mu.Unlock()
	now := time.Now()
	for sid, sess := range s.sessions {
		if now.After(sess.Expires) {
			delete(s.csrfMap, sess.CSRFToken)
			delete(s.sessions, sid)
		}
	}
}

func NewSID() string {
	var b [32]byte
	rand.Read(b[:])
	return hex.EncodeToString(b[:])
}
func NewCSRFToken() string {
	var b [32]byte
	rand.Read(b[:])
	return hex.EncodeToString(b[:])
}

func (s *Store) CreateSession(uid uint, role string) (sid, csrf string) {
	sid = NewSID()
	csrf = NewCSRFToken()
	s.mu.Lock()
	s.sessions[sid] = &Session{UID: uid, CSRFToken: csrf, Role: role, Expires: time.Now().Add(s.TTL)}
	s.csrfMap[csrf] = sid
	s.mu.Unlock()
	return
}

func (s *Store) GetSession(sid string) *Session {
	s.mu.RLock()
	defer s.mu.RUnlock()
	sess, ok := s.sessions[sid]
	if !ok || time.Now().After(sess.Expires) {
		return nil
	}
	return sess
}

func (s *Store) DestroySession(sid string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if sess, ok := s.sessions[sid]; ok {
		delete(s.csrfMap, sess.CSRFToken)
		delete(s.sessions, sid)
	}
}

func (s *Store) RotateCSRF(sid string) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	sess, ok := s.sessions[sid]
	if !ok {
		return ""
	}
	delete(s.csrfMap, sess.CSRFToken)
	sess.CSRFToken = NewCSRFToken()
	s.csrfMap[sess.CSRFToken] = sid
	sess.Expires = time.Now().Add(s.TTL)
	return sess.CSRFToken
}

// SessionMiddleware 解析 session cookie
func (s *Store) SessionMiddleware(cookieName string) gin.HandlerFunc {
	return func(c *gin.Context) {
		sid, _ := c.Cookie(cookieName)
		if sid != "" {
			sess := s.GetSession(sid)
			if sess != nil {
				c.Set("session_id", sid)
				c.Set("session", sess)
				if c.Request.Method != "GET" {
					s.Touch(sid)
				}
			}
		}
		c.Next()
	}
}

func (s *Store) Touch(sid string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if sess, ok := s.sessions[sid]; ok {
		sess.Expires = time.Now().Add(s.TTL)
	}
}

// CSRFMiddleware 写请求校验 X-CSRF-Token 头
func (s *Store) CSRFMiddleware(csrfCookieName string) gin.HandlerFunc {
	return func(c *gin.Context) {
		method := strings.ToUpper(c.Request.Method)
		if method != "GET" && method != "HEAD" && method != "OPTIONS" {
			headerToken := c.GetHeader("X-CSRF-Token")
			val, _ := c.Get("session")
			sess, _ := val.(*Session)
			if sess == nil || headerToken == "" || headerToken != sess.CSRFToken {
				c.JSON(http.StatusForbidden, gin.H{"code": 1, "data": nil, "msg": "CSRF 令牌无效或已过期，请刷新页面后重试"})
				c.Abort()
				return
			}
		}
		c.Next()
	}
}

// RequireLogin 必须登录（放到路由组）
func RequireLogin() gin.HandlerFunc {
	return func(c *gin.Context) {
		_, ok := c.Get("session")
		if !ok {
			c.JSON(http.StatusUnauthorized, gin.H{"code": 1, "data": nil, "msg": "未登录或登录已过期"})
			c.Abort()
			return
		}
		c.Next()
	}
}

// RequireRoles 必须指定角色
func RequireRoles(roles ...string) gin.HandlerFunc {
	return func(c *gin.Context) {
		val, ok := c.Get("session")
		if !ok {
			c.JSON(http.StatusUnauthorized, gin.H{"code": 1, "data": nil, "msg": "未登录或登录已过期"})
			c.Abort()
			return
		}
		sess, ok := val.(*Session)
		if !ok {
			c.JSON(http.StatusUnauthorized, gin.H{"code": 1, "data": nil, "msg": "未登录或登录已过期"})
			c.Abort()
			return
		}
		for _, r := range roles {
			if sess.Role == r {
				c.Next()
				return
			}
		}
		c.JSON(http.StatusForbidden, gin.H{"code": 1, "data": nil, "msg": "当前账号权限不足"})
		c.Abort()
	}
}

// SetCookies 同时下发 session + csrf cookie
func SetCookies(w http.ResponseWriter, sid, sessCSRF, sessionCookieName, csrfCookieName string) {
	http.SetCookie(w, &http.Cookie{
		Name: sessionCookieName, Value: sid, Path: "/", HttpOnly: true, SameSite: http.SameSiteLaxMode, MaxAge: 86400,
	})
	http.SetCookie(w, &http.Cookie{
		Name: csrfCookieName, Value: sessCSRF, Path: "/", HttpOnly: false, SameSite: http.SameSiteLaxMode, MaxAge: 86400,
	})
}

// ClearCookies 登出时清除
func ClearCookies(w http.ResponseWriter, sessionCookieName, csrfCookieName string) {
	http.SetCookie(w, &http.Cookie{Name: sessionCookieName, Value: "", Path: "/", MaxAge: -1})
	http.SetCookie(w, &http.Cookie{Name: csrfCookieName, Value: "", Path: "/", MaxAge: -1})
}
