/**
 * 前端公共：API 请求封装 + 工具函数
 */

/* ================= 应用版本与更新日志（每次更新只需改这里） ================= */
const APP_VERSION = 'v2.1.00';
const APP_CHANGELOG = [
  { ver: 'v2.1.00', date: '2026-09-16', items: [
    '🎨 风格主题体系升级：18 种风格全部浅/深双态 + WCAG AA 对比度，新增 Blueprint 工程蓝图完整实现（蓝底白线 / 网格坐标 / 等宽字 / 橙色标注 / 全局禁圆角）',
    '🖼️ 主页快速添加体系：快速添加弹窗（fetch_meta 自动获取 / 三种图标类型 / 实时预览 / 颜色面板）、分组 header 常驻「+」按钮、排序态卡片红色 × 删除',
    '🔐 安全与认证加固：两步验证 TOTP 2FA + 受信任设备（30 天免 2FA）、访客锁屏（密码 + 隐私加固）、公开接口限流、SSRF 防护、CSRF Token 强制校验',
    '⚙️ 后端 settings 体系重构：「搜索引擎搜索栏」「卡片筛选搜索栏」显隐开关（白名单 / DB 默认值 / 恢复初始状态四重对齐），搜索栏宽度滑块归入搜索引擎分区',
    '🎯 UI 交互主题化：顶栏向下滚自动隐藏、原生 confirm → 主题化 uiConfirm（14 处）、全部风格主题输入框/按钮/卡片/表格等 20+ 组件覆盖',
    '📱 PWA + Docker 完善：Docker 内置 HTTPS 证书、PWA 内网 HTTP/HTTPS 安装支持、manifest.json 补齐、Service Worker 缓存版本自动更新',
    '🐛 关键缺陷修复：热点新闻视图筛选卡片 CSS 选择器修复（.hidden vs [hidden] 属性）、Immersive Photo 深色下拉框背景、访客登录跳转循环、favicon 源过期自动降级文字图标',
    '🛠️ 工程质量：PHP + Go 双版本独立迭代、完整安全审计（无高危/中危）、backup 恢复初始状态键统一补齐、SP_STYLES / admin.html option / settings.php 白名单三重注册约定',
  ]},
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
      // 响应不是 JSON：通常是反向代理在到达服务前拦截（如 Nginx 413、502 错误页）
      if (res.status === 413) {
        throw new Error('上传内容过大，被反向代理拦截（HTTP 413）。若前置了 Nginx，请调大 client_max_body_size（如 client_max_body_size 512m;）后重试');
      }
      let snippet = '';
      try {
        snippet = (await res.text()).replace(/\s+/g, ' ').trim().slice(0, 150);
      } catch (_) { /* ignore */ }
      throw new Error('接口返回异常（HTTP ' + res.status + (snippet ? '）：' + snippet : '）'));
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
      'https://favicon.cccyun.cc/' + host,
      'https://icon.horse/icon/' + host,
      'https://favicon.im/' + host + '?larger=true',
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

/* ================= 文字图标工具（卡片 icon_type=text 专用） ================= */

/** 智能截断：含中文取前 4 码点，纯英文/数字/符号取前 12 码点 */
function smartIconText(title) {
  if (!title) return '';
  const chars = Array.from(String(title).trim());
  if (!chars.length) return '';
  const hasCN = chars.some(c => /[\u3400-\u9fff]/.test(c));
  return chars.slice(0, hasCN ? 4 : 12).join('');
}

/** 判断中文布局还是英文布局（含任何中文 → cn；全英文/数字/符号 → en） */
function textLayout(text) {
  if (!text) return 'en';
  const cnCount = Array.from(text).filter(c => /[\u3400-\u9fff]/.test(c)).length;
  return cnCount > 0 ? 'cn' : 'en';
}

/**
 * 渲染文字型图标
 * @param {HTMLElement} el        图标容器（已渲染到 DOM，可测 clientWidth）
 * @param {string}      text      已 smartIconText 截断好的文字
 * @param {number}      baseSize  字号上限（不同容器给不同值：主页 21 / 弹窗 18）
 */
function renderTextIcon(el, text, baseSize) {
  el.innerHTML = '';
  el.classList.remove('icon-grid-cn', 'icon-wrap-en');
  if (!text) { el.textContent = '?'; return; }

  const chars = Array.from(text);
  const layout = textLayout(text);
  el.classList.add(layout === 'cn' ? 'icon-grid-cn' : 'icon-wrap-en');

  // ★ 关键：读真实 padding，算内容区实际可用宽度
  const cs = getComputedStyle(el);
  const padH = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0);
  const innerW = Math.max(10, ((el.clientWidth || 52) - padH));

  let size;
  if (layout === 'cn') {
    // 中文 2 列 grid，每格 glyph ≈ innerW/2 → font-size ≈ 0.82 × 格子宽
    size = Math.floor((innerW / 2) * 0.82);
  } else {
    // 英文 4 列 grid，每格 glyph ≈ innerW/4 → font-size ≈ 1.35 × 格子宽
    // 英文字母（M/W）宽度 ≈ 0.7 × fontSize，需留足空间避免溢出
    size = Math.floor((innerW / 4) * 1.35);
  }
  size = Math.max(7, Math.min(baseSize, size));
  el.style.fontSize = size + 'px';

  chars.forEach(c => {
    const s = document.createElement('span');
    s.className = 'child-text';
    s.textContent = c;
    el.appendChild(s);
  });
}

/**
 * 脏数据检测：icon_type=text 但 icon_value 里存的是图片 URL
 */
function isImageUrlLike(s) {
  if (!s) return false;
  const v = String(s).trim();
  if (!v) return false;
  if (/^https?:\/\//i.test(v)) return true;
  if (/^data:image\//i.test(v)) return true;
  if (/\/uploads\//i.test(v)) return true;
  if (/\/favicon/i.test(v)) return true;
  if (/^\/[^?#]*\.(png|jpe?g|gif|webp|svg|ico|bmp|avif)(\?|$)/i.test(v)) return true;
  if (/\.(png|jpe?g|gif|webp|svg|ico|bmp|avif)(\?|$)/i.test(v) && v.indexOf(' ') === -1) return true;
  return false;
}

/** 颜色合成：透明色存 hex，半透明存 rgba */
function buildBgCss(color, alphaPercent) {
  if (!color) return '';
  if (alphaPercent >= 100) return color.startsWith('#') ? color : color;
  let h = color.trim();
  if (!h.startsWith('#')) return color;
  h = h.slice(1);
  if (h.length === 3) h = h.split('').map(c => c + c).join('');
  const r = parseInt(h.slice(0, 2), 16);
  const g = parseInt(h.slice(2, 4), 16);
  const b = parseInt(h.slice(4, 6), 16);
  return `rgba(${r},${g},${b},${(alphaPercent / 100).toFixed(2)})`;
}

/** 可选风格主题（与 themes.css 的 html[data-style] 对应） */
const SP_STYLES = ['soft', 'nature', 'natural', 'holo', 'gradient', 'material', 'fabric', 'aurora', 'scandi', 'clay', 'spotlight', 'neumorphism', 'skeuomorphism', 'immersive-photo', 'ghibli', 'fluent', 'warm-dashboard', 'blueprint'];

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

/* ================= PWA Service Worker 注册 ================= */
/** 注册 SW，仅 https 或 localhost 环境下启用。
 *  Docker 版：不显示 update-ready 提示（镜像更新靠 docker pull + 重启，用户自己知道），
 *  updatefound 事件仅静默等下次刷新生效；skipWaiting POST 保留以备未来手动更新。
 */
function spRegisterSW() {
  if (!('serviceWorker' in navigator)) return;
  // 安全上下文：HTTPS / localhost / 127.0.0.1 / 内网私有 IP 段（自托管场景）
  const hn = location.hostname;
  const isPrivateIP = /^(10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+|192\.168\.\d+\.\d+|\[?[fF][cdCD][0-9a-fA-F:]+\]?)$/.test(hn);
  const isSecure = location.protocol === 'https:' || hn === 'localhost' || hn === '127.0.0.1' || isPrivateIP;
  if (!isSecure) return;
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').then(reg => {
      reg.addEventListener('updatefound', () => {
        const newSW = reg.installing;
        if (!newSW) return;
        newSW.addEventListener('statechange', () => {
          if (newSW.state === 'installed' && navigator.serviceWorker.controller) {
            // 有新版本可用，显示更新提示
            const box = document.getElementById('updateReady');
            if (box) {
              box.hidden = false;
              const btn = document.getElementById('updateApplyBtn');
              if (btn && !btn._bound) {
                btn._bound = true;
                btn.onclick = () => {
                  navigator.serviceWorker.getRegistration().then(r => {
                    if (r && r.waiting) {
                      r.waiting.postMessage({ type: 'SKIP_WAITING' });
                      r.waiting.addEventListener('statechange', () => {
                        if (r.waiting.state === 'activated') location.reload();
                      });
                    }
                  });
                };
              }
            }
          }
        });
      });
    }).catch(err => console.warn('[PWA] SW register failed:', err));
    // 接收已激活 SW 的 postMessage（SKIP_WAITING 触发后 reload）
    navigator.serviceWorker.addEventListener('message', ev => {
      if (ev.data && ev.data.type === 'CLIENTS_CLAIM') location.reload();
    });
  });
}
// 立即尝试注册（api.js 被所有页面加载，统一入口）
spRegisterSW();
