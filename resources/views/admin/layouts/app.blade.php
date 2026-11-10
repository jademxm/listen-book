<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', $brand['name'])</title>
<style>
:root { --line:#e3e6ea; --muted:#6b7280; --accent:{{ $brand['color'] }}; --danger:#a32d2d; --ok:#0f6e56; }
* { box-sizing:border-box; }
body { margin:0; font:14px/1.6 -apple-system,BlinkMacSystemFont,"PingFang SC",sans-serif; color:#1f2937; background:#f7f8fa; }
header { display:flex; align-items:center; gap:20px; padding:12px 24px; background:#fff; border-bottom:1px solid var(--line); }
header a { color:var(--accent); text-decoration:none; }
header .brand { font-weight:600; margin-right:auto; display:flex; align-items:center; gap:8px; }
header .brand-logo { height:22px; width:auto; max-width:120px; object-fit:contain; border-radius:4px; }
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
</style>
</head>
<body>
<header>
  {{-- 站名与 Logo 来自 $brand（config/brand.php ← .env 的 BRAND_*） --}}
  <span class="brand">
    @if(!empty($brand['logo']))<img class="brand-logo" src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">@endif
    <span>{{ $brand['name'] }}</span>
  </span>
  {{-- 层名来自 $levels（config/content.php ← .env 的 CONTENT_*） --}}
  <a href="{{ route('admin.tracks.index') }}">{{ $levels['item'] }}</a>
  <a href="{{ route('admin.albums.index') }}">{{ $levels['group'] }}</a>
  <form method="POST" action="{{ route('admin.logout') }}" class="inline">@csrf<button class="ghost">退出</button></form>
</header>
<main>
  @if(session('status'))<div class="msg ok">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="msg err">{{ $errors->first() }}</div>@endif
  @yield('content')
</main>
<script>
window.csrfToken = document.querySelector('meta[name="csrf-token"]').content;
window.toggleEdit = function (id) {
  const row = document.getElementById(id);
  if (row) row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
};
window.postJson = async function (url, body) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.csrfToken },
    body: JSON.stringify(body)
  });
  const data = await res.json().catch(() => ({ ok: false, message: '响应解析失败' }));
  if (!res.ok || !data.ok) { alert('操作失败：' + (data.message || res.status)); return false; }
  // 目标行已失效（页面数据过期）时服务端会退化到「移到分组末尾」，这里明确告知而不是静默
  if (data.degraded) alert(data.message || '目标行已失效，已移到该分组末尾');
  return true;
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
