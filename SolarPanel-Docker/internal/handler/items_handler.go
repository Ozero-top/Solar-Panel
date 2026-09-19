package handler

import (
	"crypto/md5"
	"crypto/tls"
	"encoding/hex"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"solarpanel/internal/config"
	"solarpanel/internal/db"
	"solarpanel/internal/model"

	"github.com/gin-gonic/gin"
)

type ItemsHandler struct {
	cfg *config.Config
}

func NewItemsHandler(cfg *config.Config) *ItemsHandler {
	return &ItemsHandler{cfg: cfg}
}

func isValidURL(s string) bool {
	return strings.HasPrefix(s, "http://") || strings.HasPrefix(s, "https://")
}

func isValidIconType(s string) bool {
	switch s {
	case "image", "text", "favicon":
		return true
	}
	return false
}

func isValidOpenMethod(n int) bool {
	return n == 1 || n == 2 || n == 3
}

var htmlDecodeReplacer = strings.NewReplacer(
	"&amp;", "&",
	"&lt;", "<",
	"&gt;", ">",
	"&quot;", "\"",
	"&#39;", "'",
	"&nbsp;", " ",
)

var (
	reTitle        = regexp.MustCompile(`(?is)<title[^>]*>(.*?)</title>`)
	reOGTitle      = regexp.MustCompile(`(?is)<meta[^>]+(?:property|name)\s*=\s*["']og:title["'][^>]*content\s*=\s*["']([^"']+)["'][^>]*>`)
	reOGTitle2     = regexp.MustCompile(`(?is)<meta[^>]+content\s*=\s*["']([^"']+)["'][^>]*(?:property|name)\s*=\s*["']og:title["'][^>]*>`)
	reTwTitle      = regexp.MustCompile(`(?is)<meta[^>]+name\s*=\s*["']twitter:title["'][^>]*content\s*=\s*["']([^"']+)["'][^>]*>`)
	reTwTitle2     = regexp.MustCompile(`(?is)<meta[^>]+content\s*=\s*["']([^"']+)["'][^>]*name\s*=\s*["']twitter:title["'][^>]*>`)
	reMetaDesc     = regexp.MustCompile(`(?is)<meta[^>]+(?:name|property)\s*=\s*["']description["'][^>]*content\s*=\s*["']([^"']+)["'][^>]*>`)
	reMetaDesc2    = regexp.MustCompile(`(?is)<meta[^>]+content\s*=\s*["']([^"']+)["'][^>]*(?:name|property)\s*=\s*["']description["'][^>]*>`)
	reOGDesc       = regexp.MustCompile(`(?is)<meta[^>]+(?:property|name)\s*=\s*["']og:description["'][^>]*content\s*=\s*["']([^"']+)["'][^>]*>`)
	reOGDesc2      = regexp.MustCompile(`(?is)<meta[^>]+content\s*=\s*["']([^"']+)["'][^>]*(?:property|name)\s*=\s*["']og:description["'][^>]*>`)
	reTwDesc       = regexp.MustCompile(`(?is)<meta[^>]+name\s*=\s*["']twitter:description["'][^>]*content\s*=\s*["']([^"']+)["'][^>]*>`)
	reTwDesc2      = regexp.MustCompile(`(?is)<meta[^>]+content\s*=\s*["']([^"']+)["'][^>]*name\s*=\s*["']twitter:description["'][^>]*>`)
	reOGImage      = regexp.MustCompile(`(?is)<meta[^>]+(?:property|name)\s*=\s*["']og:image["'][^>]*content\s*=\s*["']([^"']+)["'][^>]*>`)
	reOGImage2     = regexp.MustCompile(`(?is)<meta[^>]+content\s*=\s*["']([^"']+)["'][^>]*(?:property|name)\s*=\s*["']og:image["'][^>]*>`)
	reTwImage      = regexp.MustCompile(`(?is)<meta[^>]+name\s*=\s*["']twitter:image["'][^>]*content\s*=\s*["']([^"']+)["'][^>]*>`)
	reTwImage2     = regexp.MustCompile(`(?is)<meta[^>]+content\s*=\s*["']([^"']+)["'][^>]*name\s*=\s*["']twitter:image["'][^>]*>`)
	reKeywords     = regexp.MustCompile(`(?is)<meta[^>]+name\s*=\s*["']keywords["'][^>]*content\s*=\s*["']([^"']+)["'][^>]*>`)
	reKeywords2    = regexp.MustCompile(`(?is)<meta[^>]+content\s*=\s*["']([^"']+)["'][^>]*name\s*=\s*["']keywords["'][^>]*>`)
	reLinkIcon     = regexp.MustCompile(`(?is)<link\b([^>]*)>`)
	reScriptTag    = regexp.MustCompile(`(?is)<script[^>]*>[\s\S]*?</script>`)
	reStyleTag     = regexp.MustCompile(`(?is)<style[^>]*>[\s\S]*?</style>`)
	reEventHandler = regexp.MustCompile(`(?is)\son\w+\s*=\s*"[^"]*"`)
	reForeignObj   = regexp.MustCompile(`(?is)<foreignObject\b[^>]*>[\s\S]*?</foreignObject>`)
	reStripTags    = regexp.MustCompile(`<[^>]+>`)
	reMultiSpace   = regexp.MustCompile(`\s+`)
)

var privateCIDRs []*net.IPNet

func init() {
	cidrs := []string{
		"127.0.0.0/8",
		"10.0.0.0/8",
		"172.16.0.0/12",
		"192.168.0.0/16",
		"0.0.0.0/8",
		"169.254.0.0/16",
	}
	for _, c := range cidrs {
		_, n, err := net.ParseCIDR(c)
		if err == nil {
			privateCIDRs = append(privateCIDRs, n)
		}
	}
}

func isPrivateHost(host string) bool {
	host = strings.TrimSpace(host)
	if host == "" {
		return true
	}
	if ip := net.ParseIP(host); ip != nil {
		for _, c := range privateCIDRs {
			if c.Contains(ip) {
				return true
			}
		}
		return false
	}
	ips, err := net.LookupIP(host)
	if err != nil || len(ips) == 0 {
		return true
	}
	for _, ip := range ips {
		for _, c := range privateCIDRs {
			if c.Contains(ip) {
				return true
			}
		}
	}
	return false
}

func newSafeHTTPClient(timeout time.Duration) *http.Client {
	transport := &http.Transport{
		Proxy:                 nil,
		ForceAttemptHTTP2:     false,
		MaxIdleConns:          10,
		IdleConnTimeout:       30 * time.Second,
		TLSHandshakeTimeout:   10 * time.Second,
		ExpectContinueTimeout: 1 * time.Second,
		// 服务端抓取图标失败，最终回退到在线 URL 而非本地缓存
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
	}
	client := &http.Client{
		Timeout:   timeout,
		Transport: transport,
	}
	client.CheckRedirect = func(req *http.Request, via []*http.Request) error {
		if len(via) >= 5 {
			return fmt.Errorf("too many redirects")
		}
		targetURL := req.URL
		if targetURL.Scheme != "http" && targetURL.Scheme != "https" {
			return fmt.Errorf("redirect to non-http scheme")
		}
		host := targetURL.Hostname()
		if isPrivateHost(host) {
			return fmt.Errorf("redirect to private IP: %s", host)
		}
		return nil
	}
	return client
}

func md5Hex(s string) string {
	h := md5.Sum([]byte(s))
	return hex.EncodeToString(h[:])
}

func absolutize(href, scheme, host string) string {
	href = strings.TrimSpace(href)
	if href == "" {
		return ""
	}
	href = htmlDecodeReplacer.Replace(href)
	if strings.HasPrefix(href, "//") {
		return scheme + ":" + href
	}
	if strings.HasPrefix(href, "http://") || strings.HasPrefix(href, "https://") {
		return href
	}
	if strings.HasPrefix(href, "/") {
		return scheme + "://" + host + href
	}
	// 对齐 PHP ltrim($href, './')：去除相对路径前导的 ./
	return scheme + "://" + host + "/" + strings.TrimLeft(href, "./")
}

func extractIconLinks(html, scheme, host string) []string {
	var results []string
	seen := make(map[string]bool)
	add := func(u string) {
		if u == "" || seen[u] {
			return
		}
		seen[u] = true
		results = append(results, u)
	}

	matches := reLinkIcon.FindAllStringSubmatch(html, -1)
	type cand struct {
		rel  string
		href string
	}
	var cands []cand
	for _, m := range matches {
		attrs := m[1]
		relMatch := regexp.MustCompile(`(?is)\brel\s*=\s*["']([^"']+)["']`).FindStringSubmatch(attrs)
		hrefMatch := regexp.MustCompile(`(?is)\bhref\s*=\s*["']([^"']+)["']`).FindStringSubmatch(attrs)
		if relMatch == nil || hrefMatch == nil {
			continue
		}
		rel := strings.ToLower(strings.TrimSpace(relMatch[1]))
		href := strings.TrimSpace(hrefMatch[1])
		cands = append(cands, cand{rel: rel, href: href})
	}

	for _, c := range cands {
		if strings.Contains(c.rel, "icon") {
			add(absolutize(c.href, scheme, host))
		}
	}
	for _, c := range cands {
		if c.rel == "shortcut icon" || c.rel == "shortcut" {
			add(absolutize(c.href, scheme, host))
		}
	}

	add(scheme + "://" + host + "/favicon.ico")
	return results
}

func sanitizeSVG(data []byte) []byte {
	s := string(data)
	s = reScriptTag.ReplaceAllString(s, "")
	s = reEventHandler.ReplaceAllString(s, "")
	s = reForeignObj.ReplaceAllString(s, "")
	return []byte(s)
}

func extFromURL(rawURL string) string {
	u, err := url.Parse(rawURL)
	if err != nil {
		return ""
	}
	p := u.Path
	idx := strings.LastIndex(p, ".")
	if idx == -1 {
		return ""
	}
	ext := strings.ToLower(p[idx:])
	if len(ext) > 6 {
		return ""
	}
	return ext
}

func fetchPageHTML(rawURL string) (html, scheme, host string, err error) {
	u, err := url.Parse(rawURL)
	if err != nil {
		return "", "", "", err
	}
	scheme = u.Scheme
	host = u.Hostname()
	if scheme != "http" && scheme != "https" {
		return "", "", "", fmt.Errorf("unsupported scheme")
	}
	if isPrivateHost(host) {
		return "", "", "", fmt.Errorf("private host")
	}

	client := newSafeHTTPClient(10 * time.Second)
	req, err := http.NewRequest("GET", rawURL, nil)
	if err != nil {
		return "", "", "", err
	}
	// 使用与 PHP 版本一致的浏览器 User-Agent，避免被目标站点拒绝
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36")
	req.Header.Set("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8")
	req.Header.Set("Accept-Language", "zh-CN,zh;q=0.9,en;q=0.8")

	resp, err := client.Do(req)
	if err != nil {
		return "", "", "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 400 {
		return "", "", "", fmt.Errorf("http status %d", resp.StatusCode)
	}

	ct := resp.Header.Get("Content-Type")
	if !strings.Contains(strings.ToLower(ct), "text/html") && !strings.Contains(strings.ToLower(ct), "application/xhtml") {
		return "", "", "", fmt.Errorf("not html: %s", ct)
	}

	body, err := io.ReadAll(io.LimitReader(resp.Body, 5*1024*1024))
	if err != nil {
		return "", "", "", err
	}
	return string(body), scheme, host, nil
}

func fetchIconBytes(rawURL string) ([]byte, string, error) {
	u, err := url.Parse(rawURL)
	if err != nil {
		return nil, "", err
	}
	if u.Scheme != "http" && u.Scheme != "https" {
		return nil, "", fmt.Errorf("unsupported scheme")
	}
	if isPrivateHost(u.Hostname()) {
		return nil, "", fmt.Errorf("private host")
	}

	client := newSafeHTTPClient(8 * time.Second)
	req, err := http.NewRequest("GET", rawURL, nil)
	if err != nil {
		return nil, "", err
	}
	// 使用与 PHP 版本一致的浏览器 User-Agent，避免被目标站点拒绝
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36")
	req.Header.Set("Accept", "image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8")
	req.Header.Set("Accept-Language", "zh-CN,zh;q=0.9,en;q=0.8")

	resp, err := client.Do(req)
	if err != nil {
		return nil, "", err
	}
	defer resp.Body.Close()

	// 对齐 PHP：不校验 Content-Type，依赖后续 getimagesizefromstring（magic byte）判定
	// 部分 favicon 服务返回 application/x-icon 等非 image/* 类型，PHP 同样接受
	if resp.StatusCode < 200 || resp.StatusCode >= 400 {
		return nil, "", fmt.Errorf("http status %d", resp.StatusCode)
	}

	cl := resp.Header.Get("Content-Length")
	if cl != "" {
		var n int64
		fmt.Sscanf(cl, "%d", &n)
		if n > 2*1024*1024 {
			return nil, "", fmt.Errorf("too large")
		}
	}

	ct := strings.ToLower(resp.Header.Get("Content-Type"))

	body, err := io.ReadAll(io.LimitReader(resp.Body, 2*1024*1024+1))
	if err != nil {
		return nil, "", err
	}
	if len(body) > 2*1024*1024 {
		return nil, "", fmt.Errorf("too large")
	}
	return body, ct, nil
}

var faviconExts = []string{".png", ".ico", ".svg", ".jpg", ".gif", ".webp"}

func checkFaviconCache(cfg *config.Config, host string) string {
	md5h := md5Hex(host)
	dir := filepath.Join(cfg.UploadDir, "favicon")
	for _, ext := range faviconExts {
		p := filepath.Join(dir, md5h+ext)
		info, err := os.Stat(p)
		if err != nil {
			continue
		}
		if time.Since(info.ModTime()) > 30*24*time.Hour {
			os.Remove(p)
			continue
		}
		return "/frontend/uploads/favicon/" + md5h + ext
	}
	return ""
}

func saveFavicon(cfg *config.Config, host, ext string, data []byte) string {
	md5h := md5Hex(host)
	dir := filepath.Join(cfg.UploadDir, "favicon")
	os.MkdirAll(dir, 0755)
	if ext == "" {
		ext = ".png"
	}
	if !strings.HasPrefix(ext, ".") {
		ext = "." + ext
	}
	validExt := false
	for _, e := range faviconExts {
		if e == ext {
			validExt = true
			break
		}
	}
	if !validExt {
		ext = ".png"
	}

	if ext == ".svg" {
		data = sanitizeSVG(data)
	}

	p := filepath.Join(dir, md5h+ext)
	if err := os.WriteFile(p, data, 0644); err != nil {
		return ""
	}
	// 对齐 PHP：写入后校验文件存在且 >= 50 字节，失败则返回空让上层尝试下一候选
	info, err := os.Stat(p)
	if err != nil || info.Size() < 50 {
		os.Remove(p)
		return ""
	}
	return "/frontend/uploads/favicon/" + md5h + ext
}

var faviconFallbackServices = []string{
	"https://api.iowen.cn/favicon/%s.png",
	"https://favicon.cccyun.cc/%s",
	"https://favicon.im/%s?larger=true",
	"https://icon.horse/icon/%s",
	"https://www.google.com/s2/favicons?domain=%s&sz=64",
}

func extractExtFromCT(ct string) string {
	switch {
	case strings.Contains(ct, "svg"):
		return ".svg"
	case strings.Contains(ct, "png"):
		return ".png"
	case strings.Contains(ct, "jpeg") || strings.Contains(ct, "jpg"):
		return ".jpg"
	case strings.Contains(ct, "gif"):
		return ".gif"
	case strings.Contains(ct, "webp"):
		return ".webp"
	case strings.Contains(ct, "x-icon") || strings.Contains(ct, "icon"):
		return ".ico"
	default:
		return ""
	}
}

// detectImageExt 通过二进制 magic byte 判定图片真实类型（对齐 PHP getimagesizefromstring）
// 避免把 HTML 错误页等非图片内容当成图标缓存；无法识别时返回空字符串
func detectImageExt(data []byte) string {
	if len(data) < 4 {
		return ""
	}
	// PNG: 89 50 4E 47
	if data[0] == 0x89 && data[1] == 0x50 && data[2] == 0x4E && data[3] == 0x47 {
		return ".png"
	}
	// JPEG: FF D8 FF
	if data[0] == 0xFF && data[1] == 0xD8 && data[2] == 0xFF {
		return ".jpg"
	}
	// GIF: 47 49 46 38
	if data[0] == 0x47 && data[1] == 0x49 && data[2] == 0x46 && data[3] == 0x38 {
		return ".gif"
	}
	// ICO: 00 00 01 00
	if data[0] == 0x00 && data[1] == 0x00 && data[2] == 0x01 && data[3] == 0x00 {
		return ".ico"
	}
	// WebP: 52 49 46 46 ... 57 45 42 50
	if len(data) >= 12 && data[0] == 0x52 && data[1] == 0x49 && data[2] == 0x46 && data[3] == 0x46 &&
		data[8] == 0x57 && data[9] == 0x45 && data[10] == 0x42 && data[11] == 0x50 {
		return ".webp"
	}
	// SVG: 文本且包含 <svg
	if strings.Contains(string(data[:min(len(data), 512)]), "<svg") {
		return ".svg"
	}
	return ""
}

func trySaveIconFromCandidates(cfg *config.Config, host string, candidates []string) string {
	for _, iconURL := range candidates {
		if iconURL == "" {
			continue
		}
		if isPrivateHostMustParse(iconURL) {
			continue
		}
		data, ct, err := fetchIconBytes(iconURL)
		if err != nil {
			continue
		}
		if len(data) < 50 {
			continue
		}
		// 优先级：magic byte 实测 > Content-Type > URL 扩展名（对齐 PHP getimagesizefromstring）
		ext := detectImageExt(data)
		if ext == "" {
			ext = extractExtFromCT(ct)
		}
		if ext == "" {
			ext = extFromURL(iconURL)
		}
		// 扩展名白名单校验（对齐 PHP extMap），非法类型跳过该候选
		validExt := false
		for _, e := range faviconExts {
			if e == ext {
				validExt = true
				break
			}
		}
		if !validExt {
			continue
		}
		saved := saveFavicon(cfg, host, ext, data)
		if saved != "" {
			return saved
		}
	}
	return ""
}

func trySaveIcon(cfg *config.Config, rawURL string) string {
	u, err := url.Parse(rawURL)
	if err != nil {
		return ""
	}
	scheme := u.Scheme
	host := u.Hostname()
	if scheme != "http" && scheme != "https" {
		return ""
	}
	if isPrivateHost(host) {
		return ""
	}

	cached := checkFaviconCache(cfg, host)
	if cached != "" {
		return cached
	}

	// 构建候选列表（与 PHP 原版对齐：页面声明 → /favicon.ico → 公共图标服务）
	var candidates []string

	html, s, h, err := fetchPageHTML(rawURL)
	if err == nil {
		scheme = s
		host = h
		candidates = extractIconLinks(html, scheme, host)
	} else {
		// 页面无法访问，至少尝试 /favicon.ico
		candidates = append(candidates, scheme+"://"+host+"/favicon.ico")
	}

	// 追加公共图标服务作为备选源（国内优先）
	for _, tmpl := range faviconFallbackServices {
		candidates = append(candidates, fmt.Sprintf(tmpl, host))
	}

	return trySaveIconFromCandidates(cfg, host, candidates)
}

func isPrivateHostMustParse(rawURL string) bool {
	u, err := url.Parse(rawURL)
	if err != nil {
		return true
	}
	return isPrivateHost(u.Hostname())
}

func fallbackFaviconURL(host string) string {
	for _, tmpl := range faviconFallbackServices {
		return fmt.Sprintf(tmpl, host)
	}
	return ""
}

func extractMeta(html string) (title, description, favicon string) {
	title = ""
	description = ""

	if m := reOGTitle.FindStringSubmatch(html); m != nil {
		title = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
	} else if m := reOGTitle2.FindStringSubmatch(html); m != nil {
		title = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
	} else if m := reTwTitle.FindStringSubmatch(html); m != nil {
		title = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
	} else if m := reTwTitle2.FindStringSubmatch(html); m != nil {
		title = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
	}

	if title == "" {
		if m := reTitle.FindStringSubmatch(html); m != nil {
			t := reStripTags.ReplaceAllString(m[1], "")
			title = strings.TrimSpace(htmlDecodeReplacer.Replace(t))
		}
	}

	if m := reOGDesc.FindStringSubmatch(html); m != nil {
		description = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
	} else if m := reOGDesc2.FindStringSubmatch(html); m != nil {
		description = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
	} else if m := reTwDesc.FindStringSubmatch(html); m != nil {
		description = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
	} else if m := reTwDesc2.FindStringSubmatch(html); m != nil {
		description = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
	}

	if description == "" {
		if m := reMetaDesc.FindStringSubmatch(html); m != nil {
			description = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
		} else if m := reMetaDesc2.FindStringSubmatch(html); m != nil {
			description = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
		}
	}

	if description == "" {
		if m := reKeywords.FindStringSubmatch(html); m != nil {
			description = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
		} else if m := reKeywords2.FindStringSubmatch(html); m != nil {
			description = strings.TrimSpace(htmlDecodeReplacer.Replace(m[1]))
		}
	}

	if description == "" {
		// PHP 原版智能兜底：去脚本/样式后截取正文纯文本
		text := reScriptTag.ReplaceAllString(html, " ")
		text = reStyleTag.ReplaceAllString(text, " ")
		text = reStripTags.ReplaceAllString(text, " ")
		text = reMultiSpace.ReplaceAllString(text, " ")
		text = strings.TrimSpace(htmlDecodeReplacer.Replace(text))
		runes := []rune(text)
		if len(runes) > 80 {
			description = string(runes[:80])
		} else {
			description = text
		}
	}

	// PHP 原版：title 折叠空白后截 50 字符；desc 截 200 字符
	title = strings.TrimSpace(reMultiSpace.ReplaceAllString(title, " "))
	description = strings.TrimSpace(reMultiSpace.ReplaceAllString(description, " "))
	if runes := []rune(title); len(runes) > 50 {
		title = string(runes[:50])
	}
	if runes := []rune(description); len(runes) > 200 {
		description = string(runes[:200])
	}

	return title, description, favicon
}

func (h *ItemsHandler) Dispatch(c *gin.Context) {
	action := c.Query("action")
	switch action {
	case "", "list":
		h.List(c)
	case "edit":
		h.Edit(c)
	case "delete":
		h.Delete(c)
	case "sort":
		h.Sort(c)
	case "sort_batch":
		h.SortBatch(c)
	case "favicon":
		h.Favicon(c)
	case "fetch_meta":
		h.FetchMeta(c)
	default:
		FailMsg(c, "未知操作")
	}
}

func (h *ItemsHandler) PublicDispatch(c *gin.Context) {
	action := c.Query("action")
	switch action {
	case "", "list":
		h.List(c)
	case "favicon":
		h.Favicon(c)
	case "fetch_meta":
		h.FetchMeta(c)
	default:
		FailMsg(c, "未知操作")
	}
}

func (h *ItemsHandler) Favicon(c *gin.Context) {
	rawURL := c.Query("url")
	if rawURL == "" {
		FailMsg(c, "url 不能为空")
		return
	}
	if !isValidURL(rawURL) {
		FailMsg(c, "URL 必须以 http:// 或 https:// 开头")
		return
	}

	if h.cfg == nil {
		FailMsg(c, "handler 未初始化")
		return
	}

	u, err := url.Parse(rawURL)
	if err != nil {
		FailMsg(c, "URL 解析失败")
		return
	}
	host := u.Hostname()
	if isPrivateHost(host) {
		FailMsg(c, "禁止访问内网地址")
		return
	}

	cached := checkFaviconCache(h.cfg, host)
	if cached != "" {
		// PHP 原版：ok(['url' => $local, 'cached' => true, 'fallback' => false])
		Ok(c, gin.H{"url": cached, "cached": true, "fallback": false})
		return
	}

	result := trySaveIcon(h.cfg, rawURL)
	if result != "" {
		Ok(c, gin.H{"url": result, "cached": strings.HasPrefix(result, "/frontend/uploads/"), "fallback": false})
		return
	}

	// PHP 原版 fallback：ok(['url' => 公共图标URL, 'cached' => false, 'fallback' => true, 'diag' => ...])
	fallbackURL := fallbackFaviconURL(host)
	Ok(c, gin.H{"url": fallbackURL, "cached": false, "fallback": true, "diag": "无法抓取目标站点图标，已改用公共图标服务"})
}

func (h *ItemsHandler) FetchMeta(c *gin.Context) {
	rawURL := c.Query("url")
	if rawURL == "" {
		FailMsg(c, "url 不能为空")
		return
	}
	if !isValidURL(rawURL) {
		FailMsg(c, "URL 必须以 http:// 或 https:// 开头")
		return
	}

	if h.cfg == nil {
		FailMsg(c, "handler 未初始化")
		return
	}

	u, err := url.Parse(rawURL)
	if err != nil {
		FailMsg(c, "URL 解析失败")
		return
	}
	host := u.Hostname()
	if isPrivateHost(host) {
		FailMsg(c, "禁止访问内网地址")
		return
	}

	resp := gin.H{
		"title":         "",
		"description":   "",
		"icon":          "",
		"fallback_icon": false,
		"warn":          "",
	}

	scheme := u.Scheme
	if scheme != "http" && scheme != "https" {
		scheme = "https"
	}

	cached := checkFaviconCache(h.cfg, host)
	if cached != "" {
		resp["icon"] = cached
	}

	// 完整抓取用户给的 URL（含 path + query）—— 标题/描述解析才精准
	var candidates []string

	// 用原始 rawURL 而非 scheme://host/ 根路径
	html, pageScheme, pageHost, err := fetchPageHTML(rawURL)
	if err == nil {
		title, desc, _ := extractMeta(html)
		resp["title"] = title
		resp["description"] = desc
		scheme = pageScheme
		candidates = extractIconLinks(html, pageScheme, pageHost)

		// 扩展图标候选：og:image / twitter:image（比 favicon 更清晰）
		addMetaImg := func(patterns ...*regexp.Regexp) {
			for _, re := range patterns {
				if m := re.FindStringSubmatch(html); m != nil {
					abs := absolutize(strings.TrimSpace(htmlDecodeReplacer.Replace(m[1])), scheme, host)
					if abs != "" {
						seen := false
						for _, c := range candidates {
							if c == abs {
								seen = true
								break
							}
						}
						if !seen {
							candidates = append(candidates, abs)
						}
					}
				}
			}
		}
		addMetaImg(reOGImage, reOGImage2, reTwImage, reTwImage2)
	} else {
		resp["warn"] = "无法访问目标站点：" + err.Error()
	}

	// 如果缓存未命中，尝试用候选列表缓存图标（含公共图标服务兜底）
	if resp["icon"] == "" {
		// 确保有 /favicon.ico 候选
		hasFaviconIco := false
		for _, c := range candidates {
			if strings.Contains(c, "/favicon.ico") {
				hasFaviconIco = true
				break
			}
		}
		if !hasFaviconIco {
			candidates = append(candidates, scheme+"://"+host+"/favicon.ico")
		}
		// 追加公共图标服务
		for _, tmpl := range faviconFallbackServices {
			candidates = append(candidates, fmt.Sprintf(tmpl, host))
		}
		if saved := trySaveIconFromCandidates(h.cfg, host, candidates); saved != "" {
			resp["icon"] = saved
		}
	}

	// icon 兜底：公共图标服务（前端 fallback_icon=true 表示浏览器直接加载远程）
	if resp["icon"] == "" {
		resp["icon"] = fallbackFaviconURL(host)
		resp["fallback_icon"] = true
	}

	Ok(c, resp)
}

func (h *ItemsHandler) List(c *gin.Context) {
	q := db.DB.Order("sort asc, id asc")
	if gid := c.Query("group_id"); gid != "" {
		q = q.Where("group_id = ?", gid)
	}
	var items []model.Item
	if err := q.Find(&items).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "读取卡片失败")
		return
	}
	Ok(c, items)
}

func (h *ItemsHandler) Edit(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || (sess.Role != "admin" && sess.Role != "editor") {
		Fail(c, http.StatusForbidden, "无操作权限")
		return
	}
	var body struct {
		ID          uint   `json:"id"`
		GroupID     uint   `json:"group_id"`
		Title       string `json:"title"`
		URL         string `json:"url"`
		LanURL      string `json:"lan_url"`
		Description string `json:"description"`
		IconType    string `json:"icon_type"`
		IconValue   string `json:"icon_value"`
		IconBG      string `json:"icon_bg"`
		OpenMethod  int    `json:"open_method"`
		Sort        int    `json:"sort"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		FailMsg(c, "参数错误")
		return
	}
	if body.GroupID == 0 {
		FailMsg(c, "请选择分组")
		return
	}
	if body.Title == "" {
		FailMsg(c, "标题不能为空")
		return
	}
	if body.URL != "" && !isValidURL(body.URL) {
		FailMsg(c, "URL 必须以 http:// 或 https:// 开头")
		return
	}
	if body.LanURL != "" && !isValidURL(body.LanURL) {
		FailMsg(c, "LAN URL 必须以 http:// 或 https:// 开头")
		return
	}
	if !isValidIconType(body.IconType) {
		FailMsg(c, "icon_type 必须是 image/text/favicon")
		return
	}
	if body.OpenMethod == 0 {
		body.OpenMethod = 2
	}
	if !isValidOpenMethod(body.OpenMethod) {
		FailMsg(c, "open_method 必须是 1/2/3")
		return
	}

	if body.ID == 0 {
		it := model.Item{
			GroupID:     body.GroupID,
			Title:       body.Title,
			URL:         body.URL,
			LanURL:      body.LanURL,
			Description: body.Description,
			IconType:    body.IconType,
			IconValue:   body.IconValue,
			IconBG:      body.IconBG,
			OpenMethod:  body.OpenMethod,
			Sort:        body.Sort,
		}
		if err := db.DB.Create(&it).Error; err != nil {
			FailMsg(c, "创建失败")
			return
		}
		Ok(c, it)
		return
	}

	var it model.Item
	if err := db.DB.First(&it, body.ID).Error; err != nil {
		FailMsg(c, "卡片不存在")
		return
	}
	it.GroupID = body.GroupID
	it.Title = body.Title
	it.URL = body.URL
	it.LanURL = body.LanURL
	it.Description = body.Description
	it.IconType = body.IconType
	it.IconValue = body.IconValue
	it.IconBG = body.IconBG
	it.OpenMethod = body.OpenMethod
	it.Sort = body.Sort
	if err := db.DB.Save(&it).Error; err != nil {
		FailMsg(c, "保存失败")
		return
	}
	Ok(c, it)
}

func (h *ItemsHandler) Delete(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || (sess.Role != "admin" && sess.Role != "editor") {
		Fail(c, http.StatusForbidden, "无操作权限")
		return
	}
	var body struct {
		ID uint `json:"id"`
	}
	if err := c.ShouldBindJSON(&body); err != nil || body.ID == 0 {
		FailMsg(c, "参数错误")
		return
	}
	if err := db.DB.Delete(&model.Item{}, body.ID).Error; err != nil {
		FailMsg(c, "删除失败")
		return
	}
	Ok(c, nil)
}

func (h *ItemsHandler) Sort(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || (sess.Role != "admin" && sess.Role != "editor") {
		Fail(c, http.StatusForbidden, "无操作权限")
		return
	}
	var body struct {
		ID  uint   `json:"id"`
		Dir string `json:"dir"`
	}
	if err := c.ShouldBindJSON(&body); err != nil || body.ID == 0 {
		FailMsg(c, "参数错误")
		return
	}

	var cur model.Item
	if err := db.DB.First(&cur, body.ID).Error; err != nil {
		FailMsg(c, "卡片不存在")
		return
	}

	var other model.Item
	q := db.DB.Where("group_id = ?", cur.GroupID)
	if body.Dir == "up" {
		q = q.Where("sort < ?", cur.Sort).Order("sort desc, id desc")
	} else {
		q = q.Where("sort > ?", cur.Sort).Order("sort asc, id asc")
	}
	if err := q.First(&other).Error; err != nil {
		FailMsg(c, (map[string]string{"up": "已经是第一个", "down": "已经是最后一个"})[body.Dir])
		return
	}

	curSort := cur.Sort
	cur.Sort = other.Sort
	other.Sort = curSort

	if err := db.DB.Save(&cur).Error; err != nil {
		FailMsg(c, "排序失败")
		return
	}
	if err := db.DB.Save(&other).Error; err != nil {
		FailMsg(c, "排序失败")
		return
	}
	Ok(c, nil)
}

func (h *ItemsHandler) SortBatch(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || (sess.Role != "admin" && sess.Role != "editor") {
		Fail(c, http.StatusForbidden, "无操作权限")
		return
	}
	var body struct {
		IDs []uint `json:"ids"`
	}
	if err := c.ShouldBindJSON(&body); err != nil || len(body.IDs) == 0 {
		FailMsg(c, "参数错误")
		return
	}
	tx := db.DB.Begin()
	for i, id := range body.IDs {
		if err := tx.Model(&model.Item{}).Where("id = ?", id).Update("sort", i).Error; err != nil {
			tx.Rollback()
			FailMsg(c, "排序失败")
			return
		}
	}
	tx.Commit()
	Ok(c, nil)
}
