/**
 * 登录页逻辑
 */
(async function () {
  // 已登录则直接进入后台
  try {
    const u = await API.get('../backend/api/auth.php?action=me');
    if (u) {
      location.replace('admin.html');
      return;
    }
  } catch (e) { /* 未登录，正常展示登录页 */ }

  // 应用后端默认主题
  try {
    const data = await API.get('../backend/api/public.php');
    if (data && data.settings) applyTheme(data.settings);
  } catch (e) { /* 忽略 */ }

  const form = document.getElementById('loginForm');
  const errEl = document.getElementById('loginError');
  const btn = document.getElementById('loginBtn');

  form.addEventListener('submit', async e => {
    e.preventDefault();
    errEl.textContent = '';
    btn.disabled = true;
    btn.textContent = '登录中…';
    try {
      await API.post('../backend/api/auth.php?action=login', {
        username: document.getElementById('username').value.trim(),
        password: document.getElementById('password').value,
      });
      toast('登录成功', 'success');
      location.href = 'admin.html';
    } catch (err) {
      errEl.textContent = err.message;
      btn.disabled = false;
      btn.textContent = '登 录';
    }
  });
})();
