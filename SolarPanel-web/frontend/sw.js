// SolarPanel PWA Service Worker — 轻量缓存策略
const CACHE_NAME = 'sp-cache-v18';
const RUNTIME_CACHE = 'sp-runtime-v7';

// 预缓存（安装时）
const PRECACHE_URLS = [
  './',
  'index.html',
  'login.html',
  'admin.html',
  'offline.html',
  'assets/css/common.css',
  'assets/css/index.css',
  'assets/css/auth-admin.css',
  'assets/css/themes.css',
  'assets/js/api.js',
  'assets/js/index.js',
  'assets/js/login.js',
  'assets/js/admin.js',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME).then(c => c.addAll(PRECACHE_URLS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(k => k !== CACHE_NAME && k !== RUNTIME_CACHE).map(k => caches.delete(k))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const req = e.request;
  // 只处理 GET
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // 1) API 调用 —— 不缓存（后端数据实时性）
  if (url.pathname.startsWith('/api/')) return;

  // 2) manifest.json / sw.js 自身 —— 直接网络
  if (url.pathname.endsWith('manifest.json') || url.pathname.endsWith('sw.js')) return;

  // 3) HTML 导航 —— 网络优先，失败回退缓存，再失败回退 offline.html
  if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
    e.respondWith(
      fetch(req).then(res => {
        const copy = res.clone();
        caches.open(RUNTIME_CACHE).then(c => c.put(req, copy));
        return res;
      }).catch(() =>
        caches.match(req).then(cached => cached || caches.match('./offline.html'))
      )
    );
    return;
  }

  // 4) 上传图片（/uploads/） —— 网络优先，失败回退缓存
  if (url.pathname.startsWith('/uploads/') || url.pathname.startsWith('/frontend/uploads/')) {
    e.respondWith(
      fetch(req).then(res => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(RUNTIME_CACHE).then(c => {
            // 运行时缓存上限 60 条：超过时清掉最旧的
            c.keys().then(keys => {
              if (keys.length > 60) {
                c.delete(keys[0]); // FIFO
              }
            });
            c.put(req, copy);
          });
        }
        return res;
      }).catch(() => caches.match(req))
    );
    return;
  }

  // 5) 静态资源（CSS/JS/字体/图片）—— stale-while-revalidate
  e.respondWith(
    caches.match(req).then(cached => {
      const fetchPromise = fetch(req).then(res => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE_NAME).then(c => c.put(req, copy));
        }
        return res;
      }).catch(() => cached);
      return cached || fetchPromise;
    })
  );
});

// 处理 postMessage：SKIP_WAITING / CLIENTS_CLAIM 触发平滑更新
self.addEventListener('message', e => {
  if (e.data && e.data.type === 'SKIP_WAITING') self.skipWaiting();
  if (e.data && e.data.type === 'CLIENTS_CLAIM') self.clients.claim();
});
