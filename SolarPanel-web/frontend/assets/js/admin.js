/**
 * 管理后台逻辑
 */
const API_BASE = '../backend/api/';

const state = {
  groups: [],
  items: [],
  currentGroup: 0, // 0 = 全部
  itemPage: 1, // 卡片列表当前页码（每页 ITEM_PAGE_SIZE 条）
  editingItem: null,
  groupsOrderDirty: false, // 分组拖拽后有未保存的排序
  siteUrl: '', // 后台设置的站点地址（退出登录跳转用）
  role: 'admin', // 当前登录用户权限组：admin / editor / viewer
};

const ITEM_PAGE_SIZE = 10; // 卡片管理每页条数

const ROLE_LABEL = { admin: '管理员', editor: '编辑者', viewer: '只读' };

/* ================= 初始化 ================= */
(async function boot() {
  try {
    const u = await API.get(API_BASE + 'auth.php?action=me');
    if (!u) {
      location.replace('login.html');
      return;
    }
    state.role = u.role || 'admin';
    document.getElementById('whoami').textContent = u.name || u.username;
    document.getElementById('accName').value = u.name || '';
    const accUser = document.getElementById('accUsername');
    const accBadge = document.getElementById('accRoleBadge');
    if (accUser) accUser.textContent = u.username || '—';
    if (accBadge) {
      accBadge.textContent = ROLE_LABEL[state.role] || state.role;
      accBadge.classList.add('role-' + state.role);
    }
  } catch (e) {
    if (e.code === 401) {
      location.replace('login.html');
      return;
    }
    toast(e.message, 'error');
  }

  applyThemeFromBackend();
  bindTabs();
  bindOrderGuard();
  bindModals();
  bindUploads();
  await loadGroups();
  await loadItems();
  bindItems();
  bindGroups();
  bindDragSort();
  bindSettings();
  bindSettingsSubnav();
  bindNewsSourceDrag();
  bindAccount();
  bindBackup();
  bindVersionCheck();
  bindUpgrade();
  renderVersionInfo();
  applyRoleUI();
})();

/* ================= 按权限组显示 / 隐藏后台功能 ================= */
function applyRoleUI() {
  if (state.role === 'viewer') {
    // 只读：可查看全部标签页与所有设置，但一切写操作禁用
    document.body.classList.add('role-viewer');
    // 用户管理区块隐藏（不能增删用户 / 改权限）
    const um = document.getElementById('userManageBlock');
    if (um) um.style.display = 'none';
    // 站点设置：所有表单控件禁用（灰色不可操作），但保留二级导航可切换查看
    document.querySelectorAll('#tab-settings input, #tab-settings select, #tab-settings textarea, #tab-settings button, #tab-backup select, #tab-backup button').forEach(el => {
      if (el.closest('.settings-subnav')) return; // 分区切换按钮保留可用
      if (el.id === 'changelogHistoryBtn') return;    // 更新日志为只读信息，历史更新按钮保留可用
      el.disabled = true;
    });
    // 账号页：禁用修改密码与显示名称（只读账号无写权限）
    document.querySelectorAll('#pwdForm input, #pwdForm button, #accName, #saveNameBtn').forEach(el => { el.disabled = true; });
  } else if (state.role !== 'admin') {
    // 编辑者：不可见站点设置与用户管理（内容管理可用）
    const settingsTab = document.querySelector('.admin-tabs .tab[data-tab="settings"]');
    if (settingsTab) settingsTab.style.display = 'none';
    // 备份与更新：仅管理员（升级 / 导入 / 清空为高危写操作）
    const backupTab = document.querySelector('.admin-tabs .tab[data-tab="backup"]');
    if (backupTab) backupTab.style.display = 'none';
    // 默认页签为站点设置，编辑者不可见 → 回退到卡片管理
    const firstTab = document.querySelector('.admin-tabs .tab[data-tab="items"]');
    if (firstTab) activateTab(firstTab);
    // 用户管理区块仅管理员可见（账号页保留：修改名称 / 修改密码）
    const um = document.getElementById('userManageBlock');
    if (um) um.style.display = 'none';
  }
}

async function applyThemeFromBackend() {
  try {
    const s = await API.get(API_BASE + 'settings.php?action=list');
    applyTheme(s);
    state.siteUrl = (s.site_url || '').trim();
    fillSettingsForm(s);
  } catch (e) {
    toast('设置读取失败：' + e.message, 'error');
  }
}

/* ================= 标签页 ================= */
let pendingTabSwitch = null; // 未保存排序弹窗挂起的切换目标
function activateTab(tab) {
  document.querySelectorAll('.admin-tabs .tab').forEach(t => t.classList.remove('active'));
  tab.classList.add('active');
  document.querySelectorAll('.admin-panel').forEach(p => { p.hidden = true; });
  document.getElementById('tab-' + tab.dataset.tab).hidden = false;
}

function bindTabs() {
  document.querySelectorAll('.admin-tabs .tab').forEach(tab => {
    tab.addEventListener('click', () => {
      // 有未保存的分组拖拽排序时，弹主题化确认弹窗（保存 / 放弃 / 留下）
      if (state.groupsOrderDirty && !tab.classList.contains('active')) {
        pendingTabSwitch = tab;
        document.getElementById('orderGuardModal').classList.add('show');
        return;
      }
      activateTab(tab);
    });
  });
  // 关闭 / 刷新页面时同样拦截未保存的排序（浏览器原生确认，无法样式化）
  window.addEventListener('beforeunload', e => {
    if (state.groupsOrderDirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
}

/** 未保存排序确认弹窗：保存并切换 / 放弃并切换 / 留在此页 */
function bindOrderGuard() {
  const modal = document.getElementById('orderGuardModal');
  const close = () => { modal.classList.remove('show'); pendingTabSwitch = null; };
  document.getElementById('guardStayBtn').onclick = close;
  document.getElementById('guardDiscardBtn').onclick = () => {
    const tab = pendingTabSwitch;
    close();
    setGroupsOrderDirty(false);
    // 重新拉取数据，把内存中的脏顺序还原为已保存状态
    loadGroups();
    if (tab) activateTab(tab);
  };
  document.getElementById('guardSaveAndSwitchBtn').onclick = async () => {
    const tab = pendingTabSwitch;
    const btn = document.getElementById('guardSaveAndSwitchBtn');
    btn.disabled = true;
    try {
      if (state.groupsOrderDirty) await saveGroupsOrder();
      modal.classList.remove('show');
      pendingTabSwitch = null;
      toast('排序已保存', 'success');
      if (tab) activateTab(tab);
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      btn.disabled = false;
    }
  };
}

/* ================= 弹窗通用 ================= */
function bindModals() {
  document.querySelectorAll('[data-close]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById(btn.dataset.close).classList.remove('show');
    });
  });
  document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => {
      if (e.target === m) m.classList.remove('show');
    });
  });
  document.getElementById('logoutBtn').addEventListener('click', async () => {
    try { await API.post(API_BASE + 'auth.php?action=logout'); } catch (e) { /* 忽略 */ }
    // 优先跳转后台设置的站点地址（域名/内网地址），未设置时回登录页
    location.href = state.siteUrl !== '' ? state.siteUrl : 'login.html';
  });
}

/* ================= 分组 ================= */
async function loadGroups() {
  try {
    state.groups = await API.get(API_BASE + 'groups.php?action=list');
    setGroupsOrderDirty(false);
  } catch (e) {
    toast('分组加载失败：' + e.message, 'error');
    state.groups = [];
  }
  renderGroupFilter();
  renderGroupsTable();
  renderGroupOptions();
}

function renderGroupFilter() {
  const sel = document.getElementById('itemGroupFilter');
  sel.innerHTML = '<option value="0">全部分组</option>';
  state.groups.forEach(g => {
    const opt = document.createElement('option');
    opt.value = String(g.id);
    opt.textContent = g.title + ' (' + g.item_count + ')';
    sel.appendChild(opt);
  });
  sel.value = String(state.currentGroup);
  sel.onchange = () => {
    state.currentGroup = Number(sel.value);
    state.itemPage = 1; // 切换分组后回到第一页
    loadItems();
  };
}

function renderGroupOptions() {
  const sel = document.getElementById('i_group');
  sel.innerHTML = '';
  state.groups.forEach(g => {
    const opt = document.createElement('option');
    opt.value = String(g.id);
    opt.textContent = g.title;
    sel.appendChild(opt);
  });
}

function renderGroupsTable() {
  const tbody = document.getElementById('groupsTbody');
  tbody.innerHTML = '';
  if (!state.groups.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-muted)">暂无分组，点击右上角「添加分组」创建</td></tr>';
    return;
  }
  state.groups.forEach((g, i) => {
    const vis = (g.is_visible ?? 1) ? 1 : 0;
    const tr = document.createElement('tr');
    tr.dataset.idx = i;
    if (!vis) tr.classList.add('row-hidden');
    tr.innerHTML =
      '<td class="drag-cell"><span class="drag-handle" title="拖动调整顺序">⠿</span></td>' +
      '<td>' + esc(g.title) + '</td>' +
      '<td style="color:var(--text-muted)">' + esc(g.description) + '</td>' +
      '<td>' + g.item_count + '</td>' +
      '<td class="vis-cell">' +
        '<span class="vis-badge' + (vis ? ' on' : '') + '">' + (vis ? '显示' : '隐藏') + '</span>' +
        '<button class="btn btn-sm btn-vis" data-act="vis">' + (vis ? '隐藏' : '显示') + '</button>' +
      '</td>' +
      '<td><div class="actions">' +
      '<button class="btn btn-sm" data-act="up">上移</button>' +
      '<button class="btn btn-sm" data-act="down">下移</button>' +
      '<button class="btn btn-sm" data-act="edit">编辑</button>' +
      '<button class="btn btn-sm btn-danger" data-act="del">删除</button>' +
      '</div></td>';
    tr.querySelectorAll('[data-act]').forEach(btn => {
      btn.onclick = () => groupAction(btn.dataset.act, g);
    });
    tbody.appendChild(tr);
  });
}

function groupAction(act, g) {
  if (act === 'vis') {
    const toHide = (g.is_visible ?? 1) ? 1 : 0;
    API.post(API_BASE + 'groups.php?action=visible', { id: g.id, visible: toHide ? 0 : 1 })
      .then(() => {
        toast(toHide ? '「' + g.title + '」已在前端隐藏（仅登录管理员可见）' : '「' + g.title + '」已恢复前端显示', 'success');
        loadGroups();
      })
      .catch(e => toast(e.message, 'error'));
    return;
  }
  if (act === 'edit') {
    document.getElementById('groupModalTitle').textContent = '编辑分组';
    document.getElementById('g_id').value = g.id;
    document.getElementById('g_title').value = g.title;
    document.getElementById('g_desc').value = g.description || '';
    document.getElementById('groupModal').classList.add('show');
    return;
  }
  if (act === 'up' || act === 'down') {
    API.post(API_BASE + 'groups.php?action=sort', { id: g.id, direction: act === 'up' ? 'up' : 'down' })
      .then(loadGroups)
      .catch(e => toast(e.message, 'error'));
    return;
  }
  if (act === 'del') {
    if (!confirm('删除分组「' + g.title + '」将同时删除组内 ' + g.item_count + ' 张卡片，确定？')) return;
    API.post(API_BASE + 'groups.php?action=delete', { id: g.id })
      .then(() => { toast('已删除', 'success'); loadGroups().then(loadItems); })
      .catch(e => toast(e.message, 'error'));
  }
}

function bindGroups() {
  document.getElementById('addGroupBtn').onclick = () => {
    document.getElementById('groupModalTitle').textContent = '添加分组';
    document.getElementById('g_id').value = '';
    document.getElementById('g_title').value = '';
    document.getElementById('g_desc').value = '';
    document.getElementById('groupModal').classList.add('show');
  };
  document.getElementById('groupForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('groupSaveBtn');
    btn.disabled = true;
    try {
      await API.post(API_BASE + 'groups.php?action=edit', {
        id: Number(document.getElementById('g_id').value) || 0,
        title: document.getElementById('g_title').value.trim(),
        description: document.getElementById('g_desc').value.trim(),
      });
      document.getElementById('groupModal').classList.remove('show');
      toast('已保存', 'success');
      await loadGroups();
      await loadItems();
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      btn.disabled = false;
    }
  });
}

/* ================= 卡片 ================= */
async function loadItems() {
  const gid = state.currentGroup;
  try {
    state.items = await API.get(API_BASE + 'items.php?action=list' + (gid ? '&group_id=' + gid : ''));
  } catch (e) {
    toast('卡片加载失败：' + e.message, 'error');
    state.items = [];
  }
  renderItemsTable();
}

function groupName(id) {
  const g = state.groups.find(x => x.id === id);
  return g ? g.title : '#' + id;
}

const OPEN_TEXT = { 1: '当前页', 2: '新窗口', 3: '弹层' };

function renderItemsTable() {
  const tbody = document.getElementById('itemsTbody');
  tbody.innerHTML = '';
  if (!state.items.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-muted)">暂无卡片，点击右上角「添加卡片」创建</td></tr>';
    renderItemsPager(0);
    return;
  }
  // 仅「全部分组」分页浏览；选中具体分组时直接展示该分组全部卡片
  const total = state.items.length;
  const paged = state.currentGroup === 0;
  const totalPages = paged ? Math.max(1, Math.ceil(total / ITEM_PAGE_SIZE)) : 1;
  if (state.itemPage > totalPages) state.itemPage = totalPages;
  if (state.itemPage < 1) state.itemPage = 1;
  const start = paged ? (state.itemPage - 1) * ITEM_PAGE_SIZE : 0;
  const end = paged ? start + ITEM_PAGE_SIZE : total;
  const pageItems = state.items.slice(start, end);
  pageItems.forEach(item => {
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td><span class="icon-preview" style="width:34px;height:34px;font-size:14px;border-radius:8px" data-cell="icon"></span></td>' +
      '<td><div>' + esc(item.title) + '</div></td>' +
      '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-muted)">' + esc(item.url || item.lan_url) + '</td>' +
      '<td style="white-space:nowrap"><span class="item-group-tag">' + esc(groupName(item.group_id)) + '</span></td>' +
      '<td>' + (OPEN_TEXT[item.open_method] || '新窗口') + '</td>' +
      '<td><div class="actions">' +
      '<button class="btn btn-sm" data-act="up">上移</button>' +
      '<button class="btn btn-sm" data-act="down">下移</button>' +
      '<button class="btn btn-sm" data-act="edit">编辑</button>' +
      '<button class="btn btn-sm btn-danger" data-act="del">删除</button>' +
      '</div></td>';
    // 图标预览
    const iconCell = tr.querySelector('[data-cell="icon"]');
    const it = iconFor(item);
    if (it.startsWith('<img')) {
      iconCell.innerHTML = it;
    } else {
      iconCell.textContent = it;
      iconCell.style.background = item.icon_bg || '#0969da';
    }
    tr.querySelectorAll('[data-act]').forEach(btn => {
      btn.onclick = () => itemAction(btn.dataset.act, item);
    });
    tbody.appendChild(tr);
  });
  renderItemsPager(total);
}

/** 渲染卡片列表分页控件（上一页/页码/下一页 + 总数） */
function renderItemsPager(total) {
  const pager = document.getElementById('itemsPager');
  if (!pager) return;
  // 仅「全部分组」显示分页控件；具体分组不分页
  if (state.currentGroup !== 0) {
    pager.hidden = true;
    pager.innerHTML = '';
    return;
  }
  pager.hidden = false;
  const totalPages = Math.max(1, Math.ceil(total / ITEM_PAGE_SIZE));
  // 只有一页时只显示总数信息，不渲染翻页按钮
  if (totalPages <= 1) {
    pager.innerHTML = '<span class="pager-info">共 ' + total + ' 条</span>';
    return;
  }
  const cur = state.itemPage;
  const mkBtn = (label, page, opts = {}) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'pager-btn' + (opts.active ? ' active' : '') + (opts.ellipsis ? ' ellipsis' : '');
    b.textContent = label;
    if (opts.ellipsis || opts.disabled) {
      b.disabled = true;
    } else {
      b.onclick = () => {
        state.itemPage = page;
        renderItemsTable();
        // 翻页后回到表格顶部，避免停在旧位置
        const table = document.getElementById('itemsTbody');
        if (table && table.closest('.admin-panel')) {
          table.closest('.admin-panel').scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
      };
    }
    return b;
  };
  pager.innerHTML = '';
  const info = document.createElement('span');
  info.className = 'pager-info';
  const start = (cur - 1) * ITEM_PAGE_SIZE + 1;
  const end = Math.min(cur * ITEM_PAGE_SIZE, total);
  info.textContent = '第 ' + start + '–' + end + ' 条 / 共 ' + total + ' 条';
  pager.appendChild(info);
  const nav = document.createElement('span');
  nav.className = 'pager-nav';
  nav.appendChild(mkBtn('‹ 上一页', cur - 1, { disabled: cur <= 1 }));
  // 页码窗口：当前页前后各 2 页，超出用 …
  const pages = [];
  for (let p = 1; p <= totalPages; p++) {
    if (p === 1 || p === totalPages || Math.abs(p - cur) <= 2) pages.push(p);
    else if (pages[pages.length - 1] !== '…') pages.push('…');
  }
  pages.forEach(p => {
    if (p === '…') nav.appendChild(mkBtn('…', 0, { ellipsis: true }));
    else nav.appendChild(mkBtn(String(p), p, { active: p === cur }));
  });
  nav.appendChild(mkBtn('下一页 ›', cur + 1, { disabled: cur >= totalPages }));
  pager.appendChild(nav);
}

/** 后台表格里的图标 HTML/文字（仅预览用） */
function iconFor(item) {
  if (item.icon_type === 'image' && item.icon_value) {
    return '<img src="' + esc(assetUrl(item.icon_value)) + '" alt="">';
  }
  if (item.icon_type === 'favicon' && (item.url || item.lan_url)) {
    const fu = faviconUrl(item.url || item.lan_url);
    if (fu) return '<img src="' + esc(fu) + '" alt="" referrerpolicy="no-referrer">';
  }
  return (item.title || '?').charAt(0).toUpperCase();
}

function itemAction(act, item) {
  if (act === 'edit') {
    openItemModal(item);
    return;
  }
  if (act === 'up' || act === 'down') {
    API.post(API_BASE + 'items.php?action=sort', { id: item.id, direction: act === 'up' ? 'up' : 'down' })
      .then(loadItems)
      .catch(e => toast(e.message, 'error'));
    return;
  }
  if (act === 'del') {
    if (!confirm('确定删除卡片「' + item.title + '」？')) return;
    API.post(API_BASE + 'items.php?action=delete', { id: item.id })
      .then(() => { toast('已删除', 'success'); loadGroups().then(loadItems); })
      .catch(e => toast(e.message, 'error'));
  }
}

function openItemModal(item) {
  document.getElementById('itemModalTitle').textContent = item ? '编辑卡片' : '添加卡片';
  document.getElementById('i_id').value = item ? item.id : '';
  document.getElementById('i_group').value = String(item ? item.group_id : (state.currentGroup || (state.groups[0] && state.groups[0].id) || 0));
  document.getElementById('i_open').value = String(item ? item.open_method : 2);
  document.getElementById('i_title').value = item ? item.title : '';
  document.getElementById('i_url').value = item ? item.url : '';
  document.getElementById('i_lan').value = item ? item.lan_url : '';
  document.getElementById('i_desc').value = item ? item.description : '';
  const itype = item ? item.icon_type : 'favicon';
  document.querySelector('#i_itype input[value="' + itype + '"]').checked = true;
  document.getElementById('i_icon').value = item && item.icon_type === 'image' ? item.icon_value : '';
  document.getElementById('i_bg').value = item ? item.icon_bg : '';
  updateIconPreview();
  document.getElementById('itemModal').classList.add('show');
}

function updateIconPreview() {
  const type = document.querySelector('#i_itype input:checked').value;
  const icon = document.getElementById('i_icon').value.trim();
  const title = document.getElementById('i_title').value.trim();
  const url = document.getElementById('i_url').value.trim();
  const bg = document.getElementById('i_bg').value.trim();
  const preview = document.getElementById('i_preview');
  const faviconBtn = document.getElementById('i_useFavicon');
  document.getElementById('i_bgWrap').style.display = type === 'text' ? '' : 'none';
  faviconBtn.hidden = type !== 'image';

  preview.style.background = bg || '#0969da';
  if (type === 'image' && icon) {
    preview.innerHTML = '<img src="' + esc(assetUrl(icon)) + '" alt="">';
  } else if (type === 'favicon' && url) {
    const fu = faviconUrl(url);
    preview.innerHTML = fu ? '<img src="' + esc(fu) + '" alt="" referrerpolicy="no-referrer">' : '?';
  } else {
    preview.textContent = (title || '?').charAt(0).toUpperCase();
  }
}

/* ================= 拖拽排序（拖动 → 点「保存排序」才提交） ================= */
function setGroupsOrderDirty(d) {
  state.groupsOrderDirty = d;
  const save = document.getElementById('saveGroupsOrderBtn');
  const reset = document.getElementById('resetGroupsOrderBtn');
  if (save) save.hidden = !d;
  if (reset) reset.hidden = !d;
}

/**
 * 表格行拖拽（事件委托挂在 tbody 上，重绘不丢绑定）
 * 仅从 ⠿ 把手按下时才允许整行拖拽，避免干扰按钮点击与文本选择
 */
function bindTableDrag(tbodyId, opts) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  let dragIdx = -1;
  let dropAfter = false; // 鼠标在目标行下半部时插入到该行之后
  const clearDragState = () => {
    dragIdx = -1;
    dropAfter = false;
    tbody.querySelectorAll('tr.dragging, tr.drag-over-top, tr.drag-over-bottom')
      .forEach(x => x.classList.remove('dragging', 'drag-over-top', 'drag-over-bottom'));
    tbody.querySelectorAll('tr[draggable="true"]').forEach(x => { x.draggable = false; });
  };
  tbody.addEventListener('mousedown', e => {
    const tr = e.target.closest('tr[data-idx]');
    if (tr) tr.draggable = !!e.target.closest('.drag-handle') && opts.canDrag();
  });
  tbody.addEventListener('dragstart', e => {
    const tr = e.target.closest('tr[data-idx]');
    if (!tr || !opts.canDrag()) { e.preventDefault(); clearDragState(); return; }
    dragIdx = Number(tr.dataset.idx);
    requestAnimationFrame(() => tr.classList.add('dragging'));
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', tr.dataset.idx); } catch (_) { /* IE 兼容忽略 */ }
  });
  tbody.addEventListener('dragover', e => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const tr = e.target.closest('tr[data-idx]');
    tbody.querySelectorAll('tr.drag-over-top, tr.drag-over-bottom')
      .forEach(x => x.classList.remove('drag-over-top', 'drag-over-bottom'));
    if (!tr || Number(tr.dataset.idx) === dragIdx) return;
    const rect = tr.getBoundingClientRect();
    dropAfter = e.clientY > rect.top + rect.height / 2;
    tr.classList.add(dropAfter ? 'drag-over-bottom' : 'drag-over-top');
  });
  tbody.addEventListener('drop', e => {
    e.preventDefault();
    const tr = e.target.closest('tr[data-idx]');
    if (!tr || dragIdx < 0) { clearDragState(); return; }
    const to = Number(tr.dataset.idx);
    if (to !== dragIdx) {
      opts.reorder(dragIdx, to + (dropAfter ? 1 : 0));
    }
    clearDragState();
  });
  tbody.addEventListener('dragend', clearDragState);
  tbody.addEventListener('mouseleave', clearDragState);
}

function saveGroupsOrder() {
  return API.post(API_BASE + 'groups.php?action=sort_batch', {
    ids: state.groups.map(g => g.id),
  }).then(() => {
    toast('分组排序已保存', 'success');
    setGroupsOrderDirty(false);
    return loadGroups();
  });
}

function bindDragSort() {
  // 卡片排序已移至前端主页（管理员登录后进入排序模式拖拽保存）；后台仅保留上移/下移按钮
  // 分组：全表顺序，可直接拖
  bindTableDrag('groupsTbody', {
    canDrag: () => true,
    reorder: (from, insertAt) => {
      const moved = state.groups.splice(from, 1)[0];
      state.groups.splice(insertAt > from ? insertAt - 1 : insertAt, 0, moved);
      renderGroupsTable();
      setGroupsOrderDirty(true);
    },
  });
  document.getElementById('saveGroupsOrderBtn').onclick = () => {
    saveGroupsOrder().catch(e => toast(e.message, 'error'));
  };
  document.getElementById('resetGroupsOrderBtn').onclick = () => { setGroupsOrderDirty(false); loadGroups(); };
}

function bindItems() {
  document.getElementById('addItemBtn').onclick = () => openItemModal(null);
  ['i_title', 'i_url', 'i_icon', 'i_bg'].forEach(id => {
    document.getElementById(id).addEventListener('input', updateIconPreview);
  });
  document.querySelectorAll('#i_itype input').forEach(r => {
    r.addEventListener('change', updateIconPreview);
  });

  // 抓取网页标题/描述/图标（auto=true 时仅标题为空才自动触发；进行中时忽略重复触发）
  let metaBusy = false;
  const fetchMeta = async (auto) => {
    if (metaBusy) return;
    const url = document.getElementById('i_url').value.trim();
    if (!url || !/^https?:\/\//i.test(url)) {
      if (!auto) toast('请先填写以 http:// 或 https:// 开头的地址', 'error');
      return;
    }
    const btn = document.getElementById('i_fetchMeta');
    metaBusy = true;
    btn.disabled = true;
    btn.textContent = '获取中…';
    try {
      const res = await API.get(API_BASE + 'items.php?action=fetch_meta&url=' + encodeURIComponent(url));
      const got = [];
      const changed = {};
      // 记录请求前空字段（避免失焦+点击双重触发时，失焦填充被误认为按钮没取到）
      const empty = {
        title: !document.getElementById('i_title').value.trim(),
        desc:  !document.getElementById('i_desc').value.trim(),
        icon:  !document.getElementById('i_icon').value.trim(),
      };
      if (res.title && empty.title) {
        document.getElementById('i_title').value = res.title;
        got.push('标题');
        changed.title = true;
      }
      if (res.description && empty.desc) {
        document.getElementById('i_desc').value = res.description;
        got.push('描述');
        changed.desc = true;
      }
      if (res.icon && empty.icon) {
        document.querySelector('#i_itype input[value="image"]').checked = true;
        document.getElementById('i_icon').value = res.icon;
        got.push(res.fallback_icon ? '图标(浏览器加载)' : '图标');
        changed.icon = true;
      }
      updateIconPreview();
      const warnTxt = res.warn ? '（' + res.warn + '）' : '';
      if (got.length) {
        toast('已自动获取：' + got.join('、') + warnTxt, res.warn ? 'info' : 'success');
      } else if (!empty.title || !empty.desc || !empty.icon) {
        toast('已完成（未填充的字段是原本已有内容）' + warnTxt, 'info');
      } else {
        toast('未获取到可填充的字段' + (warnTxt || '（站点无信息）'), 'info');
      }
    } catch (e) {
      if (!auto) toast(e.message, 'error');
    } finally {
      metaBusy = false;
      btn.disabled = false;
      btn.textContent = '自动获取信息';
    }
  };
  document.getElementById('i_fetchMeta').onclick = () => fetchMeta(false);
  // 地址输入框失焦后，若标题为空则自动获取
  document.getElementById('i_url').addEventListener('change', () => {
    if (!document.getElementById('i_title').value.trim()) fetchMeta(true);
  });

  document.getElementById('i_useFavicon').onclick = async () => {
    const url = document.getElementById('i_url').value.trim();
    if (!url) {
      toast('请先填写卡片地址', 'error');
      return;
    }
    const btn = document.getElementById('i_useFavicon');
    btn.disabled = true;
    btn.textContent = '获取中…';
    try {
      // 服务端多源抓取（页面 icon → /favicon.ico → 公共图标服务）并缓存到本地
      const res = await API.get(API_BASE + 'items.php?action=favicon&url=' + encodeURIComponent(url));
      document.querySelector('#i_itype input[value="image"]').checked = true;
      document.getElementById('i_icon').value = res.url;
      updateIconPreview();
      if (res.fallback) {
        toast('服务器未能缓存图标' + (res.diag ? '：' + res.diag : '') + '，已改用公共图标源（访客浏览器直接加载）', 'info');
      } else {
        toast(res.cached ? '已获取（本地缓存）' : '获取成功', 'success');
      }
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      btn.disabled = false;
      btn.textContent = '获取站点图标';
    }
  };

  document.getElementById('itemForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('itemSaveBtn');
    btn.disabled = true;
    try {
      await API.post(API_BASE + 'items.php?action=edit', {
        id: Number(document.getElementById('i_id').value) || 0,
        group_id: Number(document.getElementById('i_group').value) || 0,
        open_method: Number(document.getElementById('i_open').value),
        title: document.getElementById('i_title').value.trim(),
        url: document.getElementById('i_url').value.trim(),
        lan_url: document.getElementById('i_lan').value.trim(),
        description: document.getElementById('i_desc').value.trim(),
        icon_type: document.querySelector('#i_itype input:checked').value,
        icon_value: document.getElementById('i_icon').value.trim(),
        icon_bg: document.getElementById('i_bg').value.trim(),
      });
      document.getElementById('itemModal').classList.remove('show');
      toast('已保存', 'success');
      await loadGroups();
      await loadItems();
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      btn.disabled = false;
    }
  });
}

/* ================= 上传 ================= */
function bindUploads() {
  document.querySelectorAll('[data-upload]').forEach(btn => {
    const targetId = btn.dataset.upload;
    const fileType = targetId === 's_wallpaper' ? 'wallpaper' : (targetId === 's_site_logo' ? 'logo' : 'icon');
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.style.display = 'none';
    btn.appendChild(input);
    btn.addEventListener('click', () => input.click());
    input.addEventListener('change', async () => {
      const file = input.files[0];
      if (!file) return;
      const form = new FormData();
      form.append('file', file);
      form.append('type', fileType);
      btn.disabled = true;
      try {
        const res = await API.postForm(API_BASE + 'upload.php', form);
        document.getElementById(targetId).value = res.url;
        if (targetId.startsWith('s_')) updateSettingPreview(targetId);
        if (targetId === 's_wallpaper') loadWallpaperGallery();
        updateIconPreview();
        toast('上传成功', 'success');
      } catch (e) {
        toast(e.message, 'error');
      } finally {
        btn.disabled = false;
        input.value = '';
      }
    });
  });
}

/* ================= 站点设置 ================= */
function updateSettingPreview(id) {
  const img = document.getElementById('p_' + id.replace('s_', ''));
  if (!img) return;
  const val = document.getElementById(id).value.trim();
  if (val) {
    img.src = assetUrl(val);
    img.classList.add('show');
    img.onerror = () => img.classList.remove('show');
  } else {
    img.classList.remove('show');
  }
}

function fillSettingsForm(s) {
  document.getElementById('s_site_title').value = s.site_title || '';
  document.getElementById('s_site_logo').value = s.site_logo || '';
  document.getElementById('s_wallpaper').value = s.wallpaper || '';
  // 遮罩透明度滑条回填（0~1，step 0.05）
  const mv = parseFloat(s.mask_opacity);
  const maskVal = isNaN(mv) ? 0.35 : Math.min(Math.max(mv, 0), 1);
  document.getElementById('s_mask_opacity').value = maskVal;
  document.getElementById('v_mask_opacity').textContent = String(Number(maskVal.toFixed(2)));
  // 壁纸模糊滑条回填（0~30px）
  const bv = parseInt(s.wallpaper_blur, 10);
  const blurVal = isNaN(bv) ? 6 : Math.min(Math.max(bv, 0), 30);
  document.getElementById('s_wallpaper_blur').value = blurVal;
  document.getElementById('v_wallpaper_blur').textContent = String(blurVal);
  document.getElementById('s_announcement').value = s.announcement || '';
  document.getElementById('s_announcement_show').value = s.announcement_show === '1' ? '1' : '0';
  document.getElementById('s_default_lan_mode').value = s.default_lan_mode === 'lan' ? 'lan' : 'public';
  document.getElementById('s_site_url').value = s.site_url || '';
  document.getElementById('s_footer').value = s.footer || '';
  document.getElementById('s_clock_show').value = s.clock_show === '0' ? '0' : '1';
  document.getElementById('s_weather_show').value = s.weather_show === '0' ? '0' : '1';
  document.getElementById('s_weather_city').value = s.weather_city || '';
  document.getElementById('s_home_view').value = ['nav', 'news', 'both'].includes(s.home_view) ? s.home_view : 'both';
  loadNewsSourceRows(s.news_sources || '', s.news_order || '');
  document.getElementById('s_default_theme').value = ['light', 'dark', 'system'].includes(s.default_theme) ? s.default_theme : 'dark';
  document.getElementById('s_theme_style').value = SP_STYLES.includes(s.theme_style) ? s.theme_style : 'soft';
  document.getElementById('s_default_theme_note').textContent =
    s.default_theme === 'system' ? '跟随系统：访客未手动切换主题时，随其系统深浅自动变化' : '';
  document.getElementById('s_card_style').value = s.card_style === 'app' ? 'app' : 'detail';

  // 内容区域滑条
  document.getElementById('s_content_maxwidth').value = s.content_maxwidth || '1200';
  document.getElementById('s_content_pad_lr').value = s.content_pad_lr != null && s.content_pad_lr !== '' ? s.content_pad_lr : '20';
  document.getElementById('s_content_pad_top').value = s.content_pad_top != null && s.content_pad_top !== '' ? s.content_pad_top : '0';
  document.getElementById('s_content_pad_bottom').value = s.content_pad_bottom != null && s.content_pad_bottom !== '' ? s.content_pad_bottom : '40';
  ['maxwidth', 'pad_lr', 'pad_top', 'pad_bottom'].forEach(k => {
    const out = document.getElementById('v_content_' + k);
    if (out) out.textContent = document.getElementById('s_content_' + k).value;
  });

  // 搜索栏宽度滑条
  const swVal = parseInt(s.search_width, 10);
  document.getElementById('s_search_width').value = isNaN(swVal) ? 640 : Math.min(1200, Math.max(360, swVal));
  document.getElementById('v_search_width').textContent = document.getElementById('s_search_width').value;

  let engines = [];
  try { engines = JSON.parse(s.search_engines || '[]'); } catch (e) { /* 忽略 */ }
  renderEngineRows(engines);
  if (s.search_default) document.getElementById('s_search_default').value = s.search_default;

  updateSettingPreview('s_site_logo');
  updateSettingPreview('s_wallpaper');
  syncGalleryActive(s.wallpaper || '');
}

/* ---------- 热点新闻数据源勾选与排序（表格排版，参考分组管理） ---------- */
async function loadNewsSourceRows(saved, savedOrder) {
  const wrap = document.getElementById('newsSourceRows');
  if (!wrap) return;
  let checked = [];
  try { checked = JSON.parse(saved || '[]'); } catch (e) { checked = []; }
  if (!Array.isArray(checked)) checked = [];
  let order = [];
  try { order = JSON.parse(savedOrder || '[]'); } catch (e) { order = []; }
  if (!Array.isArray(order)) order = [];
  try {
    // meta 返回全部内置源（不受启用列表过滤，保证被关掉的源也能重新勾选），且已按保存的显示顺序排序
    const meta = await API.get(API_BASE + 'news.php?action=meta');
    let sources = (meta && meta.sources) || [];
    // 双保险：本地再按保存顺序排一次（未包含的源按 meta 顺序追加）
    if (order.length) {
      const pos = new Map(order.map((id, i) => [id, i]));
      sources = sources
        .map(s => [pos.has(s.id) ? pos.get(s.id) : 9999, s])
        .sort((a, b) => a[0] - b[0])
        .map(x => x[1]);
    }
    wrap.innerHTML = '';
    if (!sources.length) {
      wrap.innerHTML = '<div class="form-hint">暂无可选数据源</div>';
      return;
    }
    const table = document.createElement('table');
    table.className = 'table news-source-table';
    table.innerHTML =
      '<thead><tr>' +
      '<th style="width:220px">数据源</th>' +
      '<th>数据源API地址</th>' +
      '<th style="width:70px;text-align:center">排序号</th>' +
      '<th style="width:110px;text-align:center">前端显示</th>' +
      '<th style="width:110px">操作</th>' +
      '</tr></thead><tbody></tbody>';
    const tbody = table.querySelector('tbody');
    sources.forEach((src, i) => {
      const tr = document.createElement('tr');
      tr.className = 'news-source-item';
      tr.dataset.id = src.id;
      // 第 1 列：数据源（拖拽手柄 + 色点 + 名称）
      const tdName = document.createElement('td');
      tdName.style.whiteSpace = 'nowrap';
      const handle = document.createElement('span');
      handle.className = 'ns-handle';
      handle.title = '拖动调整主页显示顺序';
      handle.textContent = '⠿';
      handle.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); });
      const dot = document.createElement('span');
      dot.className = 'ns-dot';
      dot.style.background = src.color || 'var(--accent)';
      const name = document.createElement('span');
      name.textContent = src.name;
      tdName.appendChild(handle);
      tdName.appendChild(dot);
      tdName.appendChild(name);
      // 第 2 列：数据源 API 地址（home）
      const tdUrl = document.createElement('td');
      const home = src.home || '-';
      tdUrl.innerHTML = '<span class="ns-url" title="' + esc(home) + '">' + esc(home) + '</span>';
      // 第 3 列：排序号（当前行序号，1 起始）
      const tdOrder = document.createElement('td');
      tdOrder.style.textAlign = 'center';
      tdOrder.textContent = i + 1;
      // 第 4 列：前端显示（启用开关）
      const tdVis = document.createElement('td');
      tdVis.style.textAlign = 'center';
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = src.id;
      // 空配置 = 全部启用；非空按保存值勾选
      cb.checked = checked.length === 0 || checked.includes(src.id);
      cb.title = '勾选后在主页新闻视图显示';
      tdVis.appendChild(cb);
      // 第 5 列：操作（上移/下移）
      const tdAct = document.createElement('td');
      const acts = document.createElement('div');
      acts.className = 'actions';
      const upBtn = document.createElement('button');
      upBtn.className = 'btn btn-sm';
      upBtn.textContent = '上移';
      upBtn.onclick = () => moveNewsRow(tr, -1);
      const downBtn = document.createElement('button');
      downBtn.className = 'btn btn-sm';
      downBtn.textContent = '下移';
      downBtn.onclick = () => moveNewsRow(tr, 1);
      acts.appendChild(upBtn);
      acts.appendChild(downBtn);
      tdAct.appendChild(acts);

      tr.appendChild(tdName);
      tr.appendChild(tdUrl);
      tr.appendChild(tdOrder);
      tr.appendChild(tdVis);
      tr.appendChild(tdAct);
      tbody.appendChild(tr);
    });
    wrap.appendChild(table);
    refreshNewsOrderNumbers();
  } catch (e) {
    wrap.innerHTML = '<div class="form-hint">数据源列表加载失败：' + e.message + '</div>';
  }
}

/** 上移/下移某行并刷新排序号显示 */
function moveNewsRow(tr, dir) {
  const tbody = tr.parentElement;
  if (!tbody) return;
  const rows = Array.prototype.slice.call(tbody.querySelectorAll('.news-source-item'));
  const idx = rows.indexOf(tr);
  if (idx < 0) return;
  const target = idx + dir;
  if (target < 0 || target >= rows.length) return;
  const ref = rows[target];
  if (dir < 0) tbody.insertBefore(tr, ref);
  else tbody.insertBefore(tr, ref.nextSibling);
  refreshNewsOrderNumbers();
}

/** 重排后刷新「排序号」列的数字显示 */
function refreshNewsOrderNumbers() {
  const rows = document.querySelectorAll('#newsSourceRows .news-source-item');
  rows.forEach((r, i) => {
    const td = r.children[2];
    if (td) td.textContent = i + 1;
  });
}

/** 数据源行拖拽排序（仅从 ⠿ 手柄起拖；网格布局按 DOM 顺序落位） */
function bindNewsSourceDrag() {
  const wrap = document.getElementById('newsSourceRows');
  if (!wrap) return;
  let dragEl = null;
  const clearMarks = () => {
    wrap.querySelectorAll('.drag-over-before, .drag-over-after, .dragging')
      .forEach(el => el.classList.remove('drag-over-before', 'drag-over-after', 'dragging'));
    wrap.querySelectorAll('.news-source-item').forEach(el => { el.draggable = false; });
  };
  wrap.addEventListener('mousedown', e => {
    const item = e.target.closest('.news-source-item');
    if (item) item.draggable = !!e.target.closest('.ns-handle');
  });
  wrap.addEventListener('dragstart', e => {
    // 注意：dragstart 的 e.target 是被拖元素本身（label），不是手柄；
    // 能否拖拽已由 mousedown 中「仅按下 ⠿ 手柄才设 draggable=true」把关，这里不能再判定 closest('.ns-handle')
    const item = e.target.closest('.news-source-item');
    if (!item || item.draggable === false) { e.preventDefault(); return; }
    dragEl = item;
    requestAnimationFrame(() => item.classList.add('dragging'));
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', item.dataset.id || ''); } catch (_) { /* 兼容忽略 */ }
  });
  wrap.addEventListener('dragover', e => {
    if (!dragEl) return;
    const item = e.target.closest('.news-source-item');
    wrap.querySelectorAll('.drag-over-before, .drag-over-after')
      .forEach(el => el.classList.remove('drag-over-before', 'drag-over-after'));
    if (!item || item === dragEl) { e.preventDefault(); return; }
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const rect = item.getBoundingClientRect();
    const after = (e.clientX - rect.left) > rect.width / 2 || (e.clientY - rect.top) > rect.height / 2;
    item.classList.add(after ? 'drag-over-after' : 'drag-over-before');
  });
  wrap.addEventListener('drop', e => {
    e.preventDefault();
    const item = e.target.closest('.news-source-item');
    if (!dragEl || !item || item === dragEl) { clearMarks(); dragEl = null; return; }
    const rect = item.getBoundingClientRect();
    const after = (e.clientX - rect.left) > rect.width / 2 || (e.clientY - rect.top) > rect.height / 2;
    if (after) item.after(dragEl);
    else item.before(dragEl);
    clearMarks();
    dragEl = null;
  });
  wrap.addEventListener('dragend', () => { clearMarks(); dragEl = null; });
}

/* ---------- 壁纸图库 ---------- */
async function loadWallpaperGallery() {
  const wrap = document.getElementById('wpGallery');
  const empty = document.getElementById('wpGalleryEmpty');
  if (!wrap || !empty) return;
  try {
    const list = await API.get(API_BASE + 'gallery.php?action=list');
    wrap.querySelectorAll('.wp-item').forEach(el => el.remove());
    empty.hidden = list.length > 0;
    empty.textContent = '图库为空，上传壁纸后自动加入';
    list.forEach(w => wrap.appendChild(buildWpItem(w)));
    syncGalleryActive(document.getElementById('s_wallpaper').value.trim());
  } catch (e) {
    empty.hidden = false;
    empty.textContent = '图库加载失败：' + e.message;
  }
}

function buildWpItem(w) {
  const div = document.createElement('div');
  div.className = 'wp-item';
  div.dataset.url = w.url;
  div.title = w.preset ? w.name + '（预置壁纸，点击选用）' : w.name + '（点击选用）';
  const img = document.createElement('img');
  img.src = assetUrl(w.url);
  img.alt = '';
  img.loading = 'lazy';
  div.appendChild(img);
  if (w.preset) {
    // 预置壁纸：带角标，不提供删除
    const tag = document.createElement('span');
    tag.className = 'wp-tag';
    tag.textContent = '预置';
    div.appendChild(tag);
  } else {
    const del = document.createElement('button');
    del.className = 'wp-del';
    del.type = 'button';
    del.textContent = '×';
    del.title = '从图库删除';
    del.onclick = async e => {
      e.stopPropagation();
      if (!confirm('从图库删除壁纸「' + w.name + '」？文件将被移除，已引用它的设置需另行调整。')) return;
      try {
        await API.post(API_BASE + 'gallery.php?action=delete', { url: w.url });
        if (document.getElementById('s_wallpaper').value.trim() === w.url) {
          document.getElementById('s_wallpaper').value = '';
          updateSettingPreview('s_wallpaper');
          syncGalleryActive('');
        }
        toast('壁纸已删除', 'success');
        loadWallpaperGallery();
      } catch (err) {
        toast(err.message, 'error');
      }
    };
    div.appendChild(del);
  }
  const name = document.createElement('span');
  name.className = 'wp-name';
  name.textContent = w.name;
  div.appendChild(name);
  div.onclick = () => {
    document.getElementById('s_wallpaper').value = w.url;
    updateSettingPreview('s_wallpaper');
    syncGalleryActive(w.url);
    // 选用后关闭图库弹窗
    document.getElementById('wpGalleryModal').classList.remove('show');
    toast('壁纸已选用，点击分区「保存」生效', 'success');
  };
  return div;
}

/** 图库选中态与壁纸地址联动 */
function syncGalleryActive(url) {
  document.querySelectorAll('#wpGallery .wp-item').forEach(el => {
    el.classList.toggle('active', el.dataset.url === url);
  });
}

/* ================= 版本号 & 更新日志 ================= */
/** 更新日志日期统一显示为「年-月-日 时:分:秒」；仅填了日期（YYYY-MM-DD）的旧记录补 00:00:00 */
function formatLogDate(d) {
  const s = String(d || '').trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s + ' 00:00:00';
  return s;
}

function renderVersionInfo() {
  const verEl = document.getElementById('appVer');
  if (verEl) verEl.textContent = APP_VERSION;
  const verEl2 = document.getElementById('appVer2');
  if (verEl2) verEl2.textContent = APP_VERSION;
  const detail = document.getElementById('changelogDetail');
  const historyList = document.getElementById('changelogHistoryList');
  const historyBtn = document.getElementById('changelogHistoryBtn');
  const historyModal = document.getElementById('changelogHistoryModal');

  // 渲染单个版本条目
  const buildLogItem = (rel) => {
    const item = document.createElement('div');
    item.className = 'log-item';
    const head = document.createElement('div');
    head.className = 'log-head';
    const ver = document.createElement('span');
    ver.className = 'log-ver';
    ver.textContent = rel.ver + (rel.ver === APP_VERSION ? '（当前版本）' : '');
    const date = document.createElement('span');
    date.className = 'log-date';
    date.textContent = formatLogDate(rel.date);
    head.appendChild(ver);
    head.appendChild(date);
    item.appendChild(head);
    const ul = document.createElement('ul');
    if (!rel.items.length) {
      const li = document.createElement('li');
      li.textContent = '暂无记录，功能更新后将在此显示';
      li.style.color = 'var(--text-muted)';
      ul.appendChild(li);
    }
    rel.items.forEach(t => {
      const li = document.createElement('li');
      li.textContent = t;
      ul.appendChild(li);
    });
    item.appendChild(ul);
    return item;
  };

  // 主页区域：仅显示最新版本
  if (detail) {
    detail.innerHTML = '';
    const latest = APP_CHANGELOG[0];
    if (latest) detail.appendChild(buildLogItem(latest));
  }
  // 历史弹窗：渲染全部版本
  if (historyList) {
    historyList.innerHTML = '';
    APP_CHANGELOG.forEach(rel => historyList.appendChild(buildLogItem(rel)));
  }
  // 按钮打开弹窗
  if (historyBtn && historyModal) {
    historyBtn.addEventListener('click', () => historyModal.classList.add('show'));
  }
}

function renderEngineRows(engines) {
  const wrap = document.getElementById('engineRows');
  wrap.innerHTML = '';
  (engines || []).forEach(eng => addEngineRow(eng.name, eng.url));
  refreshEngineDefaults();
}

function addEngineRow(name, url) {
  const row = document.createElement('div');
  row.className = 'engine-row';
  row.innerHTML =
    '<input class="input engine-name" placeholder="名称" maxlength="20">' +
    '<input class="input engine-url" placeholder="https://…/%s" maxlength="500">' +
    '<button class="btn btn-sm btn-danger" type="button" data-del>删除</button>';
  row.querySelector('.engine-name').value = name || '';
  row.querySelector('.engine-url').value = url || '';
  row.querySelector('[data-del]').onclick = () => {
    row.remove();
    refreshEngineDefaults();
  };
  row.querySelectorAll('input').forEach(inp => inp.addEventListener('change', refreshEngineDefaults));
  document.getElementById('engineRows').appendChild(row);
}

function refreshEngineDefaults() {
  const sel = document.getElementById('s_search_default');
  const current = sel.value;
  sel.innerHTML = '';
  document.querySelectorAll('#engineRows .engine-row').forEach(row => {
    const name = row.querySelector('.engine-name').value.trim();
    if (!name) return;
    const opt = document.createElement('option');
    opt.value = name;
    opt.textContent = name;
    sel.appendChild(opt);
  });
  if ([...sel.options].some(o => o.value === current)) sel.value = current;
}

/* 分区定义：每个分区独立保存，只提交自己的设置项（后端白名单兼容部分提交）
   原「Logo 与壁纸」分区已并入「外观布局」；原「时钟与天气」分区已并入「基础信息」 */
const SETTING_SECTIONS = {
  basic:    { label: '基础信息', keys: ['site_title', 'site_url', 'footer', 'default_lan_mode', 'home_view', 'announcement_show', 'announcement', 'clock_show', 'weather_show', 'weather_city', 'icp_show', 'icp_number', 'icp_link', 'police_show', 'police_number', 'police_link'] },
  theme:    { label: '外观布局', keys: ['default_theme', 'theme_style', 'content_maxwidth', 'content_pad_lr', 'content_pad_top', 'content_pad_bottom', 'card_style', 'search_width', 'site_logo', 'wallpaper', 'mask_opacity', 'wallpaper_blur'] },
  search:   { label: '搜索引擎', keys: ['search_engines', 'search_default'] },
  news:     { label: '热点新闻', keys: ['news_sources', 'news_order'] },
};

function numSetting(id, def) {
  const n = parseInt(document.getElementById(id).value, 10);
  return isNaN(n) ? def : String(n);
}

/** 按设置项键名收集当前表单值（特殊键单独处理，其余按 s_键名 取输入值） */
function collectSetting(key) {
  if (key === 'search_engines') {
    const engines = [];
    document.querySelectorAll('#engineRows .engine-row').forEach(row => {
      const name = row.querySelector('.engine-name').value.trim();
      const url = row.querySelector('.engine-url').value.trim();
      if (name && url) engines.push({ name: name, url: url });
    });
    return JSON.stringify(engines);
  }
  if (key === 'mask_opacity') {
    const v = document.getElementById('s_mask_opacity').value.trim();
    return v === '' ? '0.35' : v;
  }
  if (key === 'content_maxwidth') return numSetting('s_content_maxwidth', '1200');
  if (key === 'content_pad_lr') return numSetting('s_content_pad_lr', '20');
  if (key === 'content_pad_top') return numSetting('s_content_pad_top', '0');
  if (key === 'content_pad_bottom') return numSetting('s_content_pad_bottom', '40');
  if (key === 'search_width') return numSetting('s_search_width', '640');
  if (key === 'wallpaper_blur') return numSetting('s_wallpaper_blur', '6');
  if (key === 'news_sources') {
    // 勾选的数据源 id：全选时存空串 = 全部启用；列表未加载成功则不提交，避免误存空数组关掉全部源
    const boxes = document.querySelectorAll('#newsSourceRows input[type="checkbox"]');
    if (!boxes.length) return undefined;
    const ids = [];
    boxes.forEach(cb => { if (cb.checked && cb.value) ids.push(cb.value); });
    return ids.length === boxes.length ? '' : JSON.stringify(ids);
  }
  if (key === 'news_order') {
    // 全部数据源（含未勾选）的显示顺序，按当前行排列收集；列表未加载成功则不提交
    const rows = document.querySelectorAll('#newsSourceRows .news-source-item');
    if (!rows.length) return undefined;
    const ids = [];
    rows.forEach(r => { if (r.dataset.id) ids.push(r.dataset.id); });
    return JSON.stringify(ids);
  }
  return document.getElementById('s_' + key).value.trim();
}

function bindSettings() {
  // 内容区域滑条：拖动时同步显示当前数值
  ['maxwidth', 'pad_lr', 'pad_top', 'pad_bottom'].forEach(k => {
    const range = document.getElementById('s_content_' + k);
    const out = document.getElementById('v_content_' + k);
    if (range && out) {
      range.addEventListener('input', () => { out.textContent = range.value; });
    }
  });

  // 搜索栏宽度滑条联动
  const swRange = document.getElementById('s_search_width');
  const swOut = document.getElementById('v_search_width');
  if (swRange && swOut) swRange.addEventListener('input', () => { swOut.textContent = swRange.value; });

  // 遮罩透明度 / 壁纸模糊滑条联动
  [['s_mask_opacity', 'v_mask_opacity'], ['s_wallpaper_blur', 'v_wallpaper_blur']].forEach(([rid, oid]) => {
    const r = document.getElementById(rid);
    const o = document.getElementById(oid);
    if (r && o) r.addEventListener('input', () => { o.textContent = r.value; });
  });

  // 壁纸图库弹窗：打开时加载最新列表
  const openGalleryBtn = document.getElementById('openGalleryBtn');
  if (openGalleryBtn) openGalleryBtn.onclick = () => {
    document.getElementById('wpGalleryModal').classList.add('show');
    loadWallpaperGallery();
  };

  // 壁纸清空：地址置空并取消图库选中态
  const clearWpBtn = document.getElementById('clearWallpaperBtn');
  if (clearWpBtn) clearWpBtn.onclick = () => {
    document.getElementById('s_wallpaper').value = '';
    updateSettingPreview('s_wallpaper');
    syncGalleryActive('');
  };

  document.getElementById('addEngineBtn').onclick = () => addEngineRow('', '');
  ['s_site_logo', 's_wallpaper'].forEach(id => {
    document.getElementById(id).addEventListener('input', () => updateSettingPreview(id));
  });

  // 主题实时预览：切换选择立即在后台生效
  ['s_default_theme', 's_theme_style'].forEach(id => {
    document.getElementById(id).addEventListener('change', () => {
      applyTheme({
        default_theme: document.getElementById('s_default_theme').value,
        theme_style: document.getElementById('s_theme_style').value,
      });
      document.getElementById('s_default_theme_note').textContent =
        document.getElementById('s_default_theme').value === 'system'
          ? '跟随系统：访客未手动切换主题时，随其系统深浅自动变化'
          : '';
    });
  });

  // 分区独立保存：每个分区表单只提交自己的设置项
  document.querySelectorAll('#tab-settings form[data-section]').forEach(form => {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const key = form.dataset.section;
      const sec = SETTING_SECTIONS[key];
      if (!sec) return;
      const btn = e.submitter || document.querySelector('.section-save[form="' + form.id + '"]') ||
        form.querySelector('[type="submit"]');
      if (btn) { btn.disabled = true; btn.textContent = '保存中…'; }
      try {
        const payload = {};
        sec.keys.forEach(k => { payload[k] = collectSetting(k); });
        await API.post(API_BASE + 'settings.php?action=save', payload);
        if (key === 'basic') state.siteUrl = (payload.site_url || '').replace(/\/+$/, '');
        if (key === 'theme') {
          applyTheme({
            default_theme: document.getElementById('s_default_theme').value,
            theme_style: document.getElementById('s_theme_style').value,
          });
        }
        toast(sec.label + '已保存' + (key === 'theme' ? '，部分设置需刷新主页生效' : ''), 'success');
      } catch (err) {
        toast(err.message, 'error');
      } finally {
        if (btn) { btn.disabled = false; btn.textContent = '保存'; }
      }
    });
  });
}

/* ================= 站点设置：二级导航分区切换 ================= */
function bindSettingsSubnav() {
  const nav = document.getElementById('settingsSubnav');
  if (!nav) return;
  // 分区卡片与导航按钮按文档顺序一一对应（basic/theme/media/clock/search/news）
  const sections = Array.from(document.querySelectorAll('#tab-settings .settings-section'));
  const tabs = Array.from(nav.querySelectorAll('.subtab'));

  function show(panel) {
    tabs.forEach((t, i) => {
      const on = t.dataset.panel === panel;
      t.classList.toggle('active', on);
      if (sections[i]) sections[i].hidden = !on;
    });
    try { localStorage.setItem('sp_settings_panel', panel); } catch (e) { /* 隐私模式忽略 */ }
  }

  tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.panel)));

  let saved = null;
  try { saved = localStorage.getItem('sp_settings_panel'); } catch (e) { /* 忽略 */ }
  if (!saved || !tabs.some(t => t.dataset.panel === saved)) saved = 'basic';
  show(saved);
}

/* ================= 备份与恢复 ================= */
function bindBackup() {
  const exportBtn = document.getElementById('exportBackupBtn');
  const importBtn = document.getElementById('importBackupBtn');
  const resetBtn = document.getElementById('resetBackupBtn');
  const fileInput = document.getElementById('importBackupFile');
  if (!exportBtn || !importBtn || !fileInput) return;

  // 导出：直接下载（GET 带登录态 Cookie）
  exportBtn.onclick = () => {
    exportBtn.disabled = true;
    setTimeout(() => { exportBtn.disabled = false; }, 2000);
    location.href = API_BASE + 'backup.php?action=export&t=' + Date.now();
  };

  // 导入：选文件 → 强确认 → 上传 → 成功后整页刷新
  importBtn.onclick = () => fileInput.click();

  fileInput.addEventListener('change', async () => {
    const file = fileInput.files && fileInput.files[0];
    fileInput.value = '';
    if (!file) return;
    if (!/\.json$/i.test(file.name)) {
      toast('请选择导出生成的 .json 备份文件', 'error');
      return;
    }
    if (!confirm('导入将【清空】当前全部分组、卡片、站点设置和图标 / 壁纸文件，然后用「' + file.name + '」恢复。\n用户账号不受影响。\n\n确定继续吗？')) return;
    if (!confirm('再次确认：此操作不可撤销，建议先点「导出配置」备份当前数据。现在开始导入吗？')) return;

    importBtn.disabled = true;
    const oldText = importBtn.textContent;
    importBtn.textContent = '导入中…';
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await API.postForm(API_BASE + 'backup.php?action=import', fd);
      let msg = '导入完成：' + res.groups + ' 个分组、' + res.items + ' 张卡片、' + res.settings + ' 项设置、' + res.files + ' 个文件，页面即将刷新';
      if (res.orphan_items) msg += '（跳过 ' + res.orphan_items + ' 张引用失效分组的卡片）';
      toast(msg, 'success');
      setTimeout(() => location.reload(), 1200);
    } catch (err) {
      toast('导入失败：' + err.message, 'error');
    } finally {
      importBtn.disabled = false;
      importBtn.textContent = oldText;
    }
  });

  // 清空恢复初始状态
  if (resetBtn) {
    resetBtn.onclick = async () => {
      if (!confirm('此操作将【删除】全部分组、卡片、自定义站点设置和图标 / 壁纸文件，恢复到刚安装时的默认状态。\n用户账号不受影响。\n\n确定继续吗？')) return;
      if (!confirm('再次确认：此操作不可撤销，建议先点「导出配置」备份当前数据。现在开始恢复初始状态吗？')) return;
      resetBtn.disabled = true;
      const oldText = resetBtn.textContent;
      resetBtn.textContent = '恢复中…';
      try {
        const res = await API.post(API_BASE + 'backup.php?action=reset', {});
        toast('已恢复初始状态：' + res.settings + ' 项默认设置、' + res.groups + ' 个示例分组、' + res.items + ' 张示例卡片，清除 ' + res.files_cleared + ' 个文件，页面即将刷新', 'success');
        setTimeout(() => location.reload(), 1200);
      } catch (err) {
        toast('恢复失败：' + err.message, 'error');
      } finally {
        resetBtn.disabled = false;
        resetBtn.textContent = oldText;
      }
    };
  }
}

/* ================= 系统在线升级 ================= */
let upgradeToken = '';

function upgradeCancelToken() {
  const tk = upgradeToken;
  if (!tk) return;
  upgradeToken = '';
  API.post(API_BASE + 'upgrade.php?action=cancel', { token: tk }).catch(() => { /* 清理失败无碍 */ });
}

/* ================= 主题化确认弹窗（替代浏览器原生 confirm：与主题风格同步 + 居中） ================= */
// 用法：await uiConfirm('文案') / await uiConfirm('文案', { title, okText, danger })
function uiConfirm(message, opts) {
  const m = document.getElementById('confirmModal');
  if (!m) return Promise.resolve(window.confirm(message)); // 弹窗缺失时兜底原生
  const o = opts || {};
  const t = document.getElementById('confirmTitle');
  const txt = document.getElementById('confirmText');
  const ok = document.getElementById('confirmOkBtn');
  const cancel = document.getElementById('confirmCancelBtn');
  const x = document.getElementById('confirmXBtn');
  t.textContent = o.title || '确认操作';
  txt.textContent = message;
  ok.textContent = o.okText || '确定';
  ok.className = 'btn ' + (o.danger ? 'btn-danger' : 'btn-primary');
  m.classList.add('show');
  return new Promise(resolve => {
    function done(v) {
      m.classList.remove('show');
      ok.onclick = cancel.onclick = x.onclick = null;
      m.removeEventListener('click', onBg);
      document.removeEventListener('keydown', onKey);
      resolve(v);
    }
    function onBg(e) { if (e.target === m) done(false); }
    function onKey(e) { if (e.key === 'Escape') done(false); }
    ok.onclick = () => done(true);
    cancel.onclick = () => done(false);
    x.onclick = () => done(false);
    m.addEventListener('click', onBg);
    document.addEventListener('keydown', onKey);
  });
}

function bindVersionCheck() {
  const btn = document.getElementById('checkUpdateBtn');
  const result = document.getElementById('versionCheckResult');
  if (!btn || !result) return;

  // 渲染检测结果（手动点击与进后台自动检测共用）
  const renderResult = (d) => {
    if (d.available === false) {
      result.className = 'version-check-result ok';
      result.innerHTML = `✅ 当前已是最新版本 <b>${d.current}</b>`;
      result.hidden = false;
      return;
    }
    const cl = (d.changelog || []).map(c => `<li class="cl-${c.type || 'feature'}">${String(c.item).replace(/[&<>]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[ch]))}</li>`).join('');
    const sizeStr = d.size ? (() => { const s = d.size; return s > 1048576 ? (s/1048576).toFixed(2)+' MB' : s > 1024 ? (s/1024).toFixed(1)+' KB' : s+' B'; })() : '';
    result.className = 'version-check-result new';
    if (!d.package_url) {
      result.innerHTML = `🎉 发现新版本 <b>${d.latest}</b>（当前 ${d.current}）
        ${cl ? `<ul class="vc-changelog">${cl}</ul>` : ''}
        <div class="vc-hint">新版完整升级包尚未同步到更新源，请稍后再点「检查更新」，或联系作者获取。</div>`;
      result.hidden = false;
      return;
    }
    result.innerHTML = `
      🎉 发现新版本 <b>${d.latest}</b>（当前 ${d.current}）${sizeStr ? ' · ' + sizeStr : ''}${d.full_pkg ? ' · 完整包' : ''}
      ${cl ? `<ul class="vc-changelog">${cl}</ul>` : ''}
      <div class="vc-hint">
        <button class="btn btn-primary" id="vcDownloadApplyBtn" type="button">🔽 一键下载并升级</button>
        <span style="margin-left:8px">自动备份原文件，失败自动回滚；升级后页面自动刷新。</span>
      </div>
    `;
    result.hidden = false;

    const applyBtn = result.querySelector('#vcDownloadApplyBtn');
    if (applyBtn) applyBtn.onclick = async () => {
      applyBtn.disabled = true;
      applyBtn.textContent = '下载中...';
      try {
        const dlRes = await API.post(API_BASE + 'version.php?action=download', {
          url: d.package_url,
          md5: d.package_md5
        });
        const token = dlRes.token;
        if (!token) throw new Error('下载失败：未获取到升级会话 token');
        applyBtn.textContent = '应用中...';
        await API.post(API_BASE + 'upgrade.php?action=apply', { token });
        applyBtn.textContent = '升级成功，即将刷新...';
        toast('升级完成：已更新到 ' + d.latest + '，页面即将刷新', 'success');
        setTimeout(() => location.reload(), 2500);
      } catch (e) {
        applyBtn.disabled = false;
        applyBtn.textContent = '🔽 一键下载并升级';
        const msg = (e && e.message) ? e.message : '未知错误';
        // code=2：后端已自动回滚，data.failed 含每个失败文件的路径与真实原因
        const failed = e && e.data && Array.isArray(e.data.failed) ? e.data.failed : [];
        if (failed.length) {
          const lines = failed.slice(0, 6).map(f => '• ' + f.path + ' — ' + (f.reason || '写入失败')).join('\n');
          const more = failed.length > 6 ? '\n…（共 ' + failed.length + ' 个文件失败，已自动回滚）' : '';
          result.className = 'version-check-result err';
          result.innerHTML = '';
          const p = document.createElement('div');
          p.textContent = '❌ ' + msg;
          const pre = document.createElement('pre');
          pre.style.cssText = 'white-space:pre-wrap;text-align:left;margin:8px 0 0;font:12px/1.6 inherit;max-height:200px;overflow:auto;';
          pre.textContent = lines + more;
          result.appendChild(p);
          result.appendChild(pre);
          result.hidden = false;
          result.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        toast('升级失败：' + msg, 'error');
      }
    };
  };

  // 顶栏版本号旁亮「可更新」徽标，点击直达备份与更新页
  const showBadge = (latest) => {
    const ver = document.getElementById('appVer');
    if (!ver || document.getElementById('updateBadge')) return;
    const badge = document.createElement('a');
    badge.id = 'updateBadge';
    badge.className = 'update-badge';
    badge.href = 'javascript:void(0)';
    badge.textContent = '🆕 ' + latest + ' 可更新';
    badge.title = '点击前往「备份与更新」一键升级';
    badge.onclick = () => {
      const tab = document.querySelector('.admin-tabs .tab[data-tab="backup"]');
      if (tab) tab.click();
      result.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };
    ver.after(badge);
  };

  // 手动检查：强制绕过服务端 1h 缓存
  btn.onclick = async () => {
    btn.disabled = true;
    btn.textContent = '检查中...';
    result.hidden = true;
    try {
      const d = await API.get(API_BASE + 'version.php?action=check&nocache=1');
      localStorage.setItem('sp_update_check_ts', String(Date.now()));
      renderResult(d);
      if (d.available) showBadge(d.latest);
    } catch (e) {
      result.className = 'version-check-result err';
      result.innerHTML = '❌ 检查失败：' + (e.message || '无法连接到更新源，请稍后重试。');
      result.hidden = false;
    } finally {
      btn.disabled = false;
      btn.textContent = '🔍 检查更新';
    }
  };

  // 进后台自动静默检测：localStorage 1h 节流 + 服务端 1h 缓存，失败静默不打扰
  const AUTO_TTL = 3600 * 1000;
  const last = parseInt(localStorage.getItem('sp_update_check_ts') || '0', 10);
  if (Date.now() - last < AUTO_TTL) return;
  setTimeout(async () => {
    try {
      const d = await API.get(API_BASE + 'version.php?action=check');
      localStorage.setItem('sp_update_check_ts', String(Date.now()));
      if (d && d.available) {
        renderResult(d);
        showBadge(d.latest);
      }
    } catch (e) { /* 自动检测失败静默：不影响后台正常使用 */ }
  }, 2500);
}

function bindUpgrade() {
  const btn = document.getElementById('upgradeBtn');
  const fileInput = document.getElementById('upgradeFile');
  const applyBtn = document.getElementById('upgradeApplyBtn');
  if (!btn || !fileInput || !applyBtn) return;

  const curVer = document.getElementById('upgradeCurVer');
  if (curVer) curVer.textContent = APP_VERSION;

  // 弹窗取消 / 关闭时清理服务端升级会话（暂存目录）
  document.querySelectorAll('#upgradeModal [data-close="upgradeModal"]').forEach(el => {
    el.addEventListener('click', upgradeCancelToken);
  });

  // 第一步：上传升级包 → 校验 + 变更预览
  btn.onclick = () => fileInput.click();
  fileInput.addEventListener('change', async () => {
    const file = fileInput.files && fileInput.files[0];
    fileInput.value = '';
    if (!file) return;
    if (!/\.zip$/i.test(file.name)) {
      toast('请选择 SolarPanel 升级工具生成的 .zip 升级包', 'error');
      return;
    }
    if (!(await uiConfirm('上传升级包「' + file.name + '」进行版本校验与变更预览？\n校验通过后需再点击「确认升级」才会真正应用。', { title: '校验升级包', okText: '上传并校验' }))) return;

    btn.disabled = true;
    const oldText = btn.textContent;
    btn.textContent = '校验中…';
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await API.postForm(API_BASE + 'upgrade.php?action=check', fd);
      upgradeToken = res.token;
      document.getElementById('upgradeFrom').textContent = res.current;
      document.getElementById('upgradeTo').textContent = res.to;
      document.getElementById('upgradeCounts').textContent = '更新 ' + res.updated + ' 个文件' + (res.deleted ? '，移除 ' + res.deleted + ' 个废弃文件' : '');
      document.getElementById('upgradeFiles').innerHTML = res.files.map(f => '<div class="upgrade-file">' + esc(f) + '</div>').join('');
      const delBlock = document.getElementById('upgradeDelBlock');
      if (res.deleted_files && res.deleted_files.length) {
        delBlock.hidden = false;
        document.getElementById('upgradeDelFiles').innerHTML = res.deleted_files.map(f => '<div class="upgrade-file upgrade-file-del">' + esc(f) + '</div>').join('');
      } else {
        delBlock.hidden = true;
      }
      document.getElementById('upgradeModal').classList.add('show');
    } catch (err) {
      toast('升级包校验未通过：' + err.message, 'error');
    } finally {
      btn.disabled = false;
      btn.textContent = oldText;
    }
  });

  // 第二步：确认升级 → 备份原文件 + 原子替换
  applyBtn.onclick = async () => {
    if (!upgradeToken) return;
    if (!(await uiConfirm('开始应用升级？被替换 / 删除的原文件会自动备份到 uploads/upgrade_backup，可手动回滚。', { title: '确认升级', okText: '🚀 开始升级' }))) return;
    applyBtn.disabled = true;
    const oldText = applyBtn.textContent;
    applyBtn.textContent = '升级中…';
    try {
      const res = await API.post(API_BASE + 'upgrade.php?action=apply', { token: upgradeToken });
      upgradeToken = '';
      document.getElementById('upgradeModal').classList.remove('show');
      toast('升级完成：更新 ' + res.updated + ' 个文件' + (res.deleted ? '、移除 ' + res.deleted + ' 个废弃文件' : '') + '，当前版本 ' + res.version + '，页面即将刷新', 'success');
      setTimeout(() => location.reload(), 1600);
    } catch (err) {
      if (err.code === 2 && err.data && Array.isArray(err.data.failed) && err.data.failed.length) {
        // code=2 部分失败：保留弹窗展示失败明细（含备份路径），可重试
        document.getElementById('upgradeFiles').innerHTML = err.data.failed.map(f => '<div class="upgrade-file upgrade-file-del">' + esc(f.path) + ' — ' + esc(f.reason) + '</div>').join('');
        document.getElementById('upgradeCounts').textContent = '已更新 ' + err.data.updated + ' 个，失败 ' + err.data.failed.length + ' 个（原文件备份于 ' + esc(err.data.backup || '—') + '）';
        document.getElementById('upgradeDelBlock').hidden = true;
        toast('部分文件升级失败，站点可能处于混合版本状态：请重试，或按失败明细手动处理', 'error');
      } else {
        toast('升级失败：' + err.message, 'error');
        upgradeCancelToken();
        document.getElementById('upgradeModal').classList.remove('show');
      }
    } finally {
      applyBtn.disabled = false;
      applyBtn.textContent = oldText;
    }
  };
}

/* ================= 账号 & 用户管理 ================= */
async function loadUsers() {
  const tbody = document.getElementById('usersTbody');
  if (!tbody) return;
  if (state.role !== 'admin') return; // 仅管理员可管理用户
  try {
    const data = await API.get(API_BASE + 'user.php?action=list');
    tbody.innerHTML = '';
    (data.users || []).forEach(u => {
      const role = ROLE_LABEL[u.role] ? u.role : 'admin';
      const tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + u.id + '</td>' +
        '<td>' + esc(u.username) + (u.id === data.current_id ? ' <span style="font-size:12px;color:var(--accent)">（当前）</span>' : '') + '</td>' +
        '<td style="color:var(--text-muted)">' + esc(u.name || '-') + '</td>' +
        '<td><span class="role-badge role-' + role + '">' + ROLE_LABEL[role] + '</span></td>' +
        '<td><div class="actions">' +
        '<button class="btn btn-sm" data-act="pwd">重置密码</button>' +
        '<button class="btn btn-sm" data-act="role" ' + (u.id === data.current_id ? 'disabled title="不能修改当前登录账号的权限组"' : '') + '>权限</button>' +
        '<button class="btn btn-sm btn-danger" data-act="del" ' + (u.id === data.current_id ? 'disabled title="不能删除当前登录账号"' : '') + '>删除</button>' +
        '</div></td>';
      tr.querySelectorAll('[data-act]').forEach(btn => {
        btn.onclick = () => userAction(btn.dataset.act, u);
      });
      tbody.appendChild(tr);
    });
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted)">用户列表加载失败：' + esc(e.message) + '</td></tr>';
  }
}

function userAction(act, u) {
  if (act === 'pwd') {
    document.getElementById('userPwdTitle').textContent = '重置密码 - ' + u.username;
    document.getElementById('up_id').value = u.id;
    document.getElementById('up_password').value = '';
    document.getElementById('userPwdModal').classList.add('show');
    return;
  }
  if (act === 'role') {
    document.getElementById('userRoleTitle').textContent = '权限分配 - ' + (u.name || u.username);
    document.getElementById('ur_id').value = u.id;
    document.getElementById('ur_role').value = ROLE_LABEL[u.role] ? u.role : 'admin';
    document.getElementById('userRoleModal').classList.add('show');
    return;
  }
  if (act === 'del') {
    if (!confirm('确定删除用户「' + (u.name || u.username) + '」？')) return;
    API.post(API_BASE + 'user.php?action=delete', { id: u.id })
      .then(() => { toast('已删除', 'success'); loadUsers(); })
      .catch(e => toast(e.message, 'error'));
  }
}

function bindAccount() {
  document.getElementById('saveNameBtn').onclick = async () => {
    try {
      const res = await API.post(API_BASE + 'user.php?action=update', {
        name: document.getElementById('accName').value.trim(),
      });
      document.getElementById('whoami').textContent = res.name || '';
      toast('已保存', 'success');
    } catch (e) {
      toast(e.message, 'error');
    }
  };

  document.getElementById('pwdForm').addEventListener('submit', async e => {
    e.preventDefault();
    const oldPwd = document.getElementById('oldPwd').value;
    const newPwd = document.getElementById('newPwd').value;
    const newPwd2 = document.getElementById('newPwd2').value;
    if (newPwd !== newPwd2) {
      toast('两次输入的新密码不一致', 'error');
      return;
    }
    const btn = document.getElementById('savePwdBtn');
    btn.disabled = true;
    try {
      await API.post(API_BASE + 'user.php?action=change_password', {
        old_password: oldPwd,
        new_password: newPwd,
      });
      toast('密码已修改', 'success');
      document.getElementById('oldPwd').value = '';
      document.getElementById('newPwd').value = '';
      document.getElementById('newPwd2').value = '';
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      btn.disabled = false;
    }
  });

  // 新增用户（可指定权限组）
  document.getElementById('userAddForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('userAddBtn');
    btn.disabled = true;
    try {
      await API.post(API_BASE + 'user.php?action=create', {
        username: document.getElementById('u_username').value.trim(),
        name: document.getElementById('u_name').value.trim(),
        password: document.getElementById('u_password').value,
        role: document.getElementById('u_role').value,
      });
      toast('用户已创建', 'success');
      document.getElementById('u_username').value = '';
      document.getElementById('u_name').value = '';
      document.getElementById('u_password').value = '';
      loadUsers();
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      btn.disabled = false;
    }
  });

  // 修改指定用户权限组
  document.getElementById('userRoleForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('userRoleSaveBtn');
    btn.disabled = true;
    try {
      await API.post(API_BASE + 'user.php?action=set_role', {
        id: Number(document.getElementById('ur_id').value) || 0,
        role: document.getElementById('ur_role').value,
      });
      document.getElementById('userRoleModal').classList.remove('show');
      toast('权限已更新', 'success');
      loadUsers();
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      btn.disabled = false;
    }
  });

  // 重置指定用户密码
  document.getElementById('userPwdForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('userPwdSaveBtn');
    btn.disabled = true;
    try {
      await API.post(API_BASE + 'user.php?action=set_password', {
        id: Number(document.getElementById('up_id').value) || 0,
        new_password: document.getElementById('up_password').value,
      });
      document.getElementById('userPwdModal').classList.remove('show');
      toast('密码已重置', 'success');
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      btn.disabled = false;
    }
  });

  loadUsers();
}
