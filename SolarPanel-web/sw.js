/**
 * SolarPanel Service Worker
 * 策略：
 *   - HTML 导航请求：网络优先 → 缓存 → 离线兜底页
 *   - 静态资源（css/js/img/svg/ico/字体）：stale-while-revalidate
 *   - API（backend/api/）与跨域请求：仅网络，不缓存（动态数据 / 外部资源）
 *   - uploads：网络优先 → 缓存兜底（用户上传的图片允许离线复用）
 *   - 运行时缓存 LRU 限制（避免无限膨胀），activate 清理旧版本
 */
const SW_VER = 'sp-sw-v2100';
const CACHE_STATIC = SW_VER + '-static';
const CACHE_RUNTIME = SW_VER + '-runtime';
const CACHE_UPLOADS = SW_VER + '-uploads';
const RUNTIME_MAX = 60;

const PRECACHE_URLS = [
  './',
  'index.html',
  'frontend/offline.html',
  'frontend/assets/img/icon.svg',
  'favicon.ico'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_STATIC).then(async (cache) => {
      await Promise.all(
        PRECACHE_URLS.map((url) =>
          cache.add(new Request(url, { cache: 'reload' })).catch(() => {})
        )
      );
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((k) => !k.startsWith(SW_VER))
          .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

async function trimCache(cacheName, max) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  if (keys.length <= max) return;
  for (let i = 0; i < keys.length - max; i++) {
    await cache.delete(keys[i]);
  }
}

function isStaticAsset(url, pathname) {
  if (url.origin !== self.location.origin) return false;
  return /\.(?:css|js|mjs|svg|png|jpg|jpeg|gif|webp|ico|woff2?|ttf|eot|otf)(?:\?|$)/i.test(pathname);
}

function isUpload(pathname) {
  return pathname.startsWith(self.location.pathname.replace(/[^/]*$/, '') + 'frontend/uploads/');
}

function isApi(pathname) {
  return pathname.includes('backend/api/');
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  if (url.origin !== self.location.origin) return;
  if (isApi(url.pathname)) return;

  // HTML 导航：网络优先 → 缓存 → 离线页
  if (req.mode === 'navigate' || (req.destination === 'document')) {
    event.respondWith(
      (async () => {
        try {
          const net = await fetch(req);
          const cache = await caches.open(CACHE_RUNTIME);
          cache.put(req, net.clone()).catch(() => {});
          return net;
        } catch (e) {
          const cached = await caches.match(req);
          if (cached) return cached;
          const staticCache = await caches.open(CACHE_STATIC);
          const shell = await staticCache.match('index.html') || await staticCache.match('./');
          if (shell) return shell;
          return caches.match('frontend/offline.html');
        }
      })()
    );
    return;
  }

  // uploads：网络优先 → 缓存兜底
  if (isUpload(url.pathname)) {
    event.respondWith(
      (async () => {
        try {
          const net = await fetch(req);
          const cache = await caches.open(CACHE_UPLOADS);
          cache.put(req, net.clone()).catch(() => {});
          return net;
        } catch (e) {
          const cached = await caches.match(req);
          if (cached) return cached;
          throw e;
        }
      })()
    );
    return;
  }

  // 静态资源：stale-while-revalidate
  if (isStaticAsset(url, url.pathname)) {
    event.respondWith(
      (async () => {
        const cache = await caches.open(CACHE_RUNTIME);
        const cached = await cache.match(req);
        const fetchPromise = fetch(req).then((net) => {
          if (net && (net.ok || net.type === 'opaque')) {
            cache.put(req, net.clone()).catch(() => {});
          }
          return net;
        }).catch(() => cached);
        return cached || (await fetchPromise);
      })()
    );
    trimCache(CACHE_RUNTIME, RUNTIME_MAX);
    return;
  }

  event.respondWith(
    (async () => {
      try {
        return await fetch(req);
      } catch (e) {
        const cached = await caches.match(req);
        if (cached) return cached;
        throw e;
      }
    })()
  );
});

self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type === 'SKIP_WAITING') self.skipWaiting();
  else if (data.type === 'CLIENTS_CLAIM') self.clients.claim();
});
