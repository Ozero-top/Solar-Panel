<?php
/**
 * 站点设置项白名单（settings.php 保存 / backup.php 导入 共用）
 * 新增设置项时只需在此处登记一次。
 */

/** 允许写入 settings 表的配置键白名单 */
function sp_allowed_settings(): array
{
    return [
        'site_title', 'site_logo', 'wallpaper', 'mask_opacity', 'wallpaper_blur',
        'announcement', 'announcement_show', 'footer',
        'clock_show', 'default_theme', 'theme_style', 'card_style',
        'default_lan_mode', 'search_engines', 'search_default',
        'site_url',
        'content_maxwidth', 'content_pad_lr', 'content_pad_top', 'content_pad_bottom',
        'weather_show', 'weather_city',
        'search_width',
        'home_view', 'news_sources', 'news_order',
    ];
}

/**
 * uploads 目录下随系统分发的预置资源子目录（相对 uploads 根的目录名）。
 * 这些目录由系统自带（如 weather 预置壁纸），用户不可删除，
 * 备份恢复 / 恢复初始状态清空 uploads 时必须整体保留。
 */
function sp_preset_upload_subdirs(): array
{
    return ['weather'];
}
