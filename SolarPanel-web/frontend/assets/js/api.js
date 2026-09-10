/**
 * 前端公共：API 请求封装 + 工具函数
 */

/* ================= 应用版本与更新日志（每次更新只需改这里） ================= */
const APP_VERSION = 'v2.0.01';
const APP_CHANGELOG = [
  { ver: 'v2.0.01', date: '2026-09-10', items: ['解决部分兼容问题'] },
  { ver: 'v2.0.00', date: '2026-09-10', items: ['🎉 v2.0 正式版发布：SolarPanel —— 柔软而精致的个人导航面板，数据完全自持。'] },
];

const API = {
  /**
   * 从 cookie 读取 CSRF 令牌（由后端 auth_session_start 以非 HttpOnly cookie 下发）
   */
  _csrfToken() {
    const m = document.cookie.match(/(?:^|;\s*)csrf_token=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  },
  /**
   * @param {string} path 接口地址（相对 frontend 目录）
   * @param {{method?:string, data?:object, form?:FormData}} opts
   */
  async request(path, opts = {}) {
    const init = { method: opts.method || 'GET', credentials: 'same-origin', headers: {} };
    // 写请求携带 CSRF 令牌（同步令牌模式，后端按 session 中的值校验）
    if (init.method !== 'GET') {
      const tok = API._csrfToken();
      if (tok) init.headers['X-CSRF-Token'] = tok;
    }
    if (opts.form) {
      init.body = opts.form;
    } else if (opts.data !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.data);
    }
    let res;
    try {
      res = await fetch(path, init);
    } catch (e) {
      throw new Error('网络请求失败，请检查服务是否可用');
    }
    let j;
    try {
      j = await res.json();
    } catch (e) {
      throw new Error('接口返回异常（HTTP ' + res.status + '）');
    }
    if (j.code !== 0) {
      const err = new Error(j.msg || '请求失败');
      err.code = j.code;
      err.data = j.data; // 业务失败仍可能携带明细数据（如升级部分失败的文件列表）
      throw err;
    }
    return j.data;
  },
  get(path) {
    return API.request(path);
  },
  post(path, data) {
    return API.request(path, { method: 'POST', data: data || {} });
  },
  postForm(path, form) {
    return API.request(path, { method: 'POST', form });
  },
};

/** HTML 转义 */
function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** 轻提示 */
let _toastTimer = null;
function toast(msg, type) {
  let box = document.getElementById('toast');
  if (!box) {
    box = document.createElement('div');
    box.id = 'toast';
    document.body.appendChild(box);
  }
  box.textContent = msg;
  box.className = 'toast show ' + (type || 'info');
  clearTimeout(_toastTimer);
  _toastTimer = setTimeout(() => {
    box.className = 'toast';
  }, 2600);
}

/**
 * 解析资源地址：后端返回的 /frontend/uploads/... 为站点根路径；
 * 主页位于站点根目录，根部署时直接原样使用；
 * 若部署在子路径（主页 URL 形如 /xxx/index.html），则以页面所在目录为基准自动补全
 */
function assetUrl(u) {
  if (!u) return '';
  if (/^(https?:)?\/\//i.test(u) || /^data:/i.test(u)) return u;
  if (u.startsWith('/frontend/')) {
    if (location.pathname === '/' || /^\/index\.html$/.test(location.pathname)) return u;
    const dir = location.pathname.replace(/[^/]*$/, ''); // 形如 .../（子路径部署）
    return dir + u.slice('/frontend/'.length);
  }
  return u;
}

/** 站点图标候选源（客户端按序回退，境内可达源优先） */
function faviconSources(url) {
  try {
    const host = new URL(url).hostname;
    return [
      'https://api.iowen.cn/favicon/' + host + '.png',
      'https://favicon.cccyun.cc/' + host,
      'https://favicon.im/' + host + '?larger=true',
      'https://icon.horse/icon/' + host,
      'https://www.google.com/s2/favicons?domain=' + host + '&sz=64',
    ];
  } catch (e) {
    return [];
  }
}

/** 根据网址获取站点 favicon 地址（首选源） */
function faviconUrl(url) {
  const s = faviconSources(url);
  return s.length ? s[0] : '';
}

/** 可选风格主题（与 themes.css 的 html[data-style] 对应） */
const SP_STYLES = ['soft', 'nature', 'natural', 'holo', 'gradient', 'material', 'fabric', 'aurora', 'scandi', 'clay', 'spotlight', 'neumorphism', 'skeuomorphism', 'immersive-photo', 'ghibli', 'fluent', 'warm-dashboard'];

/** 应用深浅模式（light/dark/system），system 实时跟随系统深浅 */
function setThemeMode(mode) {
  const root = document.documentElement;
  root.dataset.mode = mode === 'system' ? 'system' : mode;
  const dark = window.matchMedia('(prefers-color-scheme: dark)');
  root.dataset.theme = mode === 'system' ? (dark.matches ? 'dark' : 'light') : mode;
}

/** 主题：localStorage 手动锁定 > 后端默认设置；风格主题由 theme_style 决定；返回当前模式 */
function applyTheme(settings) {
  const root = document.documentElement;
  root.dataset.style = settings && SP_STYLES.includes(settings.theme_style) ? settings.theme_style : 'soft';

  const saved = localStorage.getItem('sp_theme');
  const mode = saved === 'light' || saved === 'dark' || saved === 'system'
    ? saved
    : (settings && ['light', 'dark', 'system'].includes(settings.default_theme) ? settings.default_theme : 'dark');
  setThemeMode(mode);

  // 跟随系统：系统深浅变化时实时切换（仅绑定一次）
  if (!window.__spSysThemeHook) {
    window.__spSysThemeHook = true;
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (document.documentElement.dataset.mode === 'system') {
        setThemeMode('system');
        if (typeof updateThemeBtn === 'function' && document.getElementById('themeBtn')) updateThemeBtn();
      }
    });
  }
  return mode;
}
