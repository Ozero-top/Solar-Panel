package handler

import (
	"net/http"
	"net/url"
	"regexp"
	"strconv"

	"solarpanel/internal/db"
	"solarpanel/internal/model"

	"github.com/gin-gonic/gin"
)

// FeedsHandler 自定义 RSS/Atom 源
type FeedsHandler struct{}

func NewFeedsHandler() *FeedsHandler { return &FeedsHandler{} }

var validFeedURLRe = regexp.MustCompile(`^https?://`)

// readAction 不读 body，只读 query / form（避免消耗 body 让后续 handler 无法 ShouldBindJSON）
func readAction(c *gin.Context) string {
	if a := c.Query("action"); a != "" {
		return a
	}
	if a := c.PostForm("action"); a != "" {
		return a
	}
	return ""
}

func (h *FeedsHandler) Dispatch(c *gin.Context) {
	action := readAction(c)
	switch action {
	case "list", "":
		h.List(c)
	case "save":
		h.Save(c)
	case "delete":
		h.Delete(c)
	case "toggle":
		h.Toggle(c)
	default:
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "unknown action: " + action})
	}
}

func (h *FeedsHandler) List(c *gin.Context) {
	var feeds []model.CustomFeed
	db.DB.Order("enabled DESC, sort ASC, id ASC").Find(&feeds)
	c.JSON(http.StatusOK, gin.H{"code": 0, "data": feeds})
}

func (h *FeedsHandler) Save(c *gin.Context) {
	// 支持 JSON body 或 form
	var body map[string]interface{}
	c.ShouldBindJSON(&body)
	get := func(k, def string) string {
		if v, ok := body[k]; ok {
			return toString(v)
		}
		return c.DefaultPostForm(k, c.DefaultQuery(k, def))
	}
	getInt := func(k string, def int) int {
		if v, ok := body[k]; ok {
			return toInt(v, def)
		}
		i, _ := strconv.Atoi(c.DefaultPostForm(k, c.DefaultQuery(k, strconv.Itoa(def))))
		return i
	}

	id := getInt("id", 0)
	title := get("title", "")
	urlStr := get("url", "")
	sort := getInt("sort", 0)
	enabled := getInt("enabled", 1)

	if title == "" {
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "标题不能为空"})
		return
	}
	if !validFeedURLRe.MatchString(urlStr) {
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "URL 必须以 http:// 或 https:// 开头"})
		return
	}
	// —— SSRF 防护：保存时立即校验主机为可达公网地址
	parsed, err := url.Parse(urlStr)
	if err != nil || parsed.Hostname() == "" {
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "URL 格式不合法"})
		return
	}
	if isPrivateHost(parsed.Hostname()) {
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "URL 指向内网/保留地址，已拦截（SSRF 防护）"})
		return
	}

	if id > 0 {
		var feed model.CustomFeed
		if err := db.DB.First(&feed, id).Error; err != nil {
			c.JSON(http.StatusNotFound, gin.H{"code": 1, "msg": "RSS 源不存在"})
			return
		}
		feed.Title = title
		feed.URL = urlStr
		feed.Sort = sort
		feed.Enabled = enabled
		db.DB.Save(&feed)
		c.JSON(http.StatusOK, gin.H{"code": 0, "msg": "已更新"})
	} else {
		feed := model.CustomFeed{Title: title, URL: urlStr, Sort: sort, Enabled: enabled}
		db.DB.Create(&feed)
		c.JSON(http.StatusOK, gin.H{"code": 0, "msg": "已添加", "data": feed})
	}
}

func (h *FeedsHandler) Delete(c *gin.Context) {
	id := bindAnyInt(c, "id", 0)
	if id <= 0 {
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "id 无效"})
		return
	}
	db.DB.Delete(&model.CustomFeed{}, id)
	c.JSON(http.StatusOK, gin.H{"code": 0, "msg": "已删除"})
}

func (h *FeedsHandler) Toggle(c *gin.Context) {
	id := bindAnyInt(c, "id", 0)
	if id <= 0 {
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "id 无效"})
		return
	}
	var feed model.CustomFeed
	if err := db.DB.First(&feed, id).Error; err != nil {
		c.JSON(http.StatusNotFound, gin.H{"code": 1, "msg": "RSS 源不存在"})
		return
	}
	if feed.Enabled == 1 {
		feed.Enabled = 0
	} else {
		feed.Enabled = 1
	}
	db.DB.Save(&feed)
	c.JSON(http.StatusOK, gin.H{"code": 0, "msg": "状态已切换", "data": feed.Enabled})
}

// bindAnyInt 从 JSON/form/query 读 int
func bindAnyInt(c *gin.Context, key string, def int) int {
	var body map[string]interface{}
	c.ShouldBindJSON(&body)
	if v, ok := body[key]; ok {
		return toInt(v, def)
	}
	i, _ := strconv.Atoi(c.DefaultPostForm(key, c.DefaultQuery(key, strconv.Itoa(def))))
	return i
}

func toString(v interface{}) string {
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}

func toInt(v interface{}, def int) int {
	switch n := v.(type) {
	case float64:
		return int(n)
	case int:
		return n
	case string:
		i, _ := strconv.Atoi(n)
		return i
	}
	return def
}
