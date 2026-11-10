@extends('admin.layouts.app')
@section('title', '操作日志')

@section('content')
<h1>操作日志</h1>

<div class="card">
  <form id="filter-form" class="row">
    <div>
      <label for="f-admin">操作人</label>
      <select id="f-admin" name="admin_id"><option value="">全部</option></select>
    </div>
    <div>
      <label for="f-action">动作</label>
      <select id="f-action" name="action"><option value="">全部</option></select>
    </div>
    <div>
      <label for="f-success">结果</label>
      <select id="f-success" name="success">
        <option value="">全部</option>
        <option value="1">成功</option>
        <option value="0">失败</option>
      </select>
    </div>
    <div>
      <label for="f-from">起始日期</label>
      <input id="f-from" type="date" name="from">
    </div>
    <div>
      <label for="f-to">结束日期</label>
      <input id="f-to" type="date" name="to">
    </div>
    <div>
      <label for="f-keyword">关键词</label>
      <input id="f-keyword" type="text" name="keyword" maxlength="60" placeholder="摘要 / 账号 / 路径">
    </div>
    <div>
      <button type="submit">筛选</button>
      <button type="button" class="ghost" id="reset-btn">重置</button>
    </div>
  </form>
</div>

<div class="card">
  <table>
    <thead>
      <tr>
        <th style="width:150px">时间</th>
        <th style="width:110px">操作人</th>
        <th style="width:120px">动作</th>
        <th>摘要</th>
        <th style="width:70px">结果</th>
        <th style="width:120px">IP</th>
        <th style="width:70px">参数</th>
      </tr>
    </thead>
    <tbody id="log-rows">
      <tr><td colspan="7" class="muted">加载中…</td></tr>
    </tbody>
  </table>
  <div class="pager" id="pager"></div>
  <p class="muted" style="font-size:12px">仅记录写操作；密码与令牌不会写入日志。日志只追加，不提供修改或删除入口。</p>
</div>
@endsection

@section('scripts')
<script>
(function () {
  if (!window.requireAdmin()) return;

  const api = window.adminApi;
  const esc = window.adminUi.escapeHtml;
  const form = document.getElementById('filter-form');
  const tbody = document.getElementById('log-rows');
  const pager = document.getElementById('pager');
  const state = { page: 1, filtersReady: false };

  function fillFilters(filters) {
    if (state.filtersReady || !filters) return;
    state.filtersReady = true;

    const admin = document.getElementById('f-admin');
    const action = document.getElementById('f-action');
    const adminValue = admin.value;
    const actionValue = action.value;

    admin.innerHTML = '<option value="">全部</option>' + (filters.admins || []).map(a =>
      '<option value="' + a.id + '">' + esc(a.label) + '</option>'
    ).join('');
    action.innerHTML = '<option value="">全部</option>' + (filters.actions || []).map(a =>
      '<option value="' + esc(a.value) + '">' + esc(a.label) + '</option>'
    ).join('');

    admin.value = adminValue;
    action.value = actionValue;
  }

  function detailHtml(log) {
    const payload = log.payload && Object.keys(log.payload).length
      ? JSON.stringify(log.payload, null, 2)
      : '（无参数）';
    return '<tr class="detail-row" id="log-detail-' + log.id + '" style="display:none">' +
      '<td colspan="7" style="background:#fafbfc">' +
        '<div class="muted" style="font-size:12px">' + esc(log.method) + ' ' + esc(log.path) +
          ' · HTTP ' + log.status + ' · ' + esc(log.user_agent || '') + '</div>' +
        '<pre style="white-space:pre-wrap;word-break:break-all;margin:8px 0 0;font-size:12px">' + esc(payload) + '</pre>' +
      '</td>' +
    '</tr>';
  }

  function rowHtml(log) {
    return '<tr>' +
      '<td class="muted">' + esc(log.created_at || '') + '</td>' +
      '<td>' + esc(log.username || '—') + '</td>' +
      '<td>' + esc(log.action_label) + '</td>' +
      '<td>' + esc(log.summary) + '</td>' +
      '<td>' + (log.success ? '<span style="color:var(--ok)">成功</span>' : '<span style="color:var(--danger)">失败</span>') + '</td>' +
      '<td class="muted">' + esc(log.ip || '') + '</td>' +
      '<td><button type="button" class="ghost" data-detail="' + log.id + '">详情</button></td>' +
    '</tr>' + detailHtml(log);
  }

  function renderPager(p) {
    if (!p || p.last_page <= 1) { pager.innerHTML = '<span class="muted">共 ' + (p ? p.total : 0) + ' 条</span>'; return; }
    pager.innerHTML =
      '<button type="button" class="ghost" data-page="' + (p.current_page - 1) + '"' + (p.current_page <= 1 ? ' disabled' : '') + '>上一页</button>' +
      '<span class="muted">第 ' + p.current_page + ' / ' + p.last_page + ' 页，共 ' + p.total + ' 条</span>' +
      '<button type="button" class="ghost" data-page="' + (p.current_page + 1) + '"' + (p.current_page >= p.last_page ? ' disabled' : '') + '>下一页</button>';
  }

  async function load() {
    const fd = new FormData(form);
    const params = new URLSearchParams({ page: String(state.page) });
    ['admin_id', 'action', 'success', 'from', 'to', 'keyword'].forEach(function (key) {
      const value = String(fd.get(key) || '').trim();
      if (value) params.set(key, value);
    });

    tbody.innerHTML = '<tr><td colspan="7" class="muted">加载中…</td></tr>';

    try {
      const data = await api.get('/api/admin/logs?' + params.toString());
      fillFilters(data.filters);
      tbody.innerHTML = (data.items || []).length
        ? data.items.map(rowHtml).join('')
        : '<tr><td colspan="7" class="muted">没有符合条件的操作记录。</td></tr>';
      renderPager(data.pagination);
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="7" class="muted">加载失败：' + esc(e.message) + '</td></tr>';
    }
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    state.page = 1;
    load();
  });

  document.getElementById('reset-btn').addEventListener('click', function () {
    form.reset();
    state.page = 1;
    load();
  });

  pager.addEventListener('click', function (e) {
    const btn = e.target.closest('button[data-page]');
    if (!btn || btn.disabled) return;
    state.page = Number(btn.dataset.page);
    load();
  });

  tbody.addEventListener('click', function (e) {
    const btn = e.target.closest('button[data-detail]');
    if (!btn) return;
    window.toggleEdit('log-detail-' + btn.dataset.detail);
  });

  load();
})();
</script>
@endsection
