<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $brand['name'] }}登录</title>
<style>
body { margin:0; display:flex; min-height:100vh; align-items:center; justify-content:center; background:#f7f8fa; font:14px/1.6 -apple-system,BlinkMacSystemFont,"PingFang SC",sans-serif; }
.box { width:340px; background:#fff; border:1px solid #e3e6ea; border-radius:10px; padding:28px; }
h1 { font-size:18px; margin:0 0 18px; display:flex; align-items:center; gap:8px; }
h1 img { height:22px; width:auto; max-width:120px; object-fit:contain; border-radius:4px; }
label { display:block; margin:12px 0 4px; color:#374151; }
input { width:100%; padding:9px 10px; border:1px solid #d1d5db; border-radius:6px; font:inherit; }
input:focus { outline:none; border-color:{{ $brand['color'] }}; }
button { width:100%; margin-top:18px; padding:9px; border:0; border-radius:6px; background:{{ $brand['color'] }}; color:#fff; font:inherit; cursor:pointer; }
button:disabled { opacity:.6; cursor:default; }
.err { color:#a32d2d; margin-top:12px; font-size:13px; }
.hint { color:#6b7280; font-size:12px; margin-top:14px; }
</style>
</head>
<body>
<form class="box" id="login-form" autocomplete="on">
  <h1>
    @if(!empty($brand['logo']))<img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">@endif
    <span>{{ $brand['name'] }}</span>
  </h1>

  <label for="account">账号</label>
  <input id="account" name="account" type="text" autocomplete="username" maxlength="64" required autofocus>

  <label for="password">密码</label>
  <input id="password" name="password" type="password" autocomplete="current-password" maxlength="200" required>

  <div class="err" id="err" style="display:none"></div>
  <button type="submit" id="submit">登录</button>
  <p class="hint">用户名或手机号均可登录。连续 5 次密码错误将锁定 15 分钟。</p>
</form>

<script src="{{ asset('js/admin.js') }}?v=1"></script>
<script>
(function () {
  // 已有登录态就别停在登录页
  if (window.adminSession.hasSession()) {
    location.replace('{{ route('admin.tracks.index') }}');
    return;
  }

  var form = document.getElementById('login-form');
  var err = document.getElementById('err');
  var btn = document.getElementById('submit');

  function showError(message) {
    err.textContent = message;
    err.style.display = '';
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    err.style.display = 'none';

    var account = document.getElementById('account').value.trim();
    var password = document.getElementById('password').value;

    if (!account || !password) {
      showError('请填写账号和密码');
      return;
    }

    btn.disabled = true;
    btn.textContent = '登录中…';

    fetch('/api/admin/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ account: account, password: password })
    })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (body) {
          if (!res.ok || body.code !== 0) {
            throw new Error(body.message || '登录失败');
          }
          return body.data;
        });
      })
      .then(function (data) {
        window.adminSession.setSession(data);
        location.replace('{{ route('admin.tracks.index') }}');
      })
      .catch(function (e) {
        showError(e.message || '登录失败，请稍后再试');
        btn.disabled = false;
        btn.textContent = '登录';
        document.getElementById('password').select();
      });
  });
})();
</script>
</body>
</html>
