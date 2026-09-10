-- ============================================================
-- SolarPanel 数据库初始化脚本（手动导入时使用）
-- 推荐直接访问 /backend/api/install.php 安装向导
-- （向导会自动建库建表、写入默认数据与管理员账号；
--   安装成功后向导会自动删除自己与 sql 目录）
-- 注意：本脚本仅创建表结构与默认数据，不创建管理员账号；
--       手动导入后请访问安装向导或后台登录页创建管理员。
-- ============================================================
-- CREATE DATABASE IF NOT EXISTS solar_panel DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE solar_panel;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `name` VARCHAR(50) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `role` VARCHAR(20) NOT NULL DEFAULT 'admin' COMMENT '权限组：admin 管理员 / editor 编辑者 / viewer 只读',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `item_groups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(50) NOT NULL,
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `sort` INT NOT NULL DEFAULT 0,
  `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(50) NOT NULL,
  `url` VARCHAR(1000) NOT NULL DEFAULT '',
  `lan_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `icon_type` VARCHAR(10) NOT NULL DEFAULT 'image',
  `icon_value` VARCHAR(1000) NOT NULL DEFAULT '',
  `icon_bg` VARCHAR(20) NOT NULL DEFAULT '',
  `open_method` TINYINT NOT NULL DEFAULT 2,
  `sort` INT NOT NULL DEFAULT 0,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_group_sort` (`group_id`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_name` VARCHAR(50) NOT NULL,
  `config_value` TEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_name` (`config_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 默认设置（与安装向导 / 后台「恢复初始状态」保持一致，共 26 项）
INSERT INTO `settings` (`config_name`, `config_value`) VALUES
  ('site_title', 'SolarPanel'),
  ('site_logo', ''),
  ('wallpaper', ''),
  ('mask_opacity', '0.35'),
  ('wallpaper_blur', '6'),
  ('announcement', '欢迎使用 SolarPanel！所有展示内容均可在后台设置。'),
  ('announcement_show', '0'),
  ('footer', 'Powered by SolarPanel'),
  ('clock_show', '1'),
  ('default_theme', 'dark'),
  ('theme_style', 'soft'),
  ('card_style', 'detail'),
  ('default_lan_mode', 'public'),
  ('site_url', ''),
  ('content_maxwidth', '1200'),
  ('content_pad_lr', '20'),
  ('content_pad_top', '0'),
  ('content_pad_bottom', '40'),
  ('weather_show', '1'),
  ('weather_city', ''),
  ('search_width', '640'),
  ('home_view', 'both'),
  ('news_sources', ''),
  ('news_order', ''),
  ('search_engines', '[{"name":"百度","url":"https://www.baidu.com/s?wd=%s"},{"name":"Google","url":"https://www.google.com/search?q=%s"},{"name":"Bing","url":"https://www.bing.com/search?q=%s"},{"name":"DuckDuckGo","url":"https://duckduckgo.com/?q=%s"},{"name":"Yandex","url":"https://yandex.com/search/?text=%s"},{"name":"GitHub","url":"https://github.com/search?q=%s"},{"name":"搜狗","url":"https://www.sogou.com/web?query=%s"},{"name":"360搜索","url":"https://www.so.com/s?q=%s"},{"name":"神马","url":"https://m.sm.cn/s?q=%s"},{"name":"夸克","url":"https://www.quark.cn/s?q=%s"},{"name":"头条搜索","url":"https://so.toutiao.com/search?keyword=%s"},{"name":"中国搜索","url":"https://www.chinaso.com/search/all?q=%s"},{"name":"抖音","url":"https://www.douyin.com/search/%s"}]'),
  ('search_default', '百度'),
  ('icp_show', '0'),
  ('icp_number', ''),
  ('icp_link', ''),
  ('police_show', '0'),
  ('police_number', ''),
  ('police_link', '')
ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`);

-- 示例分组与卡片（与安装向导一致；@gid 兼容非空库导入时自增 ID 不为 1 的情况）
INSERT INTO `item_groups` (`title`, `description`, `sort`, `is_visible`)
VALUES ('常用推荐', '点击卡片即可跳转，可在后台管理', 0, 1);
SET @gid = LAST_INSERT_ID();

INSERT INTO `items` (`group_id`, `title`, `url`, `description`, `icon_type`, `icon_value`, `icon_bg`, `open_method`, `sort`) VALUES
  (@gid, 'GitHub',     'https://github.com',       '全球最大代码托管平台', 'favicon', '', '', 2, 0),
  (@gid, '哔哩哔哩',    'https://www.bilibili.com', '视频弹幕网站',         'favicon', '', '', 2, 1),
  (@gid, 'Docker Hub', 'https://hub.docker.com',   '容器镜像仓库',         'favicon', '', '', 2, 2);
