/**
 * 主页逻辑：全部展示内容由后端 public.php 提供
 */
const state = {
  settings: {},
  groups: [],
  user: null,
  lanMode: false, // 最终生效的地址模式：lan=true 用内网地址；lan=false 用外网地址；由 default_lan_mode 决定（public / lan / auto 三选一）
  theme: 'dark',
  sortingGroupId: null, // 当前正在排序的分组 id（仅管理员，null = 未排序）
  sortDirty: false, // 当前排序分组有未保存的拖拽改动
  groupIntroDone: false, // 首屏分组入场动画只播一次（后续重绘不闪动画）
  cardsIntroDone: false, // 首屏卡片入场动画只播一次（后续重绘不闪动画）
  navActiveGroupIdx: 0,  // 导航栏模式（card_style=nav）当前激活分组索引
};

const API_BASE = '/api/';

/** 判断当前浏览器访问的 hostname 是否看起来在内网 / 本地环境
 *  用于 default_lan_mode = 'auto' 时自动选用内网地址还是外网地址
 *  @param {string} [lanHostnames] 管理员配置的自定义内网域名（逗号分隔）
 */
function isLikelyLAN(lanHostnames) {
  const h = (location.hostname || '').toLowerCase();
  if (!h) return false;
  // 管理员配置的自定义内网域名列表优先匹配
  if (lanHostnames) {
    const list = lanHostnames.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
    if (list.includes(h)) return true;
    for (const pat of list) {
      if (pat.startsWith('.') && h.endsWith(pat)) return true;
    }
  }
  // localhost / 私网域名
  if (h === 'localhost' || h.endsWith('.local') || h.endsWith('.lan') || h.endsWith('.home') || h.endsWith('.intranet')) return true;
  // IPv6 本地链路 fe80:: / ULA fcxx:: / fdxx::
  if (h.startsWith('fe8:') || h.startsWith('fc') || h.startsWith('fd')) return true;
  // IPv4 私网
  const parts = h.split('.').map(Number);
  if (parts.length === 4) {
    if (parts[0] === 10) return true;                                                          // 10.x.x.x
    if (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31) return true;                     // 172.16-31.x.x
    if (parts[0] === 192 && parts[1] === 168) return true;                                      // 192.168.x.x
    if (parts[0] === 127) return true;                                                          // 127.x.x.x
    if (parts[0] === 169 && parts[1] === 254) return true;                                      // 169.254.x.x (LLMNR)
  }
  return false;
}

function bindTopbarAutoHide() {
  const topbar = document.querySelector('.topbar');
  if (!topbar) return;

  let lastY = window.scrollY;
  let ticking = false;
  const HIDE_THRESHOLD = 64;

  function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => {
      const y = window.scrollY;
      const dy = y - lastY;

      // 规则 1：到顶部 → 显示
      if (y <= 10) {
        topbar.classList.remove('hidden-scroll');
      }
      // 规则 2：向下滚过阈值 → 隐藏
      else if (dy > HIDE_THRESHOLD) {
        topbar.classList.add('hidden-scroll');
      }
      // 注意：不要写「向上滚立即显示」—— 需求是只有到顶才显示

      lastY = y;
      ticking = false;
    });
  }

  window.addEventListener('scroll', onScroll, { passive: true });
}

async function boot() {
  bindTopbarAutoHide();
  let data;
  try {
    data = await API.get(API_BASE + 'public.php');
  } catch (e) {
    document.getElementById('groupsWrap').innerHTML =
      '<div class="empty-tip glass">数据加载失败：' + esc(e.message) + '</div>';
    return;
  }

  // 访客访问密码：开启且未通过验证时，仅渲染锁屏页，不加载分组/卡片/新闻
  if (data.guest_required) {
    state.settings = data.settings || {};
    state.theme = applyTheme(state.settings);
    updateThemeBtn();
    renderBase();
    startClock();
    renderGuestLock();
    return;
  }

  state.settings = data.settings || {};
  state.groups = data.groups || [];
  state.user = data.user || null;
  state.theme = applyTheme(state.settings);
  updateThemeBtn();

  renderBase();
  startClock();
  renderSearch();
  renderGroups();
  bindGlobal();
  initNewsSwitch();
}

/* ---------- 基础元素渲染（标题/logo/公告/页脚/壁纸/内容区） ---------- */
function renderBase() {
  const s = state.settings;

  document.title = s.site_title || 'SolarPanel';
  document.getElementById('siteTitle').textContent = s.site_title || 'SolarPanel';

  const logo = document.getElementById('logoImg');
  if (s.site_logo) {
    logo.src = assetUrl(s.site_logo);
    logo.hidden = false;
    logo.onerror = () => { logo.hidden = true; };
  }

  // 壁纸与遮罩（未设置壁纸时使用柔和渐变背景，不叠加遮罩；有壁纸时隐藏风格装饰层）
  const bg = document.getElementById('bgLayer');
  const mask = document.getElementById('bgMask');
  const hasWp = !!(s.wallpaper || s.wallpaper_source);
  document.body.classList.toggle('has-wallpaper', hasWp);
  if (hasWp) {
    const opacity = parseFloat(s.mask_opacity);
    mask.style.background =
      'rgba(0,0,0,' + (isNaN(opacity) ? 0.35 : Math.min(Math.max(opacity, 0), 1)) + ')';
    // 壁纸模糊（0~30px，默认 6），同步放大避免模糊后边缘露白
    const bRaw = parseInt(s.wallpaper_blur, 10);
    const blur = isNaN(bRaw) ? 6 : Math.min(Math.max(bRaw, 0), 30);
    if (blur > 0) {
      bg.style.filter = 'blur(' + blur + 'px)';
      bg.style.transform = 'scale(1.1)';
    } else {
      bg.style.filter = '';
      bg.style.transform = '';
    }
  } else {
    bg.style.backgroundImage = '';
    mask.style.background = 'transparent';
    bg.style.filter = '';
    bg.style.transform = '';
  }
  // 壁纸来源：每日壁纸源优先（异步拉取，sessionStorage 当日缓存），回退静态壁纸
  const applyWp = (url) => {
    if (url) bg.style.backgroundImage = 'url("' + assetUrl(url).replace(/"/g, '%22') + '")';
    else bg.style.backgroundImage = '';
  };
  if (s.wallpaper_source) {
    applyDailyWallpaper(s.wallpaper_source, applyWp, s.wallpaper || '');
  } else if (s.wallpaper) {
    applyWp(s.wallpaper);
  }

  // 内容区域尺寸（最大宽度 / 左右边距 / 顶部边距 / 底部边距）
  const num = (v, d) => {
    const n = parseInt(v, 10);
    return isNaN(n) ? d : n;
  };
  const rs = document.documentElement.style;
  rs.setProperty('--content-max', num(s.content_maxwidth, 1200) + 'px');
  rs.setProperty('--content-px', num(s.content_pad_lr, 20) + 'px');
  rs.setProperty('--content-pt', num(s.content_pad_top, 0) + 'px');
  rs.setProperty('--content-pb', num(s.content_pad_bottom, 40) + 'px');

  // 公告：后台开关打开且有内容时才显示
  const ann = document.getElementById('annBar');
  if (s.announcement_show === '1' && s.announcement && sessionStorage.getItem('sp_ann_dismissed') !== '1') {
    ann.textContent = '📢 ' + s.announcement;
    ann.title = s.announcement + '（点击关闭）';
    ann.hidden = false;
    ann.onclick = () => {
      ann.hidden = true;
      sessionStorage.setItem('sp_ann_dismissed', '1');
    };
  }

  // 页脚：文字（可选）+ 管理入口 + 当前版本号
  const footer = document.getElementById('footer');
  document.getElementById('footerText').textContent = s.footer || '';
  document.getElementById('footerSep').hidden = !s.footer;
  const fv = document.getElementById('footerVer');
  if (fv) {
    fv.textContent = typeof APP_VERSION !== 'undefined' ? APP_VERSION : '';
    fv.onclick = openChangelogModal;
  }
  // 备案信息
  const beian = document.getElementById('footerBeian');
  beian.innerHTML = '';
  const addBeian = (number, link) => {
    if (!number) return;
    const el = link ? document.createElement('a') : document.createElement('span');
    if (link) { el.href = link; el.target = '_blank'; el.rel = 'noopener'; }
    el.textContent = number;
    beian.appendChild(el);
  };
  if (s.icp_show === '1') addBeian(s.icp_number, (s.icp_link || '').trim());
  if (s.police_show === '1') addBeian(s.police_number, (s.police_link || '').trim());
  // 两个都显示则加分隔点
  if (beian.children.length === 2) {
    const dot = document.createElement('span');
    dot.className = 'beian-dot';
    dot.textContent = '·';
    beian.insertBefore(dot, beian.children[1]);
  }
  beian.hidden = beian.children.length === 0;
  footer.hidden = false;

  // 时钟 / 天气显隐与并排；搜索栏宽度
  const clockOn = s.clock_show !== '0';
  const weatherOn = s.weather_show !== '0';
  document.getElementById('clockTime').hidden = !clockOn;
  document.getElementById('clockDate').hidden = !clockOn;
  const clockArea = document.getElementById('clockArea');
  if (clockOn || weatherOn) clockArea.classList.add('show');
  const sw = parseInt(s.search_width, 10);
  if (!isNaN(sw) && sw > 200) document.getElementById('searchBox').style.maxWidth = sw + 'px';
  if (weatherOn) renderWeather(s.weather_city || '');

  // 地址模式：localStorage 优先（访客自定义），否则用后台默认
  const savedLan = localStorage.getItem('sp_lan_mode');
  state.lanMode = savedLan !== null
    ? savedLan === 'lan'
    : (s.default_lan_mode === 'lan' ||
       (s.default_lan_mode === 'auto' && isLikelyLAN(s.lan_hostnames)));
  updateLanBtn();

  // 管理入口：guest 角色跳登录页（需管理员账号），其他已登录角色直达后台
  const link = document.getElementById('adminLink');
  if (state.user) {
    if (state.user.role === 'guest') {
      link.href = 'frontend/login.html';
    } else {
      link.href = 'frontend/admin.html';
      link.title = '管理后台（' + (state.user.name || state.user.username) + '）';
    }
  }
}

/* ---------- 天气 ---------- */
async function renderWeather(city) {
  const el = document.getElementById('weatherArea');
  try {
    const w = await API.get('/api/weather.php?action=current' + (city ? '&city=' + encodeURIComponent(city) : ''));
    if (!w || w.temp === undefined) return;
    // 主行：emoji + 天气描述 + 温度；副行：城市 + 湿度 + 风速（emoji+文字）
    el.innerHTML =
      '<div class="w-main">' +
        '<span class="w-emoji">' + w.emoji + '</span>' +
        '<span class="w-desc">' + esc(w.desc) + '</span>' +
        '<span class="w-temp">' + w.temp + '°</span>' +
      '</div>' +
      '<div class="w-sub">' +
        '<span class="w-city">📍 ' + esc(w.city || '') + '</span>' +
        '<span class="w-sep">·</span>' +
        '<span class="w-humidity">💧 ' + w.humidity + '%</span>' +
        '<span class="w-sep">·</span>' +
        '<span class="w-wind">🌬️ ' + w.wind + 'km/h</span>' +
      '</div>';
    el.hidden = false;
  } catch (e) {
    // 天气服务不可用时静默隐藏，不影响主页
    el.hidden = true;
  }
}

/* ---------- 时钟 ---------- */
function startClock() {
  const timeEl = document.getElementById('clockTime');
  const dateEl = document.getElementById('clockDate');
  const week = ['日', '一', '二', '三', '四', '五', '六'];
  const pad = n => String(n).padStart(2, '0');
  const tick = () => {
    const d = new Date();
    timeEl.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    dateEl.textContent = d.getFullYear() + ' 年 ' + (d.getMonth() + 1) + ' 月 ' + d.getDate() + ' 日 · 星期' + week[d.getDay()];
  };
  tick();
  setInterval(tick, 1000);
}

/* ---------- 搜索 ---------- */
/* 搜索引擎 logo：公共图标服务多源回退（与管理端 favicon 抓取源一致），全部失败则隐藏图标只显示文字 */
const ENGINE_ICON_SOURCES = [
  host => 'https://api.iowen.cn/favicon/' + host + '.png',
  host => 'https://favicon.cccyun.cc/' + host,
  host => 'https://favicon.im/' + host + '?larger=true',
  host => 'https://www.google.com/s2/favicons?domain=' + host + '&sz=64',
];

function setEngineLogo(img, engineUrl) {
  let host = '';
  try { host = new URL(engineUrl).hostname; } catch (e) { /* 非法地址不取图标 */ }
  let idx = 0;
  img.onerror = () => {
    if (idx >= ENGINE_ICON_SOURCES.length) { img.hidden = true; img.onerror = null; return; }
    img.src = ENGINE_ICON_SOURCES[idx++](host);
  };
  if (host) img.src = ENGINE_ICON_SOURCES[idx++](host);
  else img.hidden = true;
}

function renderSearch() {
  let engines = [];
  try {
    engines = JSON.parse(state.settings.search_engines || '[]');
  } catch (e) { /* 忽略格式错误 */ }
  engines = engines.filter(e => e && e.name && e.url);
  if (!engines.length) return;

  const wrap = document.getElementById('engineSelect');
  const btn = document.getElementById('engineBtn');
  const curLogo = document.getElementById('engineCurLogo');
  const curName = document.getElementById('engineCurName');
  const menu = document.getElementById('engineMenu');
  let curIdx = Math.max(0, engines.findIndex(e => e.name === state.settings.search_default));

  // 下拉项：logo + 名称
  engines.forEach((e, i) => {
    const item = document.createElement('button');
    item.type = 'button';
    item.className = 'engine-item' + (i === curIdx ? ' active' : '');
    item.dataset.idx = String(i);
    const img = document.createElement('img');
    img.className = 'engine-logo';
    img.alt = '';
    img.loading = 'lazy';
    setEngineLogo(img, e.url);
    const name = document.createElement('span');
    name.textContent = e.name;
    item.appendChild(img);
    item.appendChild(name);
    item.addEventListener('click', () => {
      curIdx = i;
      refreshCur();
      markActive();
      toggleMenu(false);
    });
    menu.appendChild(item);
  });

  function refreshCur() {
    const cur = engines[curIdx];
    curName.textContent = cur.name;
    curLogo.hidden = false;
    setEngineLogo(curLogo, cur.url);
    markActive();
  }
  function markActive() {
    menu.querySelectorAll('.engine-item').forEach(el => {
      el.classList.toggle('active', Number(el.dataset.idx) === curIdx);
    });
  }
  function toggleMenu(show) {
    const open = show === undefined ? menu.hidden : show;
    menu.hidden = !open;
    wrap.classList.toggle('open', open);
  }

  btn.addEventListener('click', e => { e.stopPropagation(); toggleMenu(); });
  document.addEventListener('click', e => {
    if (!wrap.contains(e.target)) toggleMenu(false);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !menu.hidden) toggleMenu(false);
  });

  refreshCur();
  document.getElementById('searchBox').hidden = (state.settings.search_bar_enabled === '0');

  // 搜索历史：localStorage 存储（最多 10 条）
  const HIST_KEY = 'sp_search_history';
  const FAV_KEY = 'sp_search_favorites';

  function loadHistory() {
    try { return JSON.parse(localStorage.getItem(HIST_KEY)) || []; } catch (e) { return []; }
  }
  function saveHistory(arr) { localStorage.setItem(HIST_KEY, JSON.stringify(arr.slice(0, 10))); }
  function loadFavorites() {
    try { return JSON.parse(localStorage.getItem(FAV_KEY)) || []; } catch (e) { return []; }
  }
  function saveFavorites(arr) { localStorage.setItem(FAV_KEY, JSON.stringify(arr)); }

  const dropdown = document.getElementById('searchHistoryDropdown');
  const favSection = document.getElementById('shFavSection');
  const recentSection = document.getElementById('shRecentSection');
  const favTags = document.getElementById('shFavTags');
  const recentTags = document.getElementById('shRecentTags');
  const emptyTip = document.getElementById('shEmptyTip');
  const input = document.getElementById('searchInput');

  function toggleFav(kw) {
    const favs = loadFavorites();
    const idx = favs.findIndex(f => f.kw === kw);
    if (idx >= 0) favs.splice(idx, 1);
    else favs.unshift({ kw, ts: Date.now() });
    saveFavorites(favs);
  }

  function recordHistory(kw) {
    const h = loadHistory().filter(k => k !== kw);
    h.unshift(kw);
    saveHistory(h);
  }

  function buildTag(kw, faved) {
    const tag = document.createElement('span');
    tag.className = 'sh-tag' + (faved ? ' faved' : '');
    tag.dataset.kw = kw;
    const fav = document.createElement('span');
    fav.className = 'sh-tag-fav';
    fav.textContent = faved ? '★' : '☆';
    fav.title = faved ? '取消收藏' : '收藏';
    const text = document.createElement('span');
    text.textContent = kw;
    tag.appendChild(text);
    tag.appendChild(fav);
    return tag;
  }

  function renderHistoryDropdown() {
    const h = loadHistory();
    const favs = loadFavorites();
    const recent = h.filter(kw => !favs.some(f => f.kw === kw)).slice(0, 10);

    favTags.innerHTML = '';
    recentTags.innerHTML = '';
    favs.forEach(f => favTags.appendChild(buildTag(f.kw, true)));
    recent.forEach(kw => recentTags.appendChild(buildTag(kw, false)));

    favSection.hidden = !favs.length;
    recentSection.hidden = !recent.length;
    emptyTip.hidden = !!(favs.length || recent.length);
  }

  function showDropdown() {
    renderHistoryDropdown();
    dropdown.hidden = false;
  }
  function hideDropdown() { dropdown.hidden = true; }

  dropdown.addEventListener('click', e => {
    const tag = e.target.closest('.sh-tag');
    if (!tag) return;
    e.stopPropagation();
    // 点击星标：切换收藏
    if (e.target.classList.contains('sh-tag-fav')) {
      toggleFav(tag.dataset.kw);
      renderHistoryDropdown();
      return;
    }
    // 点击标签主体：直接搜索
    input.value = tag.dataset.kw;
    doSearch();
    hideDropdown();
  });

  document.getElementById('shClearFav').onclick = e => {
    e.stopPropagation();
    saveFavorites([]);
    renderHistoryDropdown();
  };
  document.getElementById('shClearRecent').onclick = e => {
    e.stopPropagation();
    saveHistory([]);
    renderHistoryDropdown();
  };

  input.addEventListener('focus', showDropdown);
  input.addEventListener('input', () => { if (input.value) hideDropdown(); });
  input.addEventListener('blur', () => setTimeout(hideDropdown, 150));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') hideDropdown(); });
  document.addEventListener('click', e => {
    if (!dropdown.contains(e.target) && !input.contains(e.target)) hideDropdown();
  });

  document.getElementById('searchBtn').onclick = doSearch;
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter') doSearch();
  });

  function doSearch() {
    const kw = input.value.trim();
    if (!kw) return;
    recordHistory(kw);
    hideDropdown();
    const engine = engines[curIdx] || engines[0];
    if (!safeUrl(engine.url)) {
      toast('搜索引擎地址协议不合法（仅支持 http / https）', 'error');
      return;
    }
    const url = engine.url.includes('%s')
      ? engine.url.replace('%s', encodeURIComponent(kw))
      : engine.url + encodeURIComponent(kw);
    window.open(url, '_blank');
  }
}

/* ---------- 分组与卡片 ---------- */

/** 渲染单个分组到容器（nav 模式只渲染选中分组时复用） */
function renderGroupCard(container, g, styleApp, idx, skipIntro) {
  const section = document.createElement('section');
  section.className = 'group glass' + (state.sortingGroupId === g.id ? ' sorting' : '');
  section.id = 'group-' + g.id;
  // 首屏入场动画（nav 模式跳过，因为每次切换分组都重绘，不能闪）
  if (!skipIntro && !state.groupIntroDone) {
    section.classList.add('group-in');
    section.style.animationDelay = Math.min((idx || 0) * 120, 960) + 'ms';
  }

  const head = document.createElement('div');
  head.className = 'group-head';
  const h2 = document.createElement('h2');
  h2.textContent = g.title;
  head.appendChild(h2);
  // 隐藏分组徽章（仅登录管理员会拿到隐藏分组数据）
  if (g.is_visible === 0) {
    const hid = document.createElement('span');
    hid.className = 'hidden-badge';
    hid.textContent = '🚫 已隐藏';
    hid.title = '该分组已在前端隐藏，仅登录管理员可见';
    head.appendChild(hid);
  }
  if (g.description) {
    const desc = document.createElement('span');
    desc.className = 'desc';
    desc.textContent = g.description;
    head.appendChild(desc);
  }
  // 管理员/编辑：分组名后显示排序操作 + 快速添加
  const isEditor = state.user && (state.user.role === 'admin' || state.user.role === 'editor');
  if (isEditor) {
    const sortWrap = document.createElement('span');
    sortWrap.className = 'group-sort';
    if (state.sortingGroupId === g.id) {
      const saveBtn = document.createElement('button');
      saveBtn.type = 'button';
      saveBtn.className = 'btn btn-sm btn-save-order' + (state.sortDirty ? '' : ' disabled');
      saveBtn.textContent = '💾 保存排序';
      saveBtn.disabled = !state.sortDirty;
      saveBtn.onclick = () => saveGroupSort(g.id);
      const cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'btn btn-sm';
      cancelBtn.textContent = '取消排序';
      cancelBtn.onclick = () => cancelGroupSort(g.id);
      sortWrap.appendChild(saveBtn);
      sortWrap.appendChild(cancelBtn);
    } else {
      const sortBtn = document.createElement('button');
      sortBtn.type = 'button';
      sortBtn.className = 'btn btn-sm btn-sort-toggle';
      sortBtn.textContent = '排序';
      sortBtn.title = '拖拽卡片调整该分组的显示顺序';
      sortBtn.onclick = () => startGroupSort(g.id);
      sortWrap.appendChild(sortBtn);
    }
    head.appendChild(sortWrap);

    if (state.sortingGroupId !== g.id) {
      const addBtn = document.createElement('span');
      addBtn.className = 'group-add-btn';
      addBtn.title = '快速添加卡片';
      addBtn.textContent = '+';
      addBtn.onclick = (e) => { e.stopPropagation(); openQuickAddCard(g.id); };
      sortWrap.appendChild(addBtn);
    }
  }
  section.appendChild(head);

  const cards = document.createElement('div');
  cards.className = 'cards' + (styleApp ? ' style-app' : '');
  (g.items || []).forEach((item, ci) => {
    const card = buildCard(item, styleApp);
    if (!skipIntro && !state.cardsIntroDone) {
      card.classList.add('card-in');
      const delay = Math.min((idx || 0) * 120 + ci * 60, 1200);
      card.style.animationDelay = delay + 'ms';
    }
    cards.appendChild(card);
  });
  section.appendChild(cards);

  if (!(g.items || []).length) {
    const empty = document.createElement('div');
    empty.className = 'empty-tip';
    empty.textContent = '该分组暂无卡片';
    section.appendChild(empty);
  }

  container.appendChild(section);
}

function renderGroups() {
  const wrap = document.getElementById('groupsWrap');
  const styleApp = state.settings.card_style === 'app';

  // 导航栏模式：由 renderGroupsNavbar 统一渲染（入口）
  if (state.settings.card_style === 'nav') {
    renderGroupsNavbar();
    return;
  }

  wrap.innerHTML = '';

  if (!state.groups.length) {
    wrap.innerHTML = '<div class="empty-tip glass">暂无内容，请先登录后台添加分组与卡片。</div>';
    document.getElementById('groupNav').hidden = true;
    const gnToggleBtn = document.getElementById('gnToggle');
    if (gnToggleBtn) gnToggleBtn.hidden = true;
    return;
  }

  state.groups.forEach((g, i) => {
    renderGroupCard(wrap, g, styleApp, i, false);
  });

  // 首屏入场动画已排布完成
  state.groupIntroDone = true;
  state.cardsIntroDone = true;

  // 同步左侧分组目录
  renderGroupNav();
}

/* ---------- 左侧悬浮目录条（导航视图=分组目录 / 新闻视图=平台目录，共用同一对元素） ---------- */
let __gnHideTimer = null;
let __gnManual = false;
let __gnHover = false;
function __gnSetCollapsed(c) {
  const nav = document.getElementById('groupNav');
  const t = document.getElementById('gnToggle');
  if (!nav || !t) return;
  nav.classList.toggle('gn-collapsed', c);
  t.classList.toggle('gn-collapsed', c);
  t.setAttribute('aria-expanded', String(!c));
}
function __gnArmHide() {
  // 武装 1.5s 收起防抖：到点时若非手动锁定且鼠标不在目录/把手上才收起
  if (__gnHideTimer) { clearTimeout(__gnHideTimer); __gnHideTimer = null; }
  __gnHideTimer = setTimeout(() => {
    __gnHideTimer = null;
    if (!__gnManual && !__gnHover) __gnSetCollapsed(true);
  }, 1500);
}
function __gnShowNow() {
  // 展开 + 重置 1.5s 收起防抖
  __gnSetCollapsed(false);
  __gnArmHide();
}

/** 目录条把手 / 悬停 / 滚动高亮（DOM 元素常驻，仅绑定一次） */
function bindNavChrome() {
  const nav = document.getElementById('groupNav');
  const toggle = document.getElementById('gnToggle');
  if (!nav || !toggle || toggle.__gnBound) return;
  toggle.__gnBound = true;

  // 左缘把手：收起时 ▶ 点击展开（手动锁定，不自动收起）；展开时旋转为 ◀ 点击收起
  toggle.onclick = () => {
    const willShow = nav.classList.contains('gn-collapsed');
    __gnManual = willShow;
    if (__gnHideTimer) { clearTimeout(__gnHideTimer); __gnHideTimer = null; }
    __gnSetCollapsed(!willShow);
  };
  // 悬停把手自动展开目录；移出后 1.5s 收起
  toggle.onmouseenter = () => { __gnHover = true; __gnShowNow(); };
  toggle.onmouseleave = () => { __gnHover = false; __gnArmHide(); };
  // 悬停目录期间暂停收起；移出后 1.5s 收起（手动锁定除外）
  nav.onmouseenter = () => { __gnHover = true; if (__gnHideTimer) { clearTimeout(__gnHideTimer); __gnHideTimer = null; } };
  nav.onmouseleave = () => { __gnHover = false; __gnArmHide(); };

  // 滚动：高亮当前所在区块（分组 section 或新闻卡片），停止滚动 1.5s 后自动收起（滚动解除手动锁定）
  window.addEventListener('scroll', () => {
    if (nav.hidden) return;
    const items = nav.querySelectorAll('.gn-item');
    if (!items.length) return;
    // 取顶部最接近触发线（视口 35%）且已滚过该线的区块；多列同行时 top 相同取 DOM 最前者
    let activeTarget = null, bestTop = -Infinity;
    const trigger = window.innerHeight * 0.35;
    items.forEach(it => {
      const el = document.getElementById(it.dataset.target);
      if (!el) return;
      const top = el.getBoundingClientRect().top;
      if (top <= trigger && top > bestTop) { bestTop = top; activeTarget = it.dataset.target; }
    });
    items.forEach(it => it.classList.toggle('active', it.dataset.target === activeTarget));
    __gnManual = false;
    __gnShowNow();
  }, { passive: true });
}

/** 构建一个目录项（targetId=跳转目标元素 id，dotColor=色点颜色，空则用默认灰点） */
function buildNavItem(targetId, label, dotColor) {
  const a = document.createElement('a');
  a.href = '#';
  a.className = 'gn-item';
  a.dataset.target = targetId;
  const dot = document.createElement('span');
  dot.className = 'gn-dot';
  if (dotColor) { dot.style.background = dotColor; dot.style.opacity = '1'; }
  const lab = document.createElement('span');
  lab.className = 'gn-label';
  lab.textContent = label;
  a.appendChild(dot);
  a.appendChild(lab);
  a.addEventListener('click', e => {
    e.preventDefault();
    const target = document.getElementById(targetId);
    if (target) {
      const y = target.getBoundingClientRect().top + window.pageYOffset - 90;
      window.scrollTo({ top: y, behavior: 'smooth' });
    }
  });
  return a;
}

/** 导航视图：分组目录 */
function renderGroupNav() {
  const nav = document.getElementById('groupNav');
  const toggle = document.getElementById('gnToggle');

  // 导航栏模式：侧边栏由顶部标签栏替代，隐藏
  // ⚠️ 不要碰 navbarSelect.hidden！那是 renderGroupsNavbar 的职责
  if (state.settings.card_style === 'nav') {
    if (nav) nav.hidden = true;
    if (toggle) toggle.hidden = true;
    return;
  }
  // 非 nav 模式也隐藏 navbarSelect
  const sel = document.getElementById('navbarSelect');
  if (sel) sel.hidden = true;

  const visibleGroups = state.groups.filter(g => (g.items || []).length > 0 || g.is_visible !== 0);
  nav.setAttribute('aria-label', '分组目录');
  if (toggle) { toggle.setAttribute('aria-label', '展开分组目录'); toggle.title = '展开分组目录'; }
  if (!visibleGroups.length) { nav.innerHTML = ''; nav.hidden = true; if (toggle) toggle.hidden = true; return; }

  nav.innerHTML = '';
  visibleGroups.forEach(g => nav.appendChild(buildNavItem('group-' + g.id, g.title, '')));
  nav.hidden = false;
  if (toggle) toggle.hidden = false;
  __gnSetCollapsed(true);
}

/* ---------- 导航栏模式（card_style=nav）：顶部横向分组标签栏 ---------- */

/** 导航栏模式主渲染入口：填充 tabs + select + 触发卡片渲染 */
function renderGroupsNavbar() {
  const tabs = document.getElementById('navbarTabs');
  const sel = document.getElementById('navbarSelect');
  const main = document.getElementById('groupsWrap');
  if (!tabs) return;

  // 激活索引边界保护
  if (!state.groups.length) {
    tabs.hidden = true;
    if (sel) sel.hidden = true;
    if (main) main.innerHTML = '<div class="empty-tip glass">暂无内容，请先登录后台添加分组与卡片。</div>';
    renderGroupNav();
    return;
  }
  if (state.navActiveGroupIdx >= state.groups.length) state.navActiveGroupIdx = 0;

  tabs.hidden = false;
  tabs.innerHTML = '';
  state.groups.forEach((g, idx) => {
    const hidden = g.is_visible === 0 || (g.items && g.items.length === 0);
    const btn = document.createElement('button');
    btn.className = 'ntab-btn' + (hidden ? ' ntab-hidden' : '');
    btn.textContent = ' ' + g.title;
    btn.type = 'button';
    if (idx === state.navActiveGroupIdx) btn.classList.add('active');
    btn.onclick = () => {
      state.navActiveGroupIdx = idx;
      renderTabsOnly();
      renderNavbarCardsOnly();
    };
    tabs.appendChild(btn);
  });

  // 移动端下拉框（始终同步，CSS 控制显隐）
  if (sel) {
    sel.innerHTML = '';
    state.groups.forEach((g, idx) => {
      const hidden = g.is_visible === 0 || (g.items && g.items.length === 0);
      if (hidden) return; // 隐藏分组不出现在 select 里
      const opt = document.createElement('option');
      opt.value = idx;
      opt.textContent = g.title;
      if (idx === state.navActiveGroupIdx) opt.selected = true;
      sel.appendChild(opt);
    });
    sel.hidden = false;
    sel.onchange = () => {
      const idx = +sel.value;
      state.navActiveGroupIdx = idx;
      renderTabsOnly();
      renderNavbarCardsOnly();
    };
  }

  // 侧边栏目录隐藏
  renderGroupNav();

  renderNavbarCardsOnly();
}

/** 同步 navbarTabs 激活态 + navbarSelect 值（内部子函数，供 renderGroupsNavbar 切换时调用） */
function renderTabsOnly() {
  const tabs = document.getElementById('navbarTabs');
  if (!tabs) return;
  tabs.querySelectorAll('.ntab-btn').forEach((b, i) => {
    b.classList.toggle('active', i === state.navActiveGroupIdx);
  });
  const sel = document.getElementById('navbarSelect');
  if (sel) sel.value = String(state.navActiveGroupIdx);
}

/** 导航栏模式：只渲染选中分组的卡片到 groupsWrap */
function renderNavbarCardsOnly() {
  const wrap = document.getElementById('groupsWrap');
  if (!wrap) return;
  wrap.innerHTML = '';
  const g = state.groups[state.navActiveGroupIdx];
  if (!g) return;
  const styleApp = state.settings.card_style === 'app';
  renderGroupCard(wrap, g, styleApp, state.navActiveGroupIdx, true);
  state.groupIntroDone = true;
  state.cardsIntroDone = true;
}

/** 新闻视图：平台名称目录（色点取平台主题色） */
function renderNewsNav() {
  const nav = document.getElementById('groupNav');
  const toggle = document.getElementById('gnToggle');
  const srcs = newsState.sources || [];
  nav.setAttribute('aria-label', '平台目录');
  if (toggle) { toggle.setAttribute('aria-label', '展开平台目录'); toggle.title = '展开平台目录'; }
  if (!srcs.length) { nav.innerHTML = ''; nav.hidden = true; if (toggle) toggle.hidden = true; return; }

  nav.innerHTML = '';
  srcs.forEach(s => nav.appendChild(buildNavItem('news-card-' + s.id, s.name, s.color || '')));
  nav.hidden = false;
  if (toggle) toggle.hidden = false;
  __gnSetCollapsed(true);
}

function buildCard(item, styleApp) {
  const a = document.createElement('a');
  a.className = 'card' + (styleApp ? ' app-card' : '');
  a.href = '#';
  a.dataset.id = item.id; // 排序模式拖拽落位后按 data-id 收集顺序
  // 筛选/最近常用依赖的 data-* 属性
  a.dataset.title = item.title || '';
  a.dataset.url = item.url || item.lan_url || '';
  // 搜索索引 = 标题 + 描述 + URL（空格分隔，用于搜索）
  a.dataset.search = [
    (item.title || '').toLowerCase(),
    (item.description || '').toLowerCase(),
    (item.url || '').toLowerCase(),
    (item.lan_url || '').toLowerCase(),
  ].join(' ') + (item.icon_type === 'favicon' ? ' favicon' : '');
  a.draggable = false; // 默认禁止原生链接拖拽；排序模式下由 mousedown 动态开启

  // 图标
  const icon = document.createElement('span');
  icon.className = 'icon';
  a.appendChild(icon); // ★ 提前进 DOM，renderTextIcon 才能读到真实 clientWidth

  let iconDone = false;
  const useTextIcon = () => {
    if (iconDone) return;
    iconDone = true;
    icon.classList.remove('has-img');
    // 文字型：手动文字优先 → 空则 fallback title
    let raw = '';
    if (item.icon_type === 'text') {
      if (item.icon_value && !isImageUrlLike(item.icon_value)) {
        raw = item.icon_value;
      } else {
        raw = item.title || '?';
      }
    } else {
      raw = item.title || '?';
    }
    icon.style.background = item.icon_bg || stringColor(item.title);
    renderTextIcon(icon, smartIconText(raw), 21);
  };
  if (item.icon_type === 'image' && item.icon_value) {
    const img = document.createElement('img');
    img.src = assetUrl(item.icon_value);
    img.alt = '';
    img.onerror = useTextIcon;
    img.onload = () => { iconDone = true; icon.classList.add('has-img'); };
    icon.appendChild(img);
  } else if (item.icon_type === 'favicon' && item.url) {
    // 客户端多源回退，全部失败再使用文字图标
    const sources = faviconSources(item.url);
    if (sources.length) {
      const img = document.createElement('img');
      img.alt = '';
      img.referrerPolicy = 'no-referrer';
      let si = 0;
      img.onerror = () => {
        si += 1;
        if (si < sources.length) img.src = sources[si];
        else useTextIcon();
      };
      img.onload = () => { iconDone = true; icon.classList.add('has-img'); };
      img.src = sources[0];
      icon.appendChild(img);
    } else {
      useTextIcon();
    }
  } else {
    useTextIcon();
  }

  // 文本
  const info = document.createElement('div');
  info.className = 'info';
  const t = document.createElement('div');
  t.className = 't';
  t.textContent = item.title;
  info.appendChild(t);
  const d = document.createElement('div');
  d.className = 'd';
  d.textContent = item.description || item.url;
  info.appendChild(d);
  a.appendChild(info);

  // 内网地址标识
  if (state.lanMode && item.lan_url) {
    const badge = document.createElement('span');
    badge.className = 'lan-badge';
    badge.textContent = '内网';
    a.appendChild(badge);
  }

  a.onclick = e => {
    e.preventDefault();
    // 当前分组正在排序时，拖拽卡片，禁止跳转打开
    if (state.sortingGroupId !== null) return;
    openPage(item);
  };
  return a;
}

/** 根据字符串生成稳定颜色（无背景色时的文字图标底色） */
function stringColor(str) {
  let hash = 0;
  for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
  const hue = Math.abs(hash) % 360;
  return 'hsl(' + hue + ', 55%, 48%)';
}

/** 跳转地址安全校验：仅允许 http(s)，拦截 javascript: / data: 等协议（后端已校验，前端兜底） */
function safeUrl(url) {
  return /^https?:\/\//i.test(String(url || '').trim());
}

/** 打开卡片：1 当前页 / 2 新窗口 / 3 弹层；newTab 参数可强制覆盖打开方式（右键菜单用） */
function openPage(item, newTab) {
  const url = state.lanMode && item.lan_url ? item.lan_url : item.url;
  if (!url) {
    toast('该卡片未设置地址', 'error');
    return;
  }
  if (!safeUrl(url)) {
    toast('该卡片地址协议不合法（仅支持 http / https），已阻止打开', 'error');
    return;
  }
  // newTab 参数覆盖：右键菜单直接指定打开方式
  if (typeof newTab === 'boolean') {
    if (newTab) window.open(url, '_blank');
    else location.href = url;
    return;
  }
  if (item.open_method === 3) {
    document.getElementById('iframeTitle').textContent = item.title + ' · ' + url;
    document.getElementById('iframeNewTab').href = url;
    document.getElementById('iframeWin').src = url;
    document.getElementById('iframeModal').classList.add('show');
    return;
  }
  if (item.open_method === 1) {
    location.href = url;
    return;
  }
  window.open(url, '_blank');
}

/* ---------- 主题切换（浅色 / 深色 / 跟随系统 三态循环） ---------- */
const THEME_CYCLE = ['light', 'dark', 'system'];
const THEME_META = {
  light: { label: '🌞 浅色', tip: '当前：浅色模式，点击切换为深色' },
  dark: { label: '🌙 深色', tip: '当前：深色模式，点击切换为跟随系统' },
  system: { label: '🖥️ 跟随', tip: '当前：跟随系统深浅，点击切换为浅色' },
};

function updateThemeBtn() {
  const btn = document.getElementById('themeBtn');
  if (!btn) return;
  const m = THEME_META[state.theme] || THEME_META.dark;
  btn.textContent = m.label;
  btn.title = m.tip;
}

/** 右键卡片弹出操作菜单（仅 admin/editor 生效） */
function showCardContextMenu(x, y, item, groupId) {
  hideCardContextMenu();

  const menu = document.createElement('div');
  menu.className = 'card-context-menu glass-strong';
  menu.style.cssText = 'position:fixed;z-index:9999;left:' + x + 'px;top:' + y + 'px;';

  const items = [
    { label: '🔗 新标签页打开', action: () => openPage(item, true) },
    { label: '📑 当前页面打开', action: () => openPage(item, false) },
    { separator: true },
    { label: '✏️ 编辑卡片', action: () => openQuickAddCard(groupId, item) },
    { label: '🗑️ 删除卡片', danger: true, action: async () => {
      if (!await uiConfirm('确认删除「' + item.title + '」？', { danger: true })) return;
      try {
        await API.post(API_BASE + 'items.php?action=delete', { id: item.id });
        toast('删除成功', 'success');
        await reloadPublic();
      } catch (e) { toast(e.message, 'error'); }
    }},
  ];

  items.forEach(it => {
    if (it.separator) {
      const sep = document.createElement('div');
      sep.className = 'ccm-sep';
      menu.appendChild(sep);
      return;
    }
    const el = document.createElement('div');
    el.className = 'ccm-item' + (it.danger ? ' danger' : '');
    el.textContent = it.label;
    el.onclick = () => { it.action(); hideCardContextMenu(); };
    menu.appendChild(el);
  });

  document.body.appendChild(menu);

  // 边界检测：靠右/靠下右键自动偏移
  requestAnimationFrame(() => {
    const r = menu.getBoundingClientRect();
    if (r.right > window.innerWidth) menu.style.left = (x - r.width) + 'px';
    if (r.bottom > window.innerHeight) menu.style.top = (y - r.height) + 'px';
  });

  // 关闭触发（注意：不要绑 once contextmenu，会与委托处理器时序冲突）
  setTimeout(() => {
    document.addEventListener('click', hideCardContextMenu, { once: true });
    window.addEventListener('scroll', hideCardContextMenu, { once: true, passive: true });
    window.addEventListener('resize', hideCardContextMenu, { once: true });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') hideCardContextMenu(); }, { once: true });
  }, 0);
}

function hideCardContextMenu() {
  const m = document.querySelector('.card-context-menu');
  if (m) m.remove();
}

/** 外网/内网切换按钮：文字反映当前模式 */
function updateLanBtn() {
  const btn = document.getElementById('lanBtn');
  if (!btn) return;
  btn.textContent = state.lanMode ? '内网' : '外网';
  btn.title = state.lanMode ? '当前：内网地址（点击切外网）' : '当前：外网地址（点击切内网）';
}

function bindGlobal() {
  // 左侧悬浮目录条（分组/平台共用）把手与滚动高亮，仅绑定一次
  bindNavChrome();

  // 右键卡片操作菜单（仅 admin/editor 生效）
  document.addEventListener('contextmenu', e => {
    const card = e.target.closest('.card');
    if (!card) return;
    if (!state.user || (state.user.role !== 'admin' && state.user.role !== 'editor')) return;
    e.preventDefault();
    const itemId = +card.dataset.id;
    let foundItem = null, foundGroupId = null;
    for (const g of state.groups) {
      const it = g.items.find(i => i.id === itemId);
      if (it) { foundItem = it; foundGroupId = g.id; break; }
    }
    if (foundItem) showCardContextMenu(e.clientX, e.clientY, foundItem, foundGroupId);
  });

  document.getElementById('themeBtn').onclick = () => {
    const idx = THEME_CYCLE.indexOf(state.theme);
    const next = THEME_CYCLE[(idx + 1) % THEME_CYCLE.length] || 'light';
    state.theme = next;
    localStorage.setItem('sp_theme', next);
    setThemeMode(next);
    updateThemeBtn();
  };

  // 外网/内网切换（所有用户可用，无角色检查）
  const lanBtn = document.getElementById('lanBtn');
  if (lanBtn) {
    lanBtn.onclick = () => {
      state.lanMode = !state.lanMode;
      localStorage.setItem('sp_lan_mode', state.lanMode ? 'lan' : 'public');
      updateLanBtn();
      toast(state.lanMode ? '已切换到内网地址' : '已切换到外网地址', 'success');
      reloadPublic();
    };
  }

  // 关闭 iframe 弹层
  const modal = document.getElementById('iframeModal');
  // 右下角常驻「返回顶部」
  const backTop = document.getElementById('backTop');
  if (backTop) backTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
  document.getElementById('iframeClose').onclick = () => {
    modal.classList.remove('show');
    document.getElementById('iframeWin').src = 'about:blank';
  };
  modal.addEventListener('click', e => {
    if (e.target === modal) {
      modal.classList.remove('show');
      document.getElementById('iframeWin').src = 'about:blank';
    }
  });

  bindSortMode();

  // PWA：顶栏安装按钮（beforeinstallprompt 时显示）
  bindPWAInstall();

  // 键盘快捷键
  bindShortcuts();

  // 底部版本号：点击弹出当前版本更新日志
  const footerVer = document.getElementById('footerVer');
  if (footerVer) {
    footerVer.addEventListener('click', () => {
      const cm = document.getElementById('changelogModal');
      if (cm) cm.classList.add('show');
    });
  }

  // 排序中且有未保存更改时，关闭/刷新页面走浏览器原生拦截
  window.addEventListener('beforeunload', e => {
    if (state.sortingGroupId !== null && state.sortDirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
}

/* ---------- 卡片排序模式（仅管理员；每分组独立排序，保存后生效） ---------- */
let pendingSortLeave = null; // 守卫弹窗解决后要执行的动作（如切换到新闻视图）

function bindSortMode() {
  // 守卫弹窗：继续排序 / 放弃并离开 / 保存并离开
  const guard = document.getElementById('orderGuardModal');
  const closeGuard = () => { guard.classList.remove('show'); pendingSortLeave = null; };
  document.getElementById('guardStayBtn').onclick = closeGuard;
  guard.addEventListener('click', e => { if (e.target === guard) closeGuard(); });
  document.getElementById('guardDiscardBtn').onclick = () => {
    const after = pendingSortLeave;
    closeGuard();
    // 放弃：按 state 已保存数据重绘，还原脏顺序并退出排序
    renderGroups();
    state.sortingGroupId = null;
    state.sortDirty = false;
    if (after) after();
  };
  document.getElementById('guardSaveBtn').onclick = async () => {
    const after = pendingSortLeave;
    const gid = state.sortingGroupId;
    const b = document.getElementById('guardSaveBtn');
    b.disabled = true;
    try {
      await saveGroupSort(gid); // 成功后内部已退出排序
      closeGuard();
      if (after) after();
    } catch (e) {
      toast('保存失败：' + e.message, 'error');
    } finally {
      b.disabled = false;
    }
  };

  // 卡片拖拽：事件委托挂 groupsWrap（重绘不丢绑定）；仅当前排序分组生效
  const wrap = document.getElementById('groupsWrap');
  let dragCard = null;
  const clearDrag = () => {
    wrap.querySelectorAll('.card.dragging, .card.drag-over-before, .card.drag-over-after')
      .forEach(c => c.classList.remove('dragging', 'drag-over-before', 'drag-over-after'));
    if (dragCard) { dragCard.draggable = false; dragCard = null; }
  };
  wrap.addEventListener('mousedown', e => {
    if (state.sortingGroupId === null) return;
    const card = e.target.closest('.card');
    // 只允许拖拽当前排序分组内的卡片
    if (card && card.closest('.group').id === 'group-' + state.sortingGroupId) card.draggable = true;
  });
  wrap.addEventListener('dragstart', e => {
    if (state.sortingGroupId === null) return;
    const card = e.target.closest('.card');
    if (!card) { e.preventDefault(); return; }
    dragCard = card;
    requestAnimationFrame(() => card.classList.add('dragging'));
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', card.dataset.id || ''); } catch (_) { /* 兼容忽略 */ }
  });
  wrap.addEventListener('dragover', e => {
    if (state.sortingGroupId === null || !dragCard) return;
    const card = e.target.closest('.card');
    wrap.querySelectorAll('.card.drag-over-before, .card.drag-over-after')
      .forEach(c => c.classList.remove('drag-over-before', 'drag-over-after'));
    // 仅允许当前排序分组内排序
    if (!card || card === dragCard || card.parentElement !== dragCard.parentElement) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const ref = cardInsertRef(card.parentElement, e.clientX, e.clientY, dragCard);
    if (ref) {
      ref.classList.add('drag-over-before');
    } else {
      const cards = card.parentElement.querySelectorAll('.card');
      const last = cards[cards.length - 1];
      if (last && last !== dragCard) last.classList.add('drag-over-after');
    }
  });
  wrap.addEventListener('drop', e => {
    if (state.sortingGroupId === null || !dragCard) return;
    const container = dragCard.parentElement;
    if (e.target.closest('.cards') !== container) { clearDrag(); return; }
    e.preventDefault();
    const ref = cardInsertRef(container, e.clientX, e.clientY, dragCard);
    if (ref) container.insertBefore(dragCard, ref);
    else container.appendChild(dragCard);
    state.sortDirty = true;
    updateSortSaveBtn();
    clearDrag();
  });
  wrap.addEventListener('dragend', clearDrag);
}

/**
 * 网格落点计算：返回「应插到其前面」的卡片，按流式顺序（先行后列）比较指针与各卡中心；
 * 指针位于所有卡片之后时返回 null（= 追加到容器末尾）
 */
function cardInsertRef(container, x, y, ignore) {
  const cards = Array.prototype.filter.call(container.querySelectorAll('.card'), c => c !== ignore);
  for (const c of cards) {
    const r = c.getBoundingClientRect();
    const cx = r.left + r.width / 2;
    const cy = r.top + r.height / 2;
    if (y < cy - r.height * 0.25 || (Math.abs(y - cy) <= r.height * 0.25 && x < cx)) return c;
  }
  return null;
}

/** 开启某分组的排序；若另一分组正排序且有改动，先弹守卫 */
function startGroupSort(gid) {
  if (state.sortingGroupId === gid) return;
  if (state.sortingGroupId !== null && state.sortDirty) {
    openSortGuard(() => startGroupSort(gid));
    return;
  }
  state.sortingGroupId = gid;
  state.sortDirty = false;
  renderGroups();
}

/** 保存指定分组的卡片顺序 */
async function saveGroupSort(gid) {
  const sec = document.getElementById('group-' + gid);
  if (!sec) return;
  const ids = Array.prototype.map.call(sec.querySelectorAll('.cards .card'), c => Number(c.dataset.id))
    .filter(n => !isNaN(n));
  await API.post('/api/items.php?action=sort_batch', { group_id: gid, ids });
  // DOM 顺序为单一事实源，同步内存 state（取消时 renderGroups 可还原）
  const grp = state.groups.find(x => Number(x.id) === gid);
  if (grp && Array.isArray(grp.items)) {
    const byId = new Map(grp.items.map(i => [Number(i.id), i]));
    grp.items = ids.map(id => byId.get(id)).filter(Boolean);
  }
  state.sortingGroupId = null;
  state.sortDirty = false;
  renderGroups();
  toast('卡片排序已保存', 'success');
}

/** 取消指定分组的排序（还原已保存顺序） */
function cancelGroupSort(gid) {
  state.sortingGroupId = null;
  state.sortDirty = false;
  renderGroups(); // 按 state 已保存数据重绘，还原拖拽改动
  toast('已取消排序', 'info');
}

/** 更新当前排序分组「保存排序」按钮的可用状态 */
function updateSortSaveBtn() {
  const sec = document.getElementById('group-' + state.sortingGroupId);
  if (!sec) return;
  const btn = sec.querySelector('.group-sort .btn-save-order');
  if (btn) btn.disabled = !state.sortDirty;
}

/** 打开未保存守卫弹窗；after 为守卫解决（保存/放弃）后执行的动作（如切换到新闻视图） */
function openSortGuard(after) {
  pendingSortLeave = after;
  document.getElementById('orderGuardModal').classList.add('show');
}

/* ---------- 热点新闻视图（参考 NewsNow：多数据源卡片聚合） ---------- */
const newsState = {
  loaded: false,
  sources: [],   // 源定义
  cards: {},     // id -> 卡片 DOM
  list: {},      // id -> 最近一次数据
};

function initNewsSwitch() {
  const sw = document.getElementById('viewSwitch');

  // 导航栏模式：视图切换胶囊由分组标签栏替代，始终隐藏
  if (state.settings.card_style === 'nav') {
    if (sw) sw.hidden = true;
    // 仍绑定按钮事件（deep link #news 仍可能触发），但跳过后面的 sw.hidden = false
    return;
  }

  const mode = state.settings.home_view || 'both';
  if (mode === 'news') { switchView('news'); return; } // 只显示热点新闻：直接进入新闻视图，不显示切换按钮
  if (mode === 'nav') { switchView('nav'); return; }   // 只显示导航站：不显示切换按钮
  if (sw) sw.hidden = false;                            // 同时显示：主页搜索框下方视图切换胶囊
  document.getElementById('vsNav').onclick = () => switchView('nav');
  document.getElementById('vsNews').onclick = () => {
    // 排序中切到新闻视图：有未保存改动先弹守卫，无改动直接退出排序
    if (state.sortingGroupId !== null) {
      if (state.sortDirty) { openSortGuard(() => switchView('news')); return; }
      state.sortingGroupId = null;
      state.sortDirty = false;
    }
    switchView('news');
  };
}

function switchView(v) {
  // 导航栏模式：忽略视图切换（始终显示导航站，标签栏替代胶囊）
  if (state.settings.card_style === 'nav') return;

  const isNav = v === 'nav';
  document.getElementById('vsNav').classList.toggle('active', isNav);
  document.getElementById('vsNews').classList.toggle('active', !isNav);
  document.getElementById('groupsWrap').hidden = !isNav;
  document.getElementById('newsView').hidden = isNav;
  // 左侧悬浮目录：导航视图=分组目录，新闻视图=平台目录（内容随视图重建）
  if (isNav) {
    renderGroupNav();
  } else if (newsState.loaded) {
    renderNewsNav();
  } else {
    // 新闻数据未加载完成前先隐藏，加载成功后 renderNewsNav 自动显示
    const gn = document.getElementById('groupNav');
    const gt = document.getElementById('gnToggle');
    if (gn) gn.hidden = true;
    if (gt) gt.hidden = true;
  }
  if (!isNav) {
    if (!newsState.loaded) loadNews();
    window.scrollTo({ top: 0 });
  }
}

async function loadNews() {
  const view = document.getElementById('newsView');
  view.innerHTML = '<div class="empty-tip glass">📰 热点新闻加载中…</div>';
  try {
    const all = await API.get('/api/news.php?action=all');
    if (!all || all.enabled === false) {
      view.innerHTML = '<div class="empty-tip glass">热点新闻未开启，可在后台「站点设置 → 🧭 基础信息」的前端视图中开启。</div>';
      newsState.sources = [];
      renderNewsNav();
      return;
    }
    newsState.sources = all.sources || [];
    newsState.list = {};
    (all.list || []).forEach(l => { newsState.list[l.id] = l; });
    renderNewsCards();
    newsState.loaded = true;
    // 首屏缓存渲染后，逐源拉取最新数据（并发 3）
    newsFetchQueue(newsState.sources.map(s => s.id), false);
  } catch (e) {
    view.innerHTML = '<div class="empty-tip glass">新闻加载失败：' + esc(e.message) + '</div>';
    newsState.sources = [];
    renderNewsNav();
  }
}

function renderNewsCards() {
  const view = document.getElementById('newsView');
  const grid = document.createElement('div');
  grid.className = 'news-grid';
  newsState.cards = {};
  newsState.sources.forEach(def => {
    const card = buildNewsCard(def);
    newsState.cards[def.id] = card;
    grid.appendChild(card);
    const cached = newsState.list[def.id];
    if (cached && cached.items && cached.items.length) {
      fillNewsCard(card, cached.items, cached.updated, false);
    } else {
      fillNewsCard(card, [], 0, false, true);
    }
  });
  view.innerHTML = '';
  view.appendChild(grid);
  // 左侧平台目录
  renderNewsNav();
}

function buildNewsCard(def) {
  const sec = document.createElement('section');
  sec.className = 'news-card glass';
  sec.id = 'news-card-' + def.id;
  sec.style.setProperty('--nc', def.color || 'var(--accent)');

  const head = document.createElement('div');
  head.className = 'nc-head';
  if (def.logo) {
    const logo = document.createElement('img');
    logo.className = 'nc-logo';
    logo.src = def.logo;
    logo.alt = def.name;
    logo.loading = 'lazy';
    logo.onerror = () => { logo.style.display = 'none'; };
    head.appendChild(logo);
  }
  const dot = document.createElement('span');
  dot.className = 'nc-dot';
  const name = document.createElement('b');
  name.className = 'nc-name';
  name.textContent = def.name;
  const time = document.createElement('span');
  time.className = 'nc-time';
  const refresh = document.createElement('button');
  refresh.className = 'nc-refresh';
  refresh.type = 'button';
  refresh.title = '刷新本榜';
  refresh.textContent = '↻';
  refresh.onclick = () => newsFetchQueue([def.id], true);
  head.appendChild(dot);
  head.appendChild(name);
  head.appendChild(time);
  head.appendChild(refresh);

  const body = document.createElement('div');
  body.className = 'nc-body';
  const ol = document.createElement('ol');
  ol.className = 'nc-list ' + (def.type === 'hottest' ? 'nc-hot' : 'nc-time');
  body.appendChild(ol);

  sec.appendChild(head);
  sec.appendChild(body);
  sec._ol = ol;
  sec._time = time;
  sec._def = def;
  return sec;
}

/** 填充卡片内容；loading=true 显示占位，isError=true 显示失败重试 */
function fillNewsCard(card, items, updated, isError, loading) {
  const ol = card._ol;
  ol.innerHTML = '';
  if (loading) {
    const li = document.createElement('li');
    li.className = 'nc-empty';
    li.textContent = '加载中…';
    ol.appendChild(li);
  } else if (isError || !items || !items.length) {
    const li = document.createElement('li');
    li.className = 'nc-empty';
    li.textContent = isError ? '获取失败，点右上角 ↻ 重试' : '暂无数据';
    ol.appendChild(li);
  } else {
    items.slice(0, 20).forEach((it, i) => ol.appendChild(buildNewsItem(card._def, it, i)));
  }
  card._time.textContent = (!isError && !loading && updated) ? newsRelTime(updated * 1000) + '更新' : '';
}

function buildNewsItem(def, it, idx) {
  const li = document.createElement('li');
  const a = document.createElement('a');
  // 外部标题全部走 textContent 渲染（防 XSS）；链接协议白名单兜底
  const url = window.innerWidth < 768 && it.mobile_url ? it.mobile_url : it.url;
  if (safeUrl(url)) {
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
  }

  if (def.type === 'hottest') {
    const rank = document.createElement('span');
    rank.className = 'nc-rank' + (idx < 3 ? ' top' : '');
    rank.textContent = String(idx + 1);
    a.appendChild(rank);
  }
  const title = document.createElement('span');
  title.className = 'nc-title';
  title.textContent = it.title || '';
  a.appendChild(title);
  if (def.type === 'realtime') {
    const when = document.createElement('span');
    when.className = 'nc-when';
    when.textContent = it.pub ? newsRelTime(it.pub * 1000) : '';
    a.appendChild(when);
  } else if (it.info) {
    const info = document.createElement('span');
    info.className = 'nc-info';
    info.textContent = it.info;
    a.appendChild(info);
  }
  li.appendChild(a);
  return li;
}

/** 并发上限 3 的拉取队列；latest=true 强制回源（卡片刷新按钮） */
async function newsFetchQueue(ids, latest) {
  const queue = ids.slice();
  async function worker() {
    while (queue.length) {
      const id = queue.shift();
      const card = newsState.cards[id];
      if (!card) continue;
      card.classList.add('loading');
      try {
        const r = await API.get('/api/news.php?action=source&id=' + encodeURIComponent(id) + (latest ? '&latest=1' : ''));
        const hasOld = newsState.list[id] && (newsState.list[id].items || []).length;
        if (r && r.items && r.items.length) {
          fillNewsCard(card, r.items, r.updated, false);
          newsState.list[id] = r;
        } else if (!hasOld) {
          fillNewsCard(card, [], 0, true);
        }
      } catch (e) {
        const hasOld = newsState.list[id] && (newsState.list[id].items || []).length;
        if (!hasOld) fillNewsCard(card, [], 0, true);
      } finally {
        card.classList.remove('loading');
      }
    }
  }
  await Promise.all([worker(), worker(), worker()]);
}

/** 时间戳（毫秒）→ 中文相对时间 */
function newsRelTime(ms) {
  if (!ms) return '';
  const diff = Date.now() - ms;
  if (diff < 60000) return '刚刚';
  const min = Math.floor(diff / 60000);
  if (min < 60) return min + ' 分钟前';
  const h = Math.floor(min / 60);
  if (h < 24) return h + ' 小时前';
  const d = new Date(ms);
  return (d.getMonth() + 1) + '月' + d.getDate() + '日';
}

/** 打开更新日志弹窗（只显示当前版本） */
function openChangelogModal() {
  const body = document.getElementById('changelogBody');
  body.innerHTML = '';
  const logs = typeof APP_CHANGELOG !== 'undefined' ? APP_CHANGELOG : [];
  const curVer = typeof APP_VERSION !== 'undefined' ? APP_VERSION : '';
  const entry = logs.find(v => v.ver === curVer);
  if (!entry) {
    body.innerHTML = '<div class="sh-empty">暂无更新日志</div>';
  } else {
    const header = document.createElement('div');
    header.className = 'cl-ver-header';
    const tag = document.createElement('span');
    tag.className = 'cl-ver-tag';
    tag.textContent = entry.ver;
    const date = document.createElement('span');
    date.className = 'cl-ver-date';
    date.textContent = entry.date || '';
    header.appendChild(tag);
    header.appendChild(date);
    body.appendChild(header);
    const ul = document.createElement('ul');
    ul.className = 'cl-ver-items';
    (entry.items || []).forEach(item => {
      const li = document.createElement('li');
      li.textContent = item;
      ul.appendChild(li);
    });
    body.appendChild(ul);
  }
  document.getElementById('changelogModal').classList.add('show');
}

/** 关闭更新日志弹窗 */
function closeChangelogModal() {
  document.getElementById('changelogModal').classList.remove('show');
}

/* ---------- 访客访问锁屏（guest_required=true 时 boot 提前 return 调此函数） ---------- */
let __guestLockBound = false; // 防止 boot 重入时重复绑定 submit
function renderGuestLock() {
  const lock = document.getElementById('guestLock');
  if (!lock) return;

  // 显示全屏玻璃蒙版弹窗
  lock.hidden = false;
  // 隐藏 footer（锁屏状态下页脚不应可见）
  const footer = document.getElementById('footer');
  if (footer) footer.hidden = true;
  // 聚焦密码输入
  const pwd = document.getElementById('guestLockPwd');
  if (pwd) pwd.focus();

  // 只绑定一次 submit（boot 成功后会再次调用 renderGuestLock，需要幂等）
  if (__guestLockBound) return;
  __guestLockBound = true;

  const form = document.getElementById('guestLockForm');
  const errBox = document.getElementById('guestLockErr');
  const leftSpan = document.getElementById('guestLockLeft');
  const btn = document.getElementById('guestLockSubmit');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (errBox) errBox.hidden = true;
    if (btn) { btn.disabled = true; btn.textContent = '验证中…'; }
    try {
      await API.post('/api/auth.php?action=guest_login', { password: pwd.value });
      // 验证成功：隐藏锁屏并重新加载主页数据
      lock.hidden = true;
      __guestLockBound = false; // 允许下次需要时重新绑定
      boot();
    } catch (err) {
      // 显示错误 + 剩余次数（如果服务端返回的话）
      const msg = err.message || '密码错误';
      if (errBox) {
        errBox.hidden = false;
        errBox.textContent = msg;
        errBox.appendChild(document.createTextNode(' '));
        const remain = document.createElement('span');
        remain.textContent = '请重试';
        errBox.appendChild(remain);
      }
      pwd.select();
      if (btn) { btn.disabled = false; btn.textContent = '解锁访问'; }
    }
  });
}

/* ============ P2: 键盘快捷键 ============ */
function spIsTyping() {
  const t = document.activeElement && document.activeElement.tagName;
  if (!t) return false;
  if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') return true;
  if (document.activeElement.isContentEditable) return true;
  return false;
}
function spCloseTopModal() {
  // 依次尝试关：iframeModal > orderGuardModal > changelogModal > shortcutsModal
  for (const id of ['iframeModal', 'orderGuardModal', 'changelogModal', 'shortcutsModal']) {
    const m = document.getElementById(id);
    if (m && m.classList.contains('show')) { m.classList.remove('show'); return true; }
  }
  return false;
}
function bindShortcuts() {
  document.addEventListener('keydown', e => {
    // Esc：关弹窗 → 清筛选 → 失焦
    if (e.key === 'Escape') {
        if (spCloseTopModal()) { e.preventDefault(); return; }
        const focused = document.activeElement;
        if (focused && (focused.tagName === 'INPUT' || focused.tagName === 'TEXTAREA')) focused.blur();
        return;
      }
    // 输入框内放行 Ctrl/Cmd+K（焦点搜索）
    if (e.key === 'k' && (e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      document.getElementById('searchInput').focus();
      return;
    }
    if (spIsTyping()) return; // 其它所有单键在输入框内放行

    switch (e.key.toLowerCase()) {
      case '/':
        e.preventDefault();
        document.getElementById('searchInput').focus();
        break;
      case 't': updateThemeBtn && updateThemeBtn(); document.getElementById('themeBtn').click(); break;
      case 'n': switchView('news'); break;
      case 'b':
      case 'h': switchView('nav'); break;
      case 'g':
        document.getElementById('groupNav') && document.getElementById('gnToggle').click();
        break;
      case '?':
        e.preventDefault();
        document.getElementById('shortcutsModal').classList.add('show');
        break;
    }
  });
  // 快捷键帮助弹窗
  document.getElementById('shortcutsClose')?.addEventListener('click', () => document.getElementById('shortcutsModal').classList.remove('show'));
  document.getElementById('shortcutsClose2')?.addEventListener('click', () => document.getElementById('shortcutsModal').classList.remove('show'));
  document.getElementById('shortcutsModal')?.addEventListener('click', e => {
    if (e.target.id === 'shortcutsModal') e.target.classList.remove('show');
  });
  // 顶栏快捷键按钮（nav 源码风格）
  const scBtn = document.getElementById('shortcutsBtn');
  if (scBtn) scBtn.onclick = () => document.getElementById('shortcutsModal').classList.add('show');
  // 更新日志弹窗关闭
  document.getElementById('changelogClose')?.addEventListener('click', closeChangelogModal);
  document.getElementById('changelogModal')?.addEventListener('click', e => {
    if (e.target.id === 'changelogModal') closeChangelogModal();
  });
}

/* ============ 顶栏 PWA 安装按钮（Chrome/Edge 触发 beforeinstallprompt 时显示） ============ */
function bindPWAInstall() {
  const btn = document.getElementById('pwaInstallBtn');
  if (!btn) return;
  let deferred = null;
  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferred = e;
    btn.hidden = false;
  });
  btn.addEventListener('click', async () => {
    if (!deferred) return;
    deferred.prompt();
    await deferred.userChoice;
    deferred = null;
    btn.hidden = true;
  });
  window.addEventListener('appinstalled', () => { deferred = null; btn.hidden = true; });
}

/* ============ P2: 每日壁纸（Bing） ============ */
/**
 * 每日壁纸：当日 sessionStorage 命中则同步应用，否则先用静态壁纸兜底再异步拉取。
 * 源地址由后端 wallpaper.php 代理抓取（Bing 等），前端只拿本地缓存 URL。
 */
async function applyDailyWallpaper(source, apply, fallback) {
  const KEY = 'sp_daily_wallpaper';
  const today = new Date().toISOString().slice(0, 10);
  let cached = null;
  try { cached = JSON.parse(sessionStorage.getItem(KEY)) || null; } catch (e) { cached = null; }
  if (cached && cached.date === today && cached.url) { apply(cached.url); return; }
  // 先用静态壁纸兜底，避免短暂空白
  apply(fallback || '');
  try {
    const r = await API.get('/api/wallpaper.php?action=daily&source=' + encodeURIComponent(source));
    if (r && r.url) {
      apply(r.url);
      try { sessionStorage.setItem(KEY, JSON.stringify({ date: today, url: r.url })); } catch (e) {}
    }
  } catch (e) { /* 拉取失败保留静态壁纸 */ }
}

/* ================= 主页专用：主题化确认弹窗 + reloadPublic + quick-add 入口 ================= */

/** 主页动态注入 confirmModal（admin.html 已有，但主页 index.html 没写） */
function ensureConfirmModal() {
  if (document.getElementById('confirmModal')) return;
  const m = document.createElement('div');
  m.className = 'modal';
  m.id = 'confirmModal';
  m.innerHTML = `
    <div class="modal-box glass-strong" style="max-width:430px">
      <div class="modal-head">
        <h3 id="confirmTitle">确认操作</h3>
        <button class="btn btn-sm" type="button" id="confirmXBtn">✕</button>
      </div>
      <p id="confirmText" style="margin:0;color:var(--text-muted);font-size:13.5px;line-height:1.7;white-space:pre-line"></p>
      <div class="modal-foot">
        <button class="btn" type="button" id="confirmCancelBtn">取消</button>
        <button class="btn btn-primary" type="button" id="confirmOkBtn">确定</button>
      </div>
    </div>`;
  document.body.appendChild(m);
}

/** 主页版 uiConfirm — 与 admin.js 实现一致 */
function uiConfirm(message, opts) {
  ensureConfirmModal();
  const m = document.getElementById('confirmModal');
  if (!m) return Promise.resolve(window.confirm(message));
  const o = opts || {};
  document.getElementById('confirmTitle').textContent = o.title || '确认操作';
  document.getElementById('confirmText').textContent = message;
  const ok = document.getElementById('confirmOkBtn');
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
    const cancel = document.getElementById('confirmCancelBtn');
    const x = document.getElementById('confirmXBtn');
    ok.onclick = () => done(true);
    cancel.onclick = () => done(false);
    x.onclick = () => done(false);
    m.addEventListener('click', onBg);
    document.addEventListener('keydown', onKey);
  });
}

/** 重新拉 public.php 并全量重绘 — 新增/删除卡片后调用 */
async function reloadPublic() {
  try {
    const data = await API.get(API_BASE + 'public.php?_t=' + Date.now());
    state.settings = data.settings || {};
    state.groups = data.groups || [];
    const container = document.getElementById('groupsWrap');
    if (container) container.innerHTML = '';
    state.groupIntroDone = true;
    state.cardsIntroDone = true;
    renderGroups();
  } catch (e) { toast('刷新失败：' + e.message, 'error'); }
}

/** 主页快速添加/编辑卡片弹窗入口 */
function openQuickAddCard(groupId, item) {
  ensureQuickAddModal();
  const m = document.getElementById('quickAddModal');
  const isEdit = !!item;
  m.dataset.groupId = String(groupId);

  // 先 reset——必须在填分组选项之前，否则 form.reset() 会清掉动态设置的 selected
  document.getElementById('qaForm').reset();

  const groupSel = document.getElementById('qa_group');
  groupSel.innerHTML = '';
  const groups = state.groups || [];
  let matched = false;
  groups.forEach(g => {
    const opt = document.createElement('option');
    opt.value = String(g.id);
    opt.textContent = g.title;
    const matchGid = isEdit ? item.group_id : groupId;
    if (String(g.id) === String(matchGid)) { opt.selected = true; matched = true; }
    groupSel.appendChild(opt);
  });
  // 兜底：没匹配到当前分组时默认选第一个
  if (!matched && groups.length) groupSel.value = String(groups[0].id);

  // 标题 + 按钮文字
  const h3 = m.querySelector('.modal-head h3');
  const saveBtn = document.getElementById('qaSaveBtn');
  h3.textContent = isEdit
    ? ('编辑卡片「' + item.title + '」')
    : (('向「' + (state.groups.find(g => g.id === groupId)?.title || '') + '」添加卡片') || '添加卡片');
  saveBtn.textContent = isEdit ? '保存' : '添加卡片';

  document.getElementById('qa_group_id').value = isEdit ? item.id : 0;
  document.getElementById('qa_title').value = isEdit ? (item.title || '') : '';
  document.getElementById('qa_url').value = isEdit ? (item.url || '') : '';
  document.getElementById('qa_lan').value = isEdit ? (item.lan_url || '') : '';
  document.getElementById('qa_desc').value = isEdit ? (item.description || '') : '';
  document.getElementById('qa_icon').value = '';
  document.getElementById('qa_iconText').value = '';
  document.getElementById('qa_open').value = isEdit ? String(item.open_method || 2) : '2';

  // 图标类型回填
  const itype = isEdit ? (item.icon_type || 'favicon') : 'favicon';
  document.querySelector('#qa_itype input[value="' + itype + '"]').checked = true;

  // 图标值回填
  document.getElementById('qa_icon').value = isEdit && item.icon_type === 'image' ? (item.icon_value || '') : '';

  // 文字图标回填
  if (isEdit && item.icon_type === 'text' && item.icon_value) {
    document.getElementById('qa_iconText').value = item.icon_value;
    window.__qaIconTextManual = true;
  } else {
    document.getElementById('qa_iconText').value = '';
    window.__qaIconTextManual = false;
    if (isEdit && item.title) document.getElementById('qa_iconText').value = smartIconText(item.title);
  }

  // 背景色回填
  if (isEdit && item.icon_bg) {
    document.getElementById('qa_bg').value = item.icon_bg;
    syncQAColorPanel('init');
  } else {
    document.getElementById('qa_bg').value = buildBgCss('#0969da', 100);
    document.getElementById('qa_color').value = '#0969da';
    document.getElementById('qa_alpha').value = '100';
    document.getElementById('qa_alphaLabel').textContent = '100%';
  }

  window.__qaMetaIcon = '';
  onQAIconTypeChange();
  m.classList.add('show');

  // 新增模式：有 URL 时自动抓 meta
  if (!isEdit) {
    setTimeout(() => {
      const urlInput = document.getElementById('qa_url');
      if (urlInput.value.trim()) fetchQAMeta();
    }, 100);
  }
}

function ensureQuickAddModal() {
  if (document.getElementById('quickAddModal')) return;
  const m = document.createElement('div');
  m.className = 'modal';
  m.id = 'quickAddModal';
  m.innerHTML = `
    <div class="modal-box glass-strong quick-add">
      <div class="modal-head">
        <h3>添加卡片</h3>
        <button class="btn btn-sm" type="button" id="qaCloseX">✕</button>
      </div>
      <form id="qaForm" autocomplete="off">
        <input type="hidden" id="qa_group_id">
        <div class="form-row">
          <div class="form-item">
            <label>所属分组</label>
            <select class="select" id="qa_group"></select>
          </div>
          <div class="form-item">
            <label>打开方式</label>
            <select class="select" id="qa_open">
              <option value="2">新窗口打开</option>
              <option value="1">当前页打开</option>
              <option value="3">弹层内嵌打开</option>
            </select>
          </div>
        </div>
        <div class="form-item">
          <label>标题</label>
          <input class="input" id="qa_title" type="text" maxlength="50" required>
        </div>
        <div class="form-item">
          <label>地址（鼠标点击此处可自动获取标题/描述/图标）
            <button class="btn btn-sm" type="button" id="qa_fetchMeta" style="float:right">自动获取信息</button>
          </label>
          <input class="input" id="qa_url" type="text" placeholder="https://…">
        </div>
        <div class="form-item">
          <label>内网地址（可选，用于主页「内网模式」）</label>
          <input class="input" id="qa_lan" type="text" placeholder="http://192.168.x.x:port">
        </div>
        <div class="form-item">
          <label>描述（可选）</label>
          <input class="input" id="qa_desc" type="text" maxlength="1000">
        </div>
        <div class="form-item">
          <label>图标类型</label>
          <div class="radio-group" id="qa_itype">
            <label><input type="radio" name="qaitype" value="favicon" checked> 自动获取站点图标</label>
            <label><input type="radio" name="qaitype" value="image"> 图片地址 / 上传</label>
            <label><input type="radio" name="qaitype" value="text"> 文字图标</label>
          </div>
        </div>
        <div class="form-item">
          <label>图标</label>
          <div class="upload-row">
            <span class="icon-preview" id="qa_preview"></span>
            <input class="input" id="qa_icon" type="text" placeholder="图标图片地址（选择「图片」类型时使用）">
            <button class="btn" type="button" id="qa_uploadIcon">上传图标</button>
            <button class="btn" type="button" id="qa_useFavicon" hidden>获取站点图标</button>
          </div>
          <div class="form-item" style="margin-top:10px" id="qa_textWrap">
            <label>手动文字（留空自动取卡片标题）</label>
            <input class="input" id="qa_iconText" type="text" maxlength="12" placeholder="支持中文 4 字 / 英文 12 字母">
          </div>
          <div class="form-item" style="margin-top:10px" id="qa_bgWrap">
            <label>图标背景色</label>
            <div class="upload-row" style="gap:8px;flex-wrap:nowrap">
              <input type="color" id="qa_color" value="#0969da" style="width:44px;height:34px;padding:2px;border-radius:6px;cursor:pointer">
              <span style="font-size:12px;color:var(--text-muted);white-space:nowrap">透明度</span>
              <input type="range" id="qa_alpha" min="0" max="100" value="100" style="flex:1">
              <span id="qa_alphaLabel" style="font-size:12px;color:var(--text-muted);min-width:32px;text-align:right">100%</span>
              <input type="hidden" id="qa_bg" value="">
            </div>
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn" type="button" id="qaCancel">取消</button>
          <button class="btn btn-primary" type="submit" id="qaSaveBtn">添加卡片</button>
        </div>
      </form>
    </div>`;
  document.body.appendChild(m);

  document.getElementById('qaCloseX').onclick = () => m.classList.remove('show');
  document.getElementById('qaCancel').onclick = () => m.classList.remove('show');
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });

  document.querySelectorAll('#qa_itype input').forEach(r => {
    r.addEventListener('change', onQAIconTypeChange);
  });

  document.getElementById('qa_title').addEventListener('input', () => {
    if (!window.__qaIconTextManual) {
      document.getElementById('qa_iconText').value = smartIconText(document.getElementById('qa_title').value);
    }
    updateQAIconPreview();
  });
  document.getElementById('qa_iconText').addEventListener('input', () => {
    window.__qaIconTextManual = true;
    updateQAIconPreview();
  });
  document.getElementById('qa_icon').addEventListener('input', updateQAIconPreview);
  document.getElementById('qa_url').addEventListener('input', updateQAIconPreview);

  document.getElementById('qa_color').addEventListener('input', syncQAColorPanel);
  document.getElementById('qa_alpha').addEventListener('input', syncQAColorPanel);

  document.getElementById('qa_fetchMeta').addEventListener('click', fetchQAMeta);

  document.getElementById('qaForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('qaSaveBtn');
    btn.disabled = true;
    try {
      const itype = document.querySelector('#qa_itype input:checked').value;
      let iconValue = '';
      switch (itype) {
        case 'image':   iconValue = document.getElementById('qa_icon').value.trim(); break;
        case 'favicon': iconValue = ''; break;
        case 'text':
          iconValue = document.getElementById('qa_iconText').value.trim();
          if (isImageUrlLike(iconValue)) iconValue = '';
          break;
      }
      syncQAColorPanel();
      const id = Number(document.getElementById('qa_group_id').value) || 0;
      await API.post(API_BASE + 'items.php?action=edit', {
        id,
        group_id: Number(document.getElementById('qa_group').value) || 0,
        open_method: Number(document.getElementById('qa_open').value),
        title: document.getElementById('qa_title').value.trim(),
        url: document.getElementById('qa_url').value.trim(),
        lan_url: document.getElementById('qa_lan').value.trim(),
        description: document.getElementById('qa_desc').value.trim(),
        icon_type: itype,
        icon_value: iconValue,
        icon_bg: document.getElementById('qa_bg').value.trim(),
      });
      m.classList.remove('show');
      toast(id ? '已保存' : '已添加', 'success');
      await reloadPublic();
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      btn.disabled = false;
    }
  });

  // 上传图标按钮
  const uploadBtn = document.getElementById('qa_uploadIcon');
  const fileInput = document.createElement('input');
  fileInput.type = 'file';
  fileInput.accept = 'image/*';
  fileInput.style.display = 'none';
  uploadBtn.parentNode.appendChild(fileInput);
  uploadBtn.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', async () => {
    const file = fileInput.files[0];
    if (!file) return;
    const form = new FormData();
    form.append('file', file);
    form.append('type', 'icon');
    uploadBtn.disabled = true;
    try {
      const res = await API.postForm(API_BASE + 'upload.php', form);
      document.getElementById('qa_icon').value = res.url;
      // 自动切到图片类型
      const imgRadio = document.querySelector('#qa_itype input[value="image"]');
      if (imgRadio && !imgRadio.checked) {
        imgRadio.checked = true;
        onQAIconTypeChange();
      }
      updateQAIconPreview();
      toast('图标上传成功', 'success');
    } catch (e) {
      toast('上传失败：' + e.message, 'error');
    } finally {
      uploadBtn.disabled = false;
      fileInput.value = '';
    }
  });

  // 获取站点图标按钮（仅 image 类型显示）
  document.getElementById('qa_useFavicon').addEventListener('click', fetchQAFavicon);
}

function onQAIconTypeChange() {
  const type = document.querySelector('#qa_itype input:checked').value;
  document.getElementById('qa_textWrap').style.display = type === 'text' ? '' : 'none';
  document.getElementById('qa_bgWrap').style.display = (type === 'text' || type === 'image') ? '' : 'none';
  document.getElementById('qa_useFavicon').hidden = type !== 'image';
  updateQAIconPreview();
}

function updateQAIconPreview() {
  const type = document.querySelector('#qa_itype input:checked').value;
  const icon = document.getElementById('qa_icon').value.trim();
  const title = document.getElementById('qa_title').value.trim();
  const url = document.getElementById('qa_url').value.trim();
  const bg = document.getElementById('qa_bg').value.trim();
  const preview = document.getElementById('qa_preview');

  if (type === 'image') {
    preview.style.background = bg || '#0969da';
    preview.innerHTML = icon ? '<img src="' + esc(assetUrl(icon)) + '" alt="" onerror="this.parentElement.innerHTML=\'<span>🖼</span>\'">' : '<span>🖼</span>';
  } else if (type === 'favicon') {
    preview.style.background = '';
    const metaIcon = (window.__qaMetaIcon || '').trim();
    const urls = metaIcon ? [metaIcon] : faviconSources(url);
    if (urls.length) {
      const key = '__qaFav_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6);
      window[key] = urls;
      preview.innerHTML = '<img src="' + esc(urls[0]) + '" alt="" referrerpolicy="no-referrer" onerror="window.__qaFavFallback(this,\'' + key + '\',1)">';
    } else {
      preview.innerHTML = '<span>?</span>';
    }
  } else {
    preview.style.background = bg || '#0969da';
    const raw = (document.getElementById('qa_iconText').value.trim())
                ? document.getElementById('qa_iconText').value : (title || '?');
    renderTextIcon(preview, smartIconText(raw), 18);
  }
}

/** 解析存储的 icon_bg 值，返回 {hex, alpha}
 *  支持两种格式：hex（#0969da）和 rgba(r,g,b,a)
 */
function parseBgColor(bg) {
  if (!bg) return null;
  bg = bg.trim();
  // rgba(r,g,b,a) 格式
  const m = bg.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+))?\s*\)$/);
  if (m) {
    const r = parseInt(m[1]), g = parseInt(m[2]), b = parseInt(m[3]);
    const a = m[4] !== undefined ? parseFloat(m[4]) : 1;
    const hex = '#' + [r, g, b].map(x => x.toString(16).padStart(2, '0')).join('');
    return { hex, alpha: Math.round(a * 100) };
  }
  // hex 格式
  if (bg.startsWith('#')) {
    let h = bg.slice(1);
    if (h.length === 3) h = h.split('').map(c => c + c).join('');
    if (h.length === 6) return { hex: '#' + h, alpha: 100 };
  }
  return null;
}

/** 图标背景色面板：color / alpha / text 三向联动 */
function syncQAColorPanel(source) {
  const bgColor = document.getElementById('qa_color');
  const bgAlpha = document.getElementById('qa_alpha');
  const bgAlphaLbl = document.getElementById('qa_alphaLabel');
  const bgText = document.getElementById('qa_bg');

  if (source === 'init') {
    const parsed = parseBgColor(bgText.value);
    if (parsed) {
      bgColor.value = parsed.hex;
      bgAlpha.value = parsed.alpha;
      bgAlphaLbl.textContent = parsed.alpha + '%';
    }
    updateQAIconPreview();
    return;
  }
  bgAlphaLbl.textContent = bgAlpha.value + '%';
  bgText.value = buildBgCss(bgColor.value, +bgAlpha.value);
  updateQAIconPreview();
}

async function fetchQAMeta() {
  const url = document.getElementById('qa_url').value.trim();
  if (!url || !/^https?:\/\//i.test(url)) { toast('请先填写 http(s):// 开头的地址', 'error'); return; }
  try {
    const btn = document.getElementById('qa_fetchMeta');
    btn.disabled = true; btn.textContent = '获取中…';
    const res = await API.get(API_BASE + 'items.php?action=fetch_meta&url=' + encodeURIComponent(url));

    const got = [];
    const emptyTitle = !document.getElementById('qa_title').value.trim();
    const emptyDesc  = !document.getElementById('qa_desc').value.trim();
    const curType = document.querySelector('#qa_itype input:checked').value;

    if (res.title && emptyTitle) { document.getElementById('qa_title').value = res.title; got.push('标题'); }
    if (res.description && emptyDesc) { document.getElementById('qa_desc').value = res.description; got.push('描述'); }

    // 图标处理：与 admin.js 对齐的三态分支
    if (curType === 'favicon') {
      if (res.icon) {
        if (res.fallback_icon) {
          // 公共图标源 → 切 image + 填 URL
          document.querySelector('#qa_itype input[value="image"]').checked = true;
          document.getElementById('qa_icon').value = res.icon;
          got.push('图标(浏览器加载)');
          onQAIconTypeChange();
        } else {
          got.push('图标');
        }
      } else {
        // favicon 拿不到 → 自动降级为文字图标
        document.querySelector('#qa_itype input[value="text"]').checked = true;
        document.getElementById('qa_iconText').value = smartIconText(res.title || url);
        onQAIconTypeChange();
        got.push('图标(文字降级)');
      }
    } else {
      updateQAIconPreview();
    }

    toast(got.length ? ('已获取：' + got.join(' / ')) : '没获取到新信息', got.length ? 'success' : 'info');
  } catch (err) { toast('自动获取失败：' + err.message, 'error'); }
  finally {
    const btn = document.getElementById('qa_fetchMeta');
    btn.disabled = false; btn.textContent = '自动获取信息';
  }
}

/** 获取站点图标（items.php?action=favicon）—— 写入 qa_icon 并自动切到 image 类型 */
async function fetchQAFavicon() {
  const url = document.getElementById('qa_url').value.trim();
  if (!url) { toast('请先填写卡片地址', 'error'); return; }
  const btn = document.getElementById('qa_useFavicon');
  btn.disabled = true; btn.textContent = '获取中…';
  try {
    const res = await API.get(API_BASE + 'items.php?action=favicon&url=' + encodeURIComponent(url));
    document.querySelector('#qa_itype input[value="image"]').checked = true;
    document.getElementById('qa_icon').value = res.url;
    onQAIconTypeChange();
    updateQAIconPreview();
    if (res.fallback) {
      toast('服务器未能缓存图标' + (res.diag ? '：' + res.diag : '') + '，已改用公共图标源', 'info');
    } else {
      toast(res.cached ? '已获取（本地缓存）' : '获取成功', 'success');
    }
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false; btn.textContent = '获取站点图标';
  }
}

window.__qaFavFallback = function(imgEl, key, nextIdx) {
  const arr = window[key];
  if (!arr) { imgEl.parentElement.innerHTML = '<span>🌐</span>'; return; }
  if (nextIdx < arr.length) {
    imgEl.src = arr[nextIdx];
  } else {
    imgEl.parentElement.innerHTML = '<span>🌐</span>';
    try { delete window[key]; } catch(e) { window[key] = null; }
  }
};

boot();
