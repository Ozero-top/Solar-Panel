package handler

import (
	"net/http"

	"solarpanel/internal/db"
	"solarpanel/internal/model"

	"github.com/gin-gonic/gin"
)

type GroupsHandler struct{}

func NewGroupsHandler() *GroupsHandler { return &GroupsHandler{} }

type groupListItem struct {
	model.ItemGroup
	ItemCount int64 `json:"item_count"`
}

func (h *GroupsHandler) Dispatch(c *gin.Context) {
	action := c.Query("action")
	switch action {
	case "":
		h.List(c)
	case "list":
		h.List(c)
	case "edit":
		h.Edit(c)
	case "delete":
		h.Delete(c)
	case "sort":
		h.Sort(c)
	case "sort_batch":
		h.SortBatch(c)
	case "visible":
		h.SetVisible(c)
	default:
		FailMsg(c, "未知操作")
	}
}

func (h *GroupsHandler) List(c *gin.Context) {
	var groups []model.ItemGroup
	if err := db.DB.Order("sort asc, id asc").Find(&groups).Error; err != nil {
		Fail(c, http.StatusInternalServerError, "读取分组失败")
		return
	}
	result := make([]groupListItem, 0, len(groups))
	for _, g := range groups {
		var cnt int64
		db.DB.Model(&model.Item{}).Where("group_id = ?", g.ID).Count(&cnt)
		result = append(result, groupListItem{ItemGroup: g, ItemCount: cnt})
	}
	Ok(c, result)
}

func (h *GroupsHandler) Edit(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || (sess.Role != "admin" && sess.Role != "editor") {
		Fail(c, http.StatusForbidden, "无操作权限")
		return
	}
	var body struct {
		ID          uint   `json:"id"`
		Title       string `json:"title"`
		Description string `json:"description"`
		Sort        int    `json:"sort"`
		IsVisible   int    `json:"is_visible"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		FailMsg(c, "参数错误")
		return
	}
	if body.Title == "" {
		FailMsg(c, "标题不能为空")
		return
	}

	if body.ID == 0 {
		g := model.ItemGroup{
			Title:       body.Title,
			Description: body.Description,
			Sort:        body.Sort,
			IsVisible:   body.IsVisible,
		}
		if err := db.DB.Create(&g).Error; err != nil {
			FailMsg(c, "创建失败")
			return
		}
		Ok(c, g)
		return
	}

	var g model.ItemGroup
	if err := db.DB.First(&g, body.ID).Error; err != nil {
		FailMsg(c, "分组不存在")
		return
	}
	g.Title = body.Title
	g.Description = body.Description
	g.Sort = body.Sort
	g.IsVisible = body.IsVisible
	if err := db.DB.Save(&g).Error; err != nil {
		FailMsg(c, "保存失败")
		return
	}
	Ok(c, g)
}

func (h *GroupsHandler) Delete(c *gin.Context) {
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
	if err := db.DB.Where("group_id = ?", body.ID).Delete(&model.Item{}).Error; err != nil {
		FailMsg(c, "删除卡片失败")
		return
	}
	if err := db.DB.Delete(&model.ItemGroup{}, body.ID).Error; err != nil {
		FailMsg(c, "删除分组失败")
		return
	}
	Ok(c, nil)
}

func (h *GroupsHandler) Sort(c *gin.Context) {
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

	var cur model.ItemGroup
	if err := db.DB.First(&cur, body.ID).Error; err != nil {
		FailMsg(c, "分组不存在")
		return
	}

	var other model.ItemGroup
	q := db.DB
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

func (h *GroupsHandler) SortBatch(c *gin.Context) {
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
		if err := tx.Model(&model.ItemGroup{}).Where("id = ?", id).Update("sort", i).Error; err != nil {
			tx.Rollback()
			FailMsg(c, "排序失败")
			return
		}
	}
	tx.Commit()
	Ok(c, nil)
}

func (h *GroupsHandler) SetVisible(c *gin.Context) {
	sess := getSession(c)
	if sess == nil || (sess.Role != "admin" && sess.Role != "editor") {
		Fail(c, http.StatusForbidden, "无操作权限")
		return
	}
	var body struct {
		ID      uint `json:"id"`
		Visible int  `json:"visible"`
	}
	if err := c.ShouldBindJSON(&body); err != nil || body.ID == 0 {
		FailMsg(c, "参数错误")
		return
	}
	if body.Visible != 0 {
		body.Visible = 1
	}
	if err := db.DB.Model(&model.ItemGroup{}).Where("id = ?", body.ID).Update("is_visible", body.Visible).Error; err != nil {
		FailMsg(c, "更新失败")
		return
	}
	Ok(c, nil)
}
