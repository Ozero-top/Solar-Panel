package handler

import (
	"html"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strings"

	"github.com/gin-gonic/gin"
)

// ImportHandler 浏览器书签导入
type ImportHandler struct{}

func NewImportHandler() *ImportHandler { return &ImportHandler{} }

func (h *ImportHandler) Dispatch(c *gin.Context) {
	// viewer/guest 无权导入书签（写操作）
	sess := getSession(c)
	if sess == nil {
		c.JSON(http.StatusUnauthorized, gin.H{"code": 401, "msg": "未登录"})
		return
	}
	if sess.Role == "viewer" || sess.Role == "guest" {
		c.JSON(http.StatusForbidden, gin.H{"code": 403, "msg": "只读账号不可导入书签"})
		return
	}
	action := c.Query("action")
	if action == "" {
		action = c.PostForm("action")
	}
	switch action {
	case "bookmarks":
		h.Bookmarks(c)
	default:
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "unknown action: " + action + "，请传 action=bookmarks 上传文件"})
	}
}

// bookmarkEntry 书签条目
type bookmarkEntry struct {
	GroupTitle string // 所属分组标题（H3）
	Title      string // 链接文本
	URL        string // href
	Icon       string // icon URL（可选）
}

// Bookmarks 解析上传的 bookmarks.html，返回分组化的结构（不落库）
// 前端拿到解析结果后让用户确认，再调 apply 落库
func (h *ImportHandler) Bookmarks(c *gin.Context) {
	file, _, err := c.Request.FormFile("file")
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "未收到文件"})
		return
	}
	defer file.Close()

	data, err := io.ReadAll(file)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"code": 1, "msg": "读取文件失败"})
		return
	}

	entries := parseBookmarksHTML(string(data))
	if len(entries) == 0 {
		c.JSON(http.StatusOK, gin.H{"code": 0, "data": gin.H{
			"groups": []gin.H{},
			"total":  0,
		}})
		return
	}

	// 按 GroupTitle 分组
	type group struct {
		Title string  `json:"title"`
		Items []gin.H `json:"items"`
	}
	groupMap := make(map[string]*group)
	var groupOrder []string

	for _, e := range entries {
		gTitle := e.GroupTitle
		if gTitle == "" {
			gTitle = "导入书签"
		}
		if _, ok := groupMap[gTitle]; !ok {
			groupMap[gTitle] = &group{Title: gTitle, Items: []gin.H{}}
			groupOrder = append(groupOrder, gTitle)
		}
		groupMap[gTitle].Items = append(groupMap[gTitle].Items, gin.H{
			"title": e.Title,
			"url":   e.URL,
		})
	}

	var result []gin.H
	var total int
	for _, gTitle := range groupOrder {
		g := groupMap[gTitle]
		if len(g.Items) > 0 {
			result = append(result, gin.H{
				"title": g.Title,
				"count": len(g.Items),
			})
			total += len(g.Items)
		}
	}

	c.JSON(http.StatusOK, gin.H{"code": 0, "data": gin.H{
		"groups": result,
		"total":  total,
	}})
}

// parseBookmarksHTML 解析 Netscape Bookmark 格式
func parseBookmarksHTML(htmlContent string) []bookmarkEntry {
	var entries []bookmarkEntry

	// 规范化：统一换行 + 去 BOM
	s := strings.TrimLeft(htmlContent, "\uFEFF")
	s = strings.ReplaceAll(s, "\r\n", "\n")
	s = strings.ReplaceAll(s, "\r", "\n")

	// 协议白名单（仅 http/https）
	allowedProto := regexp.MustCompile(`^https?://`)

	// 1. 先提取所有 H3（分组标题）及其后面的 <DL><p>
	//    结构：<H3 ...>分组名</H3>\s*<DL><p>...
	//    我们按 H3 切分，每段处理内部的 <A HREF=...>...</A>
	h3Re := regexp.MustCompile(`(?i)<h3[^>]*>(.*?)</h3>`)
	aRe := regexp.MustCompile(`(?i)<a\s+[^>]*?href\s*=\s*["']([^"']+)["'][^>]*>(.*?)</a>`)

	// 找所有 H3 位置
	h3Matches := h3Re.FindAllStringIndex(s, -1)

	// 默认分组：顶层 <A> 标签（在第一个 H3 之前）
	topH3End := 0
	if len(h3Matches) > 0 {
		topH3End = h3Matches[0][0]
	}
	// 只取第一个 H3 之前的顶层 A
	for _, m := range aRe.FindAllStringSubmatchIndex(s, -1) {
		if m[0] < topH3End {
			urlRaw := s[m[2]:m[3]]
			titleRaw := s[m[4]:m[5]]
			urlDecoded, err := url.QueryUnescape(html.UnescapeString(urlRaw))
			if err != nil {
				urlDecoded = html.UnescapeString(urlRaw)
			}
			title := strings.TrimSpace(html.UnescapeString(regexp.MustCompile(`<[^>]+>`).ReplaceAllString(titleRaw, "")))
			if allowedProto.MatchString(urlDecoded) && title != "" {
				entries = append(entries, bookmarkEntry{
					GroupTitle: "",
					Title:      title,
					URL:        urlDecoded,
				})
			}
		}
	}

	// 每个 H3 段内找 A 标签
	for i, h3m := range h3Matches {
		groupTitle := strings.TrimSpace(html.UnescapeString(s[h3m[2]:h3m[3]]))
		if groupTitle == "" {
			groupTitle = "导入分组"
		}

		// 段范围：此 H3 结束 → 下一个 H3 开始
		segStart := h3m[1]
		segEnd := len(s)
		if i+1 < len(h3Matches) {
			segEnd = h3Matches[i+1][0]
		}
		seg := s[segStart:segEnd]

		for _, am := range aRe.FindAllStringSubmatch(seg, -1) {
			urlDecoded, err := url.QueryUnescape(html.UnescapeString(am[1]))
			if err != nil {
				urlDecoded = html.UnescapeString(am[1])
			}
			title := strings.TrimSpace(html.UnescapeString(
				regexp.MustCompile(`<[^>]+>`).ReplaceAllString(am[2], ""),
			))
			if allowedProto.MatchString(urlDecoded) && title != "" {
				entries = append(entries, bookmarkEntry{
					GroupTitle: groupTitle,
					Title:      title,
					URL:        urlDecoded,
				})
			}
		}
	}

	return entries
}
