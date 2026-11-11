<!doctype html>
<html lang="zh-CN"@if(request()->routeIs('admin.admins.index', 'admin.logs.index')) data-lb-role-gate @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', $brand['name'])</title>
<script>
// 登录态 + 角色守卫：必须放在 <head>，早于 body 渲染。
// 真正的安全边界在服务端 /api/admin/*（Bearer + EnsureSuperAdmin），这里只是 UX。
(function () {
  var root = document.documentElement;

  try {
    if (!localStorage.getItem('lb_admin_at') && !localStorage.getItem('lb_admin_rt')) {
      location.replace('{{ route('admin.login') }}');
      return;
    }

    // 本地快照只用于让已确认的 super 提前看到顶部入口（减少闪烁），不承担最终判权。
    // 受限页主体由上面的 CSS 默认隐藏，等 body 底部按 /api/admin/auth/me 校准后再放开：
    // 快照可能刚被别人改过角色，不能拿它直接决定重定向。
    var profile = {};
    try {
      profile = JSON.parse(localStorage.getItem('lb_admin_profile') || '{}') || {};
    } catch (e) { /* 快照损坏，按非 super 处理 */ }
    if (profile.role === 'super') root.classList.add('lb-super-ready');
  } catch (e) {
    location.replace('{{ route('admin.login') }}');
  }
})();
</script>
<style>
:root { --line:#e3e6ea; --muted:#6b7280; --accent:{{ $brand['color'] }}; --danger:#a32d2d; --ok:#0f6e56; }
/* 角色门禁：默认隐藏，服务端实时 role 确认后再放开；JS 未执行时也不会泄露受限页按钮 */
html[data-lb-role-gate]:not(.lb-role-ready) main { visibility:hidden; }
html:not(.lb-super-ready) [data-super-only] { display:none; }
* { box-sizing:border-box; }
body { margin:0; font:14px/1.6 -apple-system,BlinkMacSystemFont,"PingFang SC",sans-serif; color:#1f2937; background:#f7f8fa; }
header { display:flex; align-items:center; gap:20px; flex-wrap:wrap; padding:12px 24px; background:#fff; border-bottom:1px solid var(--line); }
header a { color:var(--accent); text-decoration:none; }
header .nav { display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
header .head-right { margin-left:auto; display:flex; align-items:center; gap:20px; }
header .brand { font-weight:600; display:flex; align-items:center; gap:8px; padding-left:14px; border-left:1px solid var(--line); white-space:nowrap; }
header .brand-logo { height:22px; width:auto; max-width:120px; object-fit:contain; border-radius:4px; }
header .who { color:var(--muted); font-size:13px; }
main { max-width:1080px; margin:24px auto; padding:0 20px; }
.card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:20px; margin-bottom:20px; }
h1 { font-size:20px; margin:0 0 16px; }
h2 { font-size:15px; margin:0 0 14px; }
label { display:block; margin:10px 0 4px; color:#374151; }
input[type=text], input[type=password], input[type=file], select, textarea { width:100%; max-width:420px; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; background:#fff; font:inherit; }
button, .btn { display:inline-block; padding:7px 14px; border:1px solid var(--accent); border-radius:6px; background:var(--accent); color:#fff; font:inherit; cursor:pointer; text-decoration:none; }
button.ghost, .btn.ghost { background:#fff; color:var(--accent); }
button.danger { background:#fff; color:var(--danger); border-color:var(--danger); }
button:disabled { opacity:.5; cursor:default; }
table { width:100%; border-collapse:collapse; }
th, td { padding:10px 8px; border-bottom:1px solid var(--line); text-align:left; vertical-align:middle; }
th { color:var(--muted); font-weight:500; }
tr[draggable] { cursor:grab; }
tr.drag-over { background:#eef6fd; }
.msg { padding:10px 14px; border-radius:6px; margin-bottom:16px; }
.msg.ok { background:#e1f5ee; color:var(--ok); }
.msg.err { background:#fcebeb; color:var(--danger); }
.muted { color:var(--muted); }
.inline { display:inline; }
.row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
.row > div { min-width:160px; }
audio { height:32px; max-width:220px; }
img.thumb { width:40px; height:40px; object-fit:cover; border-radius:4px; background:#f3f4f6; }
tr.edit-row > td { background:#fafbfc; padding:16px 18px; }
.edit-form { max-width:640px; }
.edit-form .row > div { flex:1; }
.pager { display:flex; gap:8px; align-items:center; margin-top:16px; }
.pager button { padding:5px 12px; }
.progress { height:10px; border-radius:6px; background:#eef1f4; overflow:hidden; margin-top:12px; }
.progress > i { display:block; height:100%; width:0; background:var(--accent); transition:width .15s linear; }
.progress-text { font-size:12px; color:var(--muted); margin-top:6px; }
.upload-queue { margin-top:12px; font-size:13px; }
.upload-queue ul { margin:8px 0 0; padding-left:18px; max-height:190px; overflow:auto; }
.upload-queue li.ok { color:var(--ok); }
.upload-queue li.err { color:var(--danger); }
.stat-cards { display:flex; gap:14px; flex-wrap:wrap; }
.stat-cards > div { flex:1; min-width:160px; border:1px solid var(--line); border-radius:8px; padding:14px; }
.stat-cards strong { display:block; font-size:22px; margin-top:6px; }
.bars { display:flex; align-items:flex-end; gap:4px; height:120px; }
.bars > span { flex:1; min-width:4px; background:var(--accent); border-radius:3px 3px 0 0; opacity:.8; }
</style>
</head>
<body>
<header>
  {{-- 左侧：页面入口（操作按钮），退出单独留在右侧 --}}
  <nav class="nav">
    {{-- 层名来自 $levels（config/content.php ← .env 的 CONTENT_*） --}}
    <a href="{{ route('admin.tracks.index') }}">{{ $levels['item'] }}</a>
    <a href="{{ route('admin.albums.index') }}">{{ $levels['group'] }}</a>
    <a href="{{ route('admin.stats.index') }}">数据面板</a>
    <a href="{{ route('admin.admins.index') }}" data-super-only>管理员</a>
    <a href="{{ route('admin.logs.index') }}" data-super-only>操作日志</a>
  </nav>
  <div class="head-right">
    <span class="who" id="current-admin"></span>
    <button type="button" class="ghost" id="logout-btn">退出</button>
    {{-- 站名与 Logo 来自 $brand（config/brand.php ← .env 的 BRAND_*） --}}
    <span class="brand">
      @if(!empty($brand['logo']))<img class="brand-logo" src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">@endif
      <span>{{ $brand['name'] }}</span>
    </span>
  </div>
</header>
<main>
  <div id="page-msg" class="msg" style="display:none"></div>
  @yield('content')
</main>

<script src="{{ asset('js/admin.js') }}?v=3"></script>
<script>
(function () {
  if (!window.requireAdmin()) return;

  var root = document.documentElement;
  var gated = root.hasAttribute('data-lb-role-gate');
  var tracksUrl = '{{ route('admin.tracks.index') }}';
  var local = window.adminSession.profile();

  function showWho(p) {
    var el = document.getElementById('current-admin');
    if (el) el.textContent = (p && (p.display_name || p.username)) || '';
  }

  /** 按服务端实时 role 显隐入口；受限页确认是 super 才解除隐藏，否则回音频页 */
  function applyRole(p) {
    var isSuper = !!p && p.role === 'super';
    root.classList.toggle('lb-super-ready', isSuper);
    showWho(p);

    if (!gated) return;
    if (isSuper) {
      root.classList.add('lb-role-ready');
    } else {
      location.replace(tracksUrl);
    }
  }

  showWho(local);

  // 页面加载时校准一次 profile：角色被改动后无需重新登录即可反映到导航。
  // me 失败（或拿到旧版 admin.js 缓存）时回退本地快照，避免受限页永久空白；
  // 若期间已退出登录则不再动 DOM。
  var session = window.adminSession;
  var ready = session.syncProfile ? session.syncProfile() : Promise.resolve(null);
  ready.then(function (p) {
    if (!session.hasSession()) return;
    applyRole(p || local);
  });

  document.getElementById('logout-btn').addEventListener('click', function () {
    window.adminLogout();
  });
})();

window.toggleEdit = function (id) {
  const row = document.getElementById(id);
  if (row) row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
};

/** 统一 POST：失败弹提示，degraded 时明确告知顺序已退化 */
window.postJson = async function (url, body) {
  try {
    const data = await window.adminApi.post(url, body);
    if (data && data.degraded) {
      alert('目标行已失效（页面数据可能已过期），已移到该分组末尾，请刷新页面核对顺序。');
    }
    return true;
  } catch (e) {
    alert('操作失败：' + (e.message || '未知错误'));
    return false;
  }
};

/**
 * 行拖拽排序。
 * 落点在行的上半部分 → 插到该行之前；下半部分 → 插到该行之后（组内末行下半部分即移到末尾，target_id 传 null）。
 * options.sameGroup(movingRow, targetRow) 限制跨分组拖拽；options.groupHint 为拒绝提示。
 */
window.bindReorder = function (selector, url, buildPayload, options) {
  const opts = options || {};
  const sameGroup = opts.sameGroup || (() => true);
  const groupHint = opts.groupHint || '只能在同一分组内排序';
  const allRows = () => Array.from(document.querySelectorAll(selector));
  // 组内行按文档顺序排列：落点计算不再依赖 nextElementSibling 单步遍历，天然跳过编辑行与其他分组
  const groupRows = (row) => allRows().filter(r => sameGroup(row, r));

  allRows().forEach(row => {
    // 列表是 JS 重渲染的，同一行可能被多次传入，用标记避免重复绑定
    if (row.dataset.lbReorderBound === '1') return;
    row.dataset.lbReorderBound = '1';

    row.addEventListener('dragstart', e => {
      window.__lbMovingId = row.dataset.id;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', row.dataset.id);
    });
    row.addEventListener('dragend', () => {
      window.__lbMovingId = '';
      row.classList.remove('drag-over');
    });
    // 跨分组时直接给「禁止」光标，不必等到 drop 才提示
    row.addEventListener('dragover', e => {
      const movingRow = allRows().find(r => r.dataset.id === window.__lbMovingId);
      if (movingRow && !sameGroup(movingRow, row)) { e.dataTransfer.dropEffect = 'none'; return; }
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      row.classList.add('drag-over');
    });
    row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
    row.addEventListener('drop', async e => {
      e.preventDefault();
      row.classList.remove('drag-over');
      const movingId = e.dataTransfer.getData('text/plain') || window.__lbMovingId;
      window.__lbMovingId = '';
      if (!movingId || movingId === row.dataset.id) return;

      const movingRow = allRows().find(r => r.dataset.id === movingId);
      if (!movingRow) return;
      if (!sameGroup(movingRow, row)) { alert(groupHint); return; }

      const rect = row.getBoundingClientRect();
      const insertAfter = e.clientY - rect.top > rect.height / 2;
      let targetRow = row;
      if (insertAfter) {
        const rows = groupRows(movingRow);
        const index = rows.indexOf(row);
        targetRow = (index >= 0 && index + 1 < rows.length) ? rows[index + 1] : null;
      }
      if (targetRow === movingRow) return; // 原地，无需请求

      if (await window.postJson(url, buildPayload(Number(movingId), targetRow, movingRow))) location.reload();
    });
  });
};
</script>
@yield('scripts')
</body>
</html>
