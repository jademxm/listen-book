<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $brand['name'] }}登录</title>
<style>
body { margin:0; display:flex; min-height:100vh; align-items:center; justify-content:center; background:#f7f8fa; font:14px/1.6 -apple-system,BlinkMacSystemFont,"PingFang SC",sans-serif; }
.box { width:320px; background:#fff; border:1px solid #e3e6ea; border-radius:10px; padding:28px; }
h1 { font-size:18px; margin:0 0 18px; display:flex; align-items:center; gap:8px; }
h1 img { height:22px; width:auto; max-width:120px; object-fit:contain; border-radius:4px; }
input { width:100%; padding:9px 10px; border:1px solid #d1d5db; border-radius:6px; font:inherit; }
button { width:100%; margin-top:16px; padding:9px; border:0; border-radius:6px; background:{{ $brand['color'] }}; color:#fff; font:inherit; cursor:pointer; }
.err { color:#a32d2d; margin-top:10px; }
</style>
</head>
<body>
<form class="box" method="POST" action="{{ route('admin.login.post') }}">
  @csrf
  <h1>
    @if(!empty($brand['logo']))<img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">@endif
    <span>{{ $brand['name'] }}</span>
  </h1>
  <label for="password">管理密码</label>
  <input id="password" type="password" name="password" required autofocus>
  @error('password')<div class="err">{{ $message }}</div>@enderror
  <button type="submit">登录</button>
</form>
</body>
</html>
