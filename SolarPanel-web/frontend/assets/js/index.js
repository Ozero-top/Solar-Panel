/**
 * 主页逻辑：全部展示内容由后端 public.php 提供
 */
const state = {
  settings: {},
  groups: [],
  user: null,
  lanMode: false, // 由后台「默认地址模式」设置决定
  theme: 'dark',
  sortingGroupId: null, // 当前正在排序的分组 id（仅管理员，null = 未排序）
  sortDirty: false, // 当前排序分组有未保存的拖拽改动
  groupIntroDone: false, // 首屏分组入场动画只播一次（后续重绘不闪动画）
};

const API_BASE = '../backend/api/';

async function boot() {
  let data;
  try {
    data = await API.get(API_BASE + 'public.php');
  } catch (e) {
    document.getElementById('groupsWrap').innerHTML =
      '<div class="empty-tip glass">数据加载失败：' + esc(e.message) + '<br>请确认已完成安装：访问 <a href="../backend/api/install.php">/backend/api/install.php</a></div>';
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
  document.body.classList.toggle('has-wallpaper', !!s.wallpaper);
  if (s.wallpaper) {
    bg.style.backgroundImage = 'url("' + assetUrl(s.wallpaper).replace(/"/g, '%22') + '")';
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

  // 地址模式：后台设置（外网 public / 内网 lan）
  state.lanMode = s.default_lan_mode === 'lan';

  // 管理入口：已登录直达后台，否则去登录页
  const link = document.getElementById('adminLink');
  if (state.user) {
    link.href = 'frontend/admin.html';
    link.title = '管理后台（' + (state.user.name || state.user.username) + '）';
  }
}

/* ---------- 天气 ---------- */
async function renderWeather(city) {
  const el = document.getElementById('weatherArea');
  try {
    const w = await API.get('../backend/api/weather.php?action=current' + (city ? '&city=' + encodeURIComponent(city) : ''));
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
  document.getElementById('searchBox').hidden = false;

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
function renderGroups() {
  const wrap = document.getElementById('groupsWrap');
  const styleApp = state.settings.card_style === 'app';
  wrap.innerHTML = '';

  if (!state.groups.length) {
    wrap.innerHTML = '<div class="empty-tip glass">暂无内容，请先登录后台添加分组与卡片。</div>';
    document.getElementById('groupNav').hidden = true;
    const gnToggleBtn = document.getElementById('gnToggle');
    if (gnToggleBtn) gnToggleBtn.hidden = true;
    return;
  }

  state.groups.forEach((g, i) => {
    const section = document.createElement('section');
    section.className = 'group glass' + (state.sortingGroupId === g.id ? ' sorting' : '');
    section.id = 'group-' + g.id;
    // 首屏：分组由上而下逐个浮现（仅首次加载播放，重绘不再闪动画）
    if (!state.groupIntroDone) {
      section.classList.add('group-in');
      section.style.animationDelay = Math.min(i * 120, 960) + 'ms';
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
    // 管理员：分组名后显示排序操作（未排序显示「排序」，排序中显示「保存排序 / 取消排序」）
    if (state.user && state.user.role === 'admin') {
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
    }
    section.appendChild(head);

    const cards = document.createElement('div');
    cards.className = 'cards' + (styleApp ? ' style-app' : '');
    (g.items || []).forEach(item => cards.appendChild(buildCard(item, styleApp)));
    section.appendChild(cards);

    if (!(g.items || []).length) {
      const empty = document.createElement('div');
      empty.className = 'empty-tip';
      empty.textContent = '该分组暂无卡片';
      section.appendChild(empty);
    }

    wrap.appendChild(section);
  });

  // 首屏入场动画已排布完成，后续重绘（排序 / 切换视图等）不再播放
  state.groupIntroDone = true;

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
  a.draggable = false; // 默认禁止原生链接拖拽；排序模式下由 mousedown 动态开启

  // 图标
  const icon = document.createElement('span');
  icon.className = 'icon';
  if (item.icon_bg) icon.style.background = item.icon_bg;
  let iconDone = false;
  const useTextIcon = () => {
    if (iconDone) return;
    iconDone = true;
    icon.classList.remove('has-img');
    icon.textContent = (item.title || '?').trim().charAt(0).toUpperCase();
    if (!item.icon_bg) icon.style.background = stringColor(item.title);
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
  a.appendChild(icon);

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

/** 打开卡片：1 当前页 / 2 新窗口 / 3 弹层 */
function openPage(item) {
  const url = state.lanMode && item.lan_url ? item.lan_url : item.url;
  if (!url) {
    toast('该卡片未设置地址', 'error');
    return;
  }
  if (!safeUrl(url)) {
    toast('该卡片地址协议不合法（仅支持 http / https），已阻止打开', 'error');
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

function bindGlobal() {
  // 左侧悬浮目录条（分组/平台共用）把手与滚动高亮，仅绑定一次
  bindNavChrome();

  document.getElementById('themeBtn').onclick = () => {
    const idx = THEME_CYCLE.indexOf(state.theme);
    const next = THEME_CYCLE[(idx + 1) % THEME_CYCLE.length] || 'light';
    state.theme = next;
    localStorage.setItem('sp_theme', next);
    setThemeMode(next);
    updateThemeBtn();
  };

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

  // 更新日志弹窗关闭
  const changelogModal = document.getElementById('changelogModal');
  document.getElementById('changelogClose').onclick = closeChangelogModal;
  changelogModal.addEventListener('click', e => {
    if (e.target === changelogModal) closeChangelogModal();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && changelogModal.classList.contains('show')) closeChangelogModal();
  });

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
  await API.post('../backend/api/items.php?action=sort_batch', { group_id: gid, ids });
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
  const mode = state.settings.home_view || 'both';
  const sw = document.getElementById('viewSwitch');
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
    const all = await API.get('../backend/api/news.php?action=all');
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
        const r = await API.get('../backend/api/news.php?action=source&id=' + encodeURIComponent(id) + (latest ? '&latest=1' : ''));
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

boot();
