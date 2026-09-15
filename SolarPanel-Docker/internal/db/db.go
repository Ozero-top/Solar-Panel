package db

import (
	"log"

	"solarpanel/internal/config"
	"solarpanel/internal/model"

	"github.com/glebarez/sqlite"
	"golang.org/x/crypto/bcrypt"
	"gorm.io/gorm"
)

var DB *gorm.DB

// Init 初始化数据库连接 + AutoMigrate + 种子数据
func Init(cfg *config.Config) error {
	var err error
	DB, err = gorm.Open(sqlite.Open(cfg.DBPath+"?_pragma=journal_mode(WAL)&_pragma=foreign_keys(1)"), &gorm.Config{})
	if err != nil {
		return err
	}
	log.Println("[db] SQLite connected:", cfg.DBPath)

	// —— 升级前置清洗：老版本 audit_logs 等表的 NOT NULL 字段可能含 NULL
	// GORM AutoMigrate SQLite 改列约束会建 __temp 临时表再 INSERT…SELECT，
	// 新 schema 有 not null 约束而老数据有 NULL 时会炸。先批量把 NULL → ''。
	preMigrateFix()

	// AutoMigrate 所有 model
	if err := DB.AutoMigrate(
		&model.User{},
		&model.ItemGroup{},
		&model.Item{},
		&model.Setting{},
		&model.AuditLog{},
		&model.CustomFeed{},
		&model.RateLimit{},
		&model.TrustedDevice{},
	); err != nil {
		return err
	}

	// 种子数据 + 补全新安装/升级缺失的设置键
	seedIfEmpty()
	return nil
}

// Close 关闭（SQLite 不强制，但好习惯）
func Close() {
	if DB != nil {
		sqlDB, _ := DB.DB()
		if sqlDB != nil {
			sqlDB.Close()
		}
	}
}

// seedIfEmpty 首次运行写入默认数据；已安装实例补全缺失的设置键
func seedIfEmpty() {
	// 默认设置（26 + 3 新 = 29 项）
	defaults := map[string]string{
		"site_title":         "SolarPanel",
		"site_logo":          "",
		"wallpaper":          "",
		"mask_opacity":       "0.35",
		"wallpaper_blur":     "6",
		"announcement":       "欢迎使用 SolarPanel！所有展示内容均可在后台设置。",
		"announcement_show":  "0",
		"footer":             "Powered by SolarPanel",
		"clock_show":         "1",
		"default_theme":      "dark",
		"theme_style":        "soft",
		"card_style":         "detail",
		"default_lan_mode":   "public",
		"site_url":           "",
		"content_maxwidth":   "1200",
		"content_pad_lr":     "20",
		"content_pad_top":    "0",
		"content_pad_bottom": "40",
		"weather_show":       "1",
		"weather_city":       "",
		"search_width":       "640",
		"home_view":          "both",
		"news_sources":       "",
		"news_order":         "",
		"icp_show":           "0",
		"icp_number":         "",
		"icp_link":           "",
		"police_show":        "0",
		"police_number":      "",
		"police_link":        "",
		"wallpaper_source":      "",  // 每日壁纸源：空=禁用，bing=开启
		"guest_access_enabled":  "0", // 访客密码开关：0=关闭，1=开启
		"guest_password_hash":   "",  // 访客密码 bcrypt hash
		"search_bar_enabled":    "1", // 主页搜索引擎搜索栏开关：1=显示
		"card_filter_enabled":   "1", // 主页卡片筛选搜索栏开关：1=显示
	}

	// 搜索引擎（长文本单独处理）
	defaults["search_engines"] = `[{"name":"百度","url":"https://www.baidu.com/s?wd=%s"},{"name":"Google","url":"https://www.google.com/search?q=%s"},{"name":"Bing","url":"https://www.bing.com/search?q=%s"},{"name":"DuckDuckGo","url":"https://duckduckgo.com/?q=%s"},{"name":"Yandex","url":"https://yandex.com/search/?text=%s"},{"name":"GitHub","url":"https://github.com/search?q=%s"},{"name":"搜狗","url":"https://www.sogou.com/web?query=%s"},{"name":"360搜索","url":"https://www.so.com/s?q=%s"},{"name":"神马","url":"https://m.sm.cn/s?q=%s"},{"name":"夸克","url":"https://www.quark.cn/s?q=%s"},{"name":"头条搜索","url":"https://so.toutiao.com/search?keyword=%s"},{"name":"中国搜索","url":"https://www.chinaso.com/search/all?q=%s"},{"name":"抖音","url":"https://www.douyin.com/search/%s"}]`
	defaults["search_default"] = "百度"

	// 检查是否已有用户
	var userCount int64
	DB.Model(&model.User{}).Count(&userCount)

	if userCount == 0 {
		// 首次全量种子
		log.Println("[db] 首次安装，写入种子数据...")
		for k, v := range defaults {
			DB.Create(&model.Setting{ConfigName: k, ConfigValue: v})
		}

		hashed, _ := bcrypt.GenerateFromPassword([]byte("admin123"), bcrypt.DefaultCost)
		DB.Create(&model.User{Username: "admin", Password: string(hashed), Name: "管理员", Status: 1, Role: "admin"})

		g := model.ItemGroup{Title: "常用推荐", Description: "点击卡片即可跳转，可在后台管理", Sort: 0, IsVisible: 1, UserID: 1}
		DB.Create(&g)

		items := []model.Item{
			{GroupID: g.ID, Title: "GitHub", URL: "https://github.com", Description: "全球最大代码托管平台", IconType: "favicon", OpenMethod: 2, Sort: 0},
			{GroupID: g.ID, Title: "哔哩哔哩", URL: "https://www.bilibili.com", Description: "视频弹幕网站", IconType: "favicon", OpenMethod: 2, Sort: 1},
			{GroupID: g.ID, Title: "Docker Hub", URL: "https://hub.docker.com", Description: "容器镜像仓库", IconType: "favicon", OpenMethod: 2, Sort: 2},
		}
		for _, it := range items {
			DB.Create(&it)
		}
		log.Println("[db] 种子数据写入完成，管理员: admin / admin123")
	} else {
		// 已安装实例：补全缺失的设置键（升级兼容）
		var missing int
		for k, v := range defaults {
			var cnt int64
			DB.Model(&model.Setting{}).Where("config_name = ?", k).Count(&cnt)
			if cnt == 0 {
				DB.Create(&model.Setting{ConfigName: k, ConfigValue: v})
				missing++
			}
		}
		if missing > 0 {
			log.Printf("[db] 补全 %d 个缺失的设置键\n", missing)
		}
	}
}

// preMigrateFix 在 AutoMigrate 之前跑，修复老版本表中违反新 schema NOT NULL 约束的坏数据。
// SQLite GORM Migrator 改列约束时会建 _temp 临时表做 INSERT INTO ... SELECT，
// 新表有 NOT NULL 约束而老数据含 NULL 就会直接失败 —— 所以先清干净。
func preMigrateFix() {
	type fix struct {
		table, col string
	}
	fixes := []fix{
		// audit_logs —— 本次 bug 现场：老版本 detail 等字段没 not null 约束
		{"audit_logs", "action"},
		{"audit_logs", "target"},
		{"audit_logs", "result"},
		{"audit_logs", "actor"},
		{"audit_logs", "actor_role"},
		{"audit_logs", "ip"},
		{"audit_logs", "user_agent"},
		{"audit_logs", "detail"},
		// settings —— 某些老用户可能手动写了 NULL 的 config_value
		{"settings", "config_value"},
		// users —— name / totp_secret 可能有 NULL
		{"users", "name"},
		{"users", "totp_secret"},
	}
	for _, f := range fixes {
		// 表不存在就跳过（全新安装）
		if !DB.Migrator().HasTable(f.table) {
			continue
		}
		var cnt int64
		DB.Raw("SELECT COUNT(*) FROM " + f.table + " WHERE " + f.col + " IS NULL").Scan(&cnt)
		if cnt > 0 {
			res := DB.Exec("UPDATE " + f.table + " SET " + f.col + " = '' WHERE " + f.col + " IS NULL")
			log.Printf("[db] 升级修复: %s.%s 把 %d 行 NULL → ''（rows=%d）\n", f.table, f.col, cnt, res.RowsAffected)
		}
	}
}
