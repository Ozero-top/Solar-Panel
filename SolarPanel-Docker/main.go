package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"embed"
	"encoding/pem"
	"fmt"
	"io/fs"
	"log"
	"math/big"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"solarpanel/internal/auth"
	"solarpanel/internal/config"
	"solarpanel/internal/db"
	"solarpanel/internal/handler"

	"github.com/gin-gonic/gin"
)

//go:embed all:frontend
var frontendFS embed.FS

// contentTypeByExt 根据扩展名返回 Content-Type
func contentTypeByExt(path string) string {
	ext := filepath.Ext(path)
	switch ext {
	case ".html":
		return "text/html; charset=utf-8"
	case ".css":
		return "text/css; charset=utf-8"
	case ".js":
		return "application/javascript; charset=utf-8"
	case ".json":
		return "application/json; charset=utf-8"
	case ".ico":
		return "image/x-icon"
	case ".png":
		return "image/png"
	case ".jpg", ".jpeg":
		return "image/jpeg"
	case ".gif":
		return "image/gif"
	case ".svg":
		return "image/svg+xml"
	case ".webp":
		return "image/webp"
	case ".bmp":
		return "image/bmp"
	case ".txt":
		return "text/plain; charset=utf-8"
	default:
		return "application/octet-stream"
	}
}

func seedPresetWallpapers(uploadDir string) {
	presetDir := filepath.Join(uploadDir, "weather")
	os.MkdirAll(presetDir, 0755)

	entries, err := fs.ReadDir(frontendFS, "frontend/uploads/weather")
	if err != nil {
		return
	}
	for _, e := range entries {
		if e.IsDir() {
			continue
		}
		dst := filepath.Join(presetDir, e.Name())
		if _, err := os.Stat(dst); err == nil {
			continue
		}
		srcData, err := frontendFS.ReadFile("frontend/uploads/weather/" + e.Name())
		if err != nil {
			continue
		}
		if err := os.WriteFile(dst, srcData, 0644); err != nil {
			log.Printf("[seed] 写入 preset 壁纸 %s 失败: %v", e.Name(), err)
			continue
		}
		log.Printf("[seed] 预置壁纸 %s 已复制", e.Name())
	}
}

// extractVersion 从内嵌的 frontend/assets/js/api.js 提取 APP_VERSION 常量值
func extractVersion() string {
	data, err := frontendFS.ReadFile("frontend/assets/js/api.js")
	if err != nil {
		return ""
	}
	re := regexp.MustCompile(`const\s+APP_VERSION\s*=\s*['"]([^'"]+)['"]`)
	m := re.FindSubmatch(data)
	if len(m) < 2 {
		return ""
	}
	return string(m[1])
}

// generateSelfSignedCert 在 certPath / keyPath 生成自签名证书（不存在时）
func generateSelfSignedCert(certPath, keyPath string) error {
	if _, err := os.Stat(certPath); err == nil {
		if _, err2 := os.Stat(keyPath); err2 == nil {
			return nil // 证书已存在
		}
	}
	log.Println("[tls] 未找到 TLS 证书，正在生成自签名证书...")

	priv, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return fmt.Errorf("生成私钥失败: %w", err)
	}

	serialNumber, err := rand.Int(rand.Reader, big.NewInt(1<<62))
	if err != nil {
		return fmt.Errorf("生成序列号失败: %w", err)
	}

	// 收集本机 IP 供证书 SAN 使用
	ips := []net.IP{}
	ifaces, _ := net.Interfaces()
	for _, iface := range ifaces {
		addrs, _ := iface.Addrs()
		for _, addr := range addrs {
			if ipNet, ok := addr.(*net.IPNet); ok && !ipNet.IP.IsLoopback() {
				ips = append(ips, ipNet.IP)
			}
		}
	}
	ips = append(ips, net.IPv4(127, 0, 0, 1), net.IPv6loopback)

	template := x509.Certificate{
		SerialNumber: serialNumber,
		Subject: pkix.Name{
			Organization: []string{"SolarPanel Self-Signed"},
			CommonName:   "SolarPanel",
		},
		NotBefore:             time.Now().Add(-time.Hour),
		NotAfter:              time.Now().Add(365 * 24 * time.Hour), // 1 年
		KeyUsage:              x509.KeyUsageDigitalSignature | x509.KeyUsageKeyEncipherment,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
		IPAddresses:           ips,
		DNSNames:              []string{"localhost", "solarpanel.local"},
	}

	derBytes, err := x509.CreateCertificate(rand.Reader, &template, &template, &priv.PublicKey, priv)
	if err != nil {
		return fmt.Errorf("创建证书失败: %w", err)
	}

	// 写 cert.pem
	certOut, err := os.Create(certPath)
	if err != nil {
		return fmt.Errorf("写证书文件失败: %w", err)
	}
	defer certOut.Close()
	pem.Encode(certOut, &pem.Block{Type: "CERTIFICATE", Bytes: derBytes})

	// 写 key.pem
	keyOut, err := os.OpenFile(keyPath, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0600)
	if err != nil {
		return fmt.Errorf("写私钥文件失败: %w", err)
	}
	defer keyOut.Close()
	privBytes, _ := x509.MarshalECPrivateKey(priv)
	pem.Encode(keyOut, &pem.Block{Type: "EC PRIVATE KEY", Bytes: privBytes})

	log.Println("[tls] 自签名证书已生成:", certPath, keyPath)
	log.Println("[tls] 浏览器首次访问 HTTPS 时需点「高级 → 继续」信任自签名证书")
	return nil
}

func main() {
	cfg := config.Load()

	// 1. 数据库
	if err := db.Init(cfg); err != nil {
		log.Fatal("[db] 初始化失败:", err)
	}
	defer db.Close()

	// 1.5 预置壁纸：从 embed 复制到 UploadDir/weather/（仅文件不存在时）
	seedPresetWallpapers(cfg.UploadDir)

	// 2. Session store
	sessionStore := auth.NewSessionStore(cfg.SessionTTL)

	// 3. Gin
	gin.SetMode(gin.ReleaseMode)
	r := gin.New()
	r.RedirectTrailingSlash = false
	r.RedirectFixedPath = false
	r.Use(gin.Logger())
	r.Use(gin.Recovery())

	// ===== 中间件 =====
	r.Use(sessionStore.SessionMiddleware(cfg.SessionCookieName))
	// CSRF 校验只在登录后路由组启用（登录 POST 本身没有 session，无法校验）
	// r.Use(sessionStore.CSRFMiddleware(cfg.CSRFCookieName))

	// 1.5 限流 & 审计后台清理协程
	rateLimiter := auth.NewRateLimiter()

	// ===== 构造 handler =====
	authHandler := handler.NewAuthHandler(sessionStore)
	installHandler := handler.InstallHandler{}
	currentVersion := extractVersion()
	handler.AppVersion = currentVersion
	uploadHandler := handler.NewUploadHandler(cfg)
	galleryHandler := handler.NewGalleryHandler(cfg)
	weatherHandler := handler.NewWeatherHandler(cfg)
	publicHandler := &handler.PublicHandler{}
	newsHandler := &handler.NewsHandler{}
	settingsHandler := &handler.SettingsHandler{}
	groupsHandler := &handler.GroupsHandler{}
	itemsHandler := handler.NewItemsHandler(cfg)
	backupHandler := handler.NewBackupHandler(cfg)
	userHandler := &handler.UserHandler{}
	auditHandler := handler.NewAuditHandler()
	feedsHandler := handler.NewFeedsHandler()
	importHandler := handler.NewImportHandler()
	wallpaperHandler := handler.NewWallpaperHandler(cfg)

	// ===== 限流中间件（Gin HandlerFunc 适配器）=====
	rateLimitMW := func(endpoint string, max, windowSec int) gin.HandlerFunc {
		return func(c *gin.Context) {
			if !rateLimiter.Guard(c.Writer, c.Request, endpoint, max, windowSec) {
				c.Abort()
				return
			}
			c.Next()
		}
	}

	// ===== 公共路由（无需登录） =====
	api := r.Group("/api")
	{
		api.GET("/public.php",
			rateLimitMW("public", 60, 60),
			publicHandler.Public,
		)

		api.GET("/install.php", installHandler.Status)
		api.POST("/install.php", installHandler.Setup)

		api.GET("/weather.php",
			rateLimitMW("weather", 10, 60),
			weatherHandler.Get,
		)
		api.Any("/news.php",
			rateLimitMW("news", 30, 60),
			newsHandler.Dispatch,
		)
		api.GET("/news/meta", newsHandler.Sources)
		api.GET("/news/all", newsHandler.All)
		api.GET("/news/source", newsHandler.Source)

		api.Any("/wallpaper.php",
			rateLimitMW("wallpaper", 10, 60),
			wallpaperHandler.Dispatch,
		)

		api.Any("/auth.php", sessionStore.SessionMiddleware(cfg.SessionCookieName), authHandler.Dispatch)
	}

	// ===== 登录后路由（所有管理 API 在这里） =====
	authG := api.Group("", auth.RequireLogin(), sessionStore.CSRFMiddleware(cfg.CSRFCookieName))
	{
		authG.Any("/settings.php", settingsHandler.Dispatch)
		authG.Any("/groups.php", groupsHandler.Dispatch)
		authG.Any("/items.php", itemsHandler.Dispatch)
		authG.POST("/upload.php", uploadHandler.Upload)
		authG.Any("/gallery.php", galleryHandler.Dispatch)
		authG.Any("/backup.php", backupHandler.Dispatch)
		authG.Any("/user.php", userHandler.Dispatch)
		authG.Any("/audit.php", auditHandler.Dispatch)
		authG.Any("/feeds.php", feedsHandler.Dispatch)
		authG.POST("/import.php", importHandler.Dispatch)
	}

	// ===== 静态文件（embed） =====
	sub, err := fs.Sub(frontendFS, "frontend")
	if err != nil {
		log.Fatal("embed sub error:", err)
	}
	_ = sub // 保留 embed 子路径验证（实际直接用 frontendFS.ReadFile）

	// ===== 静态文件 helper：直接返回 bytes（绕过 FileServer 301 redirect） =====
	serveFile := func(embedPath, contentType string, c *gin.Context) {
		data, err := frontendFS.ReadFile(embedPath)
		if err != nil {
			c.JSON(404, gin.H{"code": 1, "msg": "not found"})
			return
		}
		if contentType != "" {
			c.Data(http.StatusOK, contentType, data)
			return
		}
		c.Data(http.StatusOK, "", data)
	}

	// —— 安全拦截：admin.html 只允许 admin/editor/viewer 访问 ——
	// 未登录时若 guest_access_enabled=1 也禁止访问（访客需先输访客密码，然后还需管理员账号才能进后台）
	// login.html 若已登录且非 guest，直接跳 admin.html（省一次前端检查）
	protectAdminHTML := func(c *gin.Context) {
		// 1. 取 session
		var sess *auth.Session
		if v, ok := c.Get("session"); ok {
			sess, _ = v.(*auth.Session)
		}
		// 2. 检查是否访客密码开启
		guestEnabled := false
		if db.DB != nil {
			var val string
			db.DB.Raw("SELECT config_value FROM settings WHERE config_name = ?", "guest_access_enabled").Scan(&val)
			guestEnabled = val == "1"
		}
		// 3. 权限判定
		if sess == nil {
			// 未登录 → 如果访客密码开了，也不能看 admin.html（防止直接硬爆破路径）
			if guestEnabled {
				c.Redirect(http.StatusFound, "/login.html")
				c.Abort()
				return
			}
			// 访客密码没开，但也没管理员登录 → 正常返回 admin.html（前端 admin.js 会弹登录）
		} else if sess.Role == "guest" {
			// 访客角色 → 踢到 login.html
			c.Redirect(http.StatusFound, "/login.html")
			c.Abort()
			return
		}
		// admin/editor/viewer 正常放行
		c.Next()
	}
	protectLoginHTML := func(c *gin.Context) {
		var sess *auth.Session
		if v, ok := c.Get("session"); ok {
			sess, _ = v.(*auth.Session)
		}
		if sess != nil && sess.Role != "guest" {
			// 已登录管理员 → 直接进后台
			c.Redirect(http.StatusFound, "/admin.html")
			c.Abort()
			return
		}
		c.Next()
	}

	// 显式注册根路径 → index.html
	r.GET("/", func(c *gin.Context) { serveFile("frontend/index.html", "text/html; charset=utf-8", c) })
	r.GET("/favicon.ico", func(c *gin.Context) { serveFile("frontend/favicon.ico", "image/x-icon", c) })
	r.GET("/login.html", protectLoginHTML, func(c *gin.Context) { serveFile("frontend/login.html", "text/html; charset=utf-8", c) })
	r.GET("/admin.html", protectAdminHTML, func(c *gin.Context) { serveFile("frontend/admin.html", "text/html; charset=utf-8", c) })

	// 用户上传文件 + 预置壁纸：从磁盘 UploadDir 直接提供（优先于 embed）
	// 同时注册 /frontend/uploads 和 /uploads 两个路径：
	//   /frontend/uploads 是后端返回的标准路径
	//   /uploads 是前端 assetUrl() 在 admin 等非主页页面转换后的路径（PHP 原版 Web 根即 frontend，故 /uploads 可直访）
	r.Static("/frontend/uploads", cfg.UploadDir)
	r.Static("/uploads", cfg.UploadDir)

	// CSS/JS 资源 — 用 NoRoute + 直接读 embed
	r.NoRoute(func(c *gin.Context) {
		path := c.Request.URL.Path
		// 兼容两种访问路径：
		//   /assets/css/common.css   → frontend/assets/css/common.css
		//   /frontend/assets/css/xxx → frontend/assets/css/xxx  (strip /frontend 前缀)
		cleanPath := strings.TrimPrefix(path, "/frontend")
		if cleanPath == "" {
			cleanPath = path
		}
		embedPath := "frontend" + cleanPath
		data, err := frontendFS.ReadFile(embedPath)
		if err == nil {
			ct := contentTypeByExt(cleanPath)
			c.Data(http.StatusOK, ct, data)
			return
		}
		c.JSON(http.StatusNotFound, gin.H{"code": 1, "data": nil, "msg": "not found: " + path})
	})

	// ===== 启动 =====
	log.Println("==============================================")
	log.Println(" SolarPanel (Go rewrite) 已启动")
	log.Println(" 版本:", currentVersion)
	log.Println(" 管理员默认账号: admin / admin123")
	log.Println("==============================================")

	// HTTP 服务（始终启动，方便反向代理）
	httpAddr := ":" + cfg.ServerPort
	go func() {
		srv := &http.Server{
			Addr:         httpAddr,
			Handler:      r,
			ReadTimeout:  30 * time.Second,
			WriteTimeout: 60 * time.Second,
		}
		log.Printf("[http] 监听 http://localhost%s", httpAddr)
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatal("[http] listen error:", err)
		}
	}()

	// HTTPS 服务（HTTPS_PORT 非空时自动启用，自签名证书不存在时自动生成）
	if cfg.HTTPSPort != "" {
		if err := generateSelfSignedCert(cfg.CertFile, cfg.KeyFile); err != nil {
			log.Printf("[https] 证书准备失败（跳过 HTTPS）: %v", err)
		} else {
			httpsAddr := ":" + cfg.HTTPSPort
			go func() {
				cert, err := tls.LoadX509KeyPair(cfg.CertFile, cfg.KeyFile)
				if err != nil {
					log.Fatalf("[https] 加载证书失败: %v", err)
				}
				tlsConfig := &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12}
				srv := &http.Server{
					Addr:         httpsAddr,
					Handler:      r,
					TLSConfig:    tlsConfig,
					ReadTimeout:  30 * time.Second,
					WriteTimeout: 60 * time.Second,
				}
				log.Printf("[https] 监听 https://localhost%s", httpsAddr)
				log.Printf("[https] 证书: %s | 私钥: %s", cfg.CertFile, cfg.KeyFile)
				log.Printf("[https] 浏览器首次访问请点「高级 → 继续」信任自签名证书")
				if err := srv.ListenAndServeTLS("", ""); err != nil && err != http.ErrServerClosed {
					log.Fatal("[https] listen error:", err)
				}
			}()
		}
	}

	// 主线程阻塞
	select {}
}
