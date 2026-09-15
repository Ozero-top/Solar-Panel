package config

import (
	"os"
	"path/filepath"
)

// Config 应用配置（全部使用默认值，无需外部 config.yaml）
type Config struct {
	// ServerPort HTTP 服务端口
	ServerPort string
	// HTTPSPort HTTPS 服务端口（空=不开，默认 18443）
	HTTPSPort string
	// CertFile TLS 证书路径（默认 data/cert.pem）
	CertFile string
	// KeyFile TLS 私钥路径（默认 data/key.pem）
	KeyFile string
	// DBPath SQLite 数据库文件路径
	DBPath string
	// UploadDir 上传文件根目录
	UploadDir string
	// SessionTTL 会话过期时间（秒）
	SessionTTL int
	// SessionCookieName 会话 cookie 名
	SessionCookieName string
	// CSRFCookieName CSRF cookie 名
	CSRFCookieName string
}

// Load 默认配置
func Load() *Config {
	wd, _ := os.Getwd()
	dataDir := filepath.Join(wd, "data")
	uploadDir := filepath.Join(dataDir, "uploads")
	dbPath := filepath.Join(dataDir, "solarpanel.db")
	os.MkdirAll(dataDir, 0755)
	os.MkdirAll(uploadDir, 0755)

	return &Config{
		ServerPort:        getEnv("SERVER_PORT", "18080"),
		HTTPSPort:         os.Getenv("HTTPS_PORT"), // 空=不开 HTTPS
		CertFile:          getEnv("CERT_FILE", filepath.Join(dataDir, "cert.pem")),
		KeyFile:           getEnv("KEY_FILE", filepath.Join(dataDir, "key.pem")),
		DBPath:            dbPath,
		UploadDir:         uploadDir,
		SessionTTL:        86400,
		SessionCookieName: "sp_session",
		CSRFCookieName:    "csrf_token",
	}
}

func getEnv(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}
