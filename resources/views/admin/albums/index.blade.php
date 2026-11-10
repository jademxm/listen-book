@extends('admin.layouts.app')
@php($group = $levels['group'])
@php($item = $levels['item'])
@section('title', $group.'管理')

@section('content')
<h1>{{ $group }}管理</h1>

<div class="card">
  <h2>新建{{ $group }}</h2>
  <form id="create-form" enctype="multipart/form-data">
    <label for="create-title">{{ $group }}名称</label>
    <input id="create-title" type="text" name="title" maxlength="120" required>
    <label for="create-description">简介（选填）</label>
    <input id="create-description" type="text" name="description" maxlength="500">
    <label for="create-cover">封面（选填，jpg/png/webp ≤5MB）</label>
    <input id="create-cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp">
    <div style="margin-top:14px"><button type="submit" id="create-btn">创建</button></div>
  </form>
</div>

<div class="card">
  <h2>{{ $group }}列表（拖动行调整顺序：落在行的上半部分插到其之前，下半部分插到其之后）</h2>
  <table>
    <thead><tr><th style="width:60px">顺序</th><th style="width:60px">封面</th><th>名称</th><th style="width:90px">上架{{ $item }}</th><th style="width:220px">操作</th></tr></thead>
    <tbody id="album-rows">
      <tr><td colspan="5" class="muted">加载中…</td></tr>
    </tbody>
  </table>
  <p class="muted" style="font-size:12px">拖动后按落点插入：落在行的下半部分即插到该行之后，落在最后一行下半部分即移到末尾。{{ $item }}数为「已上架」数量，与小程序端显示一致。</p>
</div>
@endsection

@section('scripts')
<script>
(function () {
  if (!window.requireAdmin()) return;

  const LEVELS = @json($levels);
  const esc = window.adminUi.escapeHtml;
  const api = window.adminApi;

  const tbody = document.getElementById('album-rows');
  const createForm = document.getElementById('create-form');

  function rowHtml(a) {
    const cover = a.cover_url ? '<img class="thumb" src="' + esc(a.cover_url) + '" alt="">' : '';

    return '' +
      '<tr draggable="true" data-id="' + a.id + '">' +
        '<td>' + a.sort_order + '</td>' +
        '<td>' + cover + '</td>' +
        '<td>' + esc(a.title) +
          '<div class="muted" style="font-size:12px">' + esc(a.description || '') + '</div>' +
        '</td>' +
        '<td>' + a.tracks_count + '</td>' +
        '<td>' +
          '<a class="btn ghost" href="/admin/tracks?album_id=' + a.id + '">查看' + esc(LEVELS.item) + '</a> ' +
          '<button type="button" class="ghost" data-act="edit" data-id="' + a.id + '">编辑</button> ' +
          '<button type="button" class="danger" data-act="delete" data-id="' + a.id + '">移除</button>' +
          '<div class="muted" style="font-size:12px">' + (a.status ? '已上架' : '已下架') + '</div>' +
        '</td>' +
      '</tr>' +
      '<tr class="edit-row" id="edit-album-' + a.id + '" style="display:none">' +
        '<td colspan="5">' +
          '<form class="edit-form" data-edit-id="' + a.id + '" enctype="multipart/form-data">' +
            '<div class="row">' +
              '<div>' +
                '<label>' + esc(LEVELS.group) + '名称</label>' +
                '<input type="text" name="title" maxlength="120" value="' + esc(a.title) + '" required>' +
              '</div>' +
              '<div>' +
                '<label>状态</label>' +
                '<select name="status">' +
                  '<option value="1"' + (a.status ? ' selected' : '') + '>已上架</option>' +
                  '<option value="0"' + (a.status ? '' : ' selected') + '>已下架</option>' +
                '</select>' +
              '</div>' +
            '</div>' +
            '<label>简介</label>' +
            '<input type="text" name="description" maxlength="500" value="' + esc(a.description || '') + '">' +
            '<label>封面（留空表示不修改）</label>' +
            (a.cover_url ? '<div class="muted" style="font-size:12px;margin-bottom:6px">当前封面：<img class="thumb" src="' + esc(a.cover_url) + '" alt=""></div>' : '') +
            '<input type="file" name="cover" accept="image/jpeg,image/png,image/webp">' +
            '<div style="margin-top:12px">' +
              '<button type="submit">保存</button> ' +
              '<button type="button" class="ghost" data-act="cancel" data-id="' + a.id + '">取消</button>' +
            '</div>' +
          '</form>' +
        '</td>' +
      '</tr>';
  }

  async function loadAlbums() {
    tbody.innerHTML = '<tr><td colspan="5" class="muted">加载中…</td></tr>';

    try {
      const data = await api.get('/api/admin/albums');

      if (!data.items.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="muted">还没有' + esc(LEVELS.group) + '，先创建一个。</td></tr>';
        return;
      }

      tbody.innerHTML = data.items.map(rowHtml).join('');
      bindDrag();
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="5" class="muted">加载失败：' + esc(e.message) + '</td></tr>';
    }
  }

  function bindDrag() {
    window.bindReorder('tbody tr[draggable]', '/api/admin/albums/reorder', (movingId, targetRow) => ({
      album_id: movingId,
      target_id: targetRow ? Number(targetRow.dataset.id) : null
    }));
  }

  tbody.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;

    const act = btn.dataset.act;
    const id = btn.dataset.id;

    if (act === 'edit' || act === 'cancel') {
      window.toggleEdit('edit-album-' + id);
      return;
    }

    if (act === 'delete') {
      if (!confirm('确认移除该' + LEVELS.group + '？其下所有' + LEVELS.item + '及桶内文件会一并删除，不可恢复。')) return;
      btn.disabled = true;
      try {
        await api.del('/api/admin/albums/' + id);
        await loadAlbums();
      } catch (err) {
        alert('操作失败：' + err.message);
        btn.disabled = false;
      }
    }
  });

  tbody.addEventListener('submit', async (e) => {
    const form = e.target.closest('form[data-edit-id]');
    if (!form) return;
    e.preventDefault();

    const id = form.dataset.editId;
    const fd = new FormData(form);
    const hasFile = fd.get('cover') instanceof File && fd.get('cover').size > 0;

    // 未选封面时走 JSON PATCH；选了封面则必须 POST + _method=PATCH
    try {
      if (hasFile) {
        fd.append('_method', 'PATCH');
        await api.request('POST', '/api/admin/albums/' + id, fd);
      } else {
        await api.request('PATCH', '/api/admin/albums/' + id, {
          title: fd.get('title'),
          description: fd.get('description'),
          status: Number(fd.get('status'))
        });
      }
      window.adminUi.toast('已保存');
      await loadAlbums();
    } catch (err) {
      alert('保存失败：' + err.message);
    }
  });

  createForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const btn = document.getElementById('create-btn');
    btn.disabled = true;
    btn.textContent = '创建中…';

    try {
      await api.request('POST', '/api/admin/albums', new FormData(createForm));
      window.adminUi.toast(LEVELS.group + '已创建');
      createForm.reset();
      await loadAlbums();
    } catch (err) {
      window.adminUi.toast('创建失败：' + err.message, 'err');
    } finally {
      btn.disabled = false;
      btn.textContent = '创建';
    }
  });

  loadAlbums();
})();
</script>
@endsection
