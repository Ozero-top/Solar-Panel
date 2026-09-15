package model

import (
	"time"
)

// User 管理员账号
type User struct {
	ID         uint      `gorm:"primaryKey;autoIncrement" json:"id"`
	Username   string    `gorm:"size:50;uniqueIndex;not null" json:"username"`
	Password   string    `gorm:"size:255;not null" json:"-"`
	Name       string    `gorm:"size:50;not null;default:''" json:"name"`
	Status     int       `gorm:"not null;default:1" json:"status"`
	Role       string    `gorm:"size:20;not null;default:'admin'" json:"role"`
	TOTPSecret string    `gorm:"size:100;not null;default:''" json:"-"` // 2FA 密钥（Base32），json 不出站
	CreatedAt  time.Time `json:"created_at"`
}

func (User) TableName() string { return "users" }

// ItemGroup 分组
type ItemGroup struct {
	ID          uint      `gorm:"primaryKey;autoIncrement" json:"id"`
	Title       string    `gorm:"size:50;not null" json:"title"`
	Description string    `gorm:"size:1000;not null;default:''" json:"description"`
	Sort        int       `gorm:"not null;default:0" json:"sort"`
	IsVisible   int       `gorm:"not null;default:1" json:"is_visible"`
	UserID      uint      `gorm:"not null;default:1" json:"user_id"`
	CreatedAt   time.Time `json:"created_at"`
}

func (ItemGroup) TableName() string { return "item_groups" }

// Item 卡片/导航项
type Item struct {
	ID          uint      `gorm:"primaryKey;autoIncrement" json:"id"`
	GroupID     uint      `gorm:"not null;index" json:"group_id"`
	Title       string    `gorm:"size:50;not null" json:"title"`
	URL         string    `gorm:"size:1000;not null;default:''" json:"url"`
	LanURL      string    `gorm:"size:1000;not null;default:''" json:"lan_url"`
	Description string    `gorm:"size:1000;not null;default:''" json:"description"`
	IconType    string    `gorm:"size:10;not null;default:'image'" json:"icon_type"`
	IconValue   string    `gorm:"size:1000;not null;default:''" json:"icon_value"`
	IconBG      string    `gorm:"size:20;not null;default:''" json:"icon_bg"`
	OpenMethod  int       `gorm:"not null;default:2" json:"open_method"`
	Sort        int       `gorm:"not null;default:0" json:"sort"`
	UserID      uint      `gorm:"not null;default:1" json:"user_id"`
	CreatedAt   time.Time `json:"created_at"`
}

func (Item) TableName() string { return "items" }

// Setting 站点设置
type Setting struct {
	ID          uint   `gorm:"primaryKey;autoIncrement" json:"-"`
	ConfigName  string `gorm:"size:50;uniqueIndex;not null" json:"config_name"`
	ConfigValue string `gorm:"type:text" json:"config_value"`
}

func (Setting) TableName() string { return "settings" }

// AuditLog 审计日志
type AuditLog struct {
	ID        uint      `gorm:"primaryKey;autoIncrement" json:"id"`
	Action    string    `gorm:"size:50;not null;index" json:"action"`             // auth.login / item.edit / settings.save ...
	Target    string    `gorm:"size:255;not null;default:''" json:"target"`       // 对象：onen / 卡片标题 / 分组名 ...
	Result    string    `gorm:"size:20;not null;default:'success'" json:"result"` // success / fail
	Actor     string    `gorm:"size:50;not null;default:''" json:"actor"`         // 操作人用户名
	ActorRole string    `gorm:"size:20;not null;default:''" json:"actor_role"`
	IP        string    `gorm:"size:50;not null;default:'';index" json:"ip"`
	UserAgent string    `gorm:"size:500;not null;default:''" json:"user_agent"`
	Detail    string    `gorm:"size:1000;not null;default:''" json:"detail"`
	CreatedAt time.Time `gorm:"index" json:"created_at"`
}

func (AuditLog) TableName() string { return "audit_logs" }

// CustomFeed 自定义 RSS/Atom 源
type CustomFeed struct {
	ID        uint      `gorm:"primaryKey;autoIncrement" json:"id"`
	Title     string    `gorm:"size:100;not null" json:"title"`
	URL       string    `gorm:"size:1000;not null" json:"url"`
	Sort      int       `gorm:"not null;default:0" json:"sort"`
	Enabled   int       `gorm:"not null;default:1" json:"enabled"`
	CreatedAt time.Time `json:"created_at"`
}

func (CustomFeed) TableName() string { return "custom_feeds" }

// RateLimit 公开接口限流（IP + 端点滑动窗口）
type RateLimit struct {
	IP          string    `gorm:"size:50;not null;primaryKey" json:"-"`
	Endpoint    string    `gorm:"size:50;not null;primaryKey" json:"-"`
	Count       int       `gorm:"not null;default:0" json:"-"`
	WindowStart time.Time `gorm:"not null" json:"-"`
}

func (RateLimit) TableName() string { return "rate_limits" }

// TrustedDevice 受信任设备（2FA 勾信任 30 天免二次验证）
type TrustedDevice struct {
	ID         uint       `gorm:"primaryKey;autoIncrement" json:"id"`
	UserID     uint       `gorm:"not null;index" json:"-"`
	TokenHash  string     `gorm:"size:64;not null;index" json:"-"`  // SHA-256(token)
	DeviceName string     `gorm:"size:100;not null;default:''" json:"device_name"`
	IPSnippet  string     `gorm:"size:45;not null;default:''" json:"ip_snippet"`
	UserAgent  string     `gorm:"size:255;not null;default:''" json:"-"`
	CreatedAt  time.Time  `json:"created_at"`
	LastUsedAt *time.Time `json:"last_used_at"`
	ExpiresAt  time.Time  `gorm:"index" json:"expires_at"`
}

func (TrustedDevice) TableName() string { return "trusted_devices" }
