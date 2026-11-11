@extends('admin.layouts.app')
@php($group = $levels['group'])
@php($item = $levels['item'])
@section('title', $item.'管理')

@section('content')
<h1>{{ $item }}管理</h1>

<div class="card">
  <h2>上传{{ $item }}</h2>
  <form id="upload-form" enctype="multipart/form-data">
    <div class="row">
      <div>
        <label for="upload-album">所属{{ $group }}</label>
        <select id="upload-album" name="album_id" required></select>
      </div>
      <div>
        <label for="upload-title">{{ $item }}标题（单个文件时填写）</label>
        <input id="upload-title" type="text" name="title" maxlength="150" placeholder="留空则用文件名；批量时按文件名自动命名">
      </div>
    </div>
    <label for="audio">{{ $item }}文件（mp3/m4a/aac/wav，≤100MB，可多选）</label>
    <input id="audio" type="file" name="audio" accept=".mp3,.m4a,.aac,.wav" multiple>
    <p class="muted" style="font-size:12px">批量上传按顺序逐个提交，一次只传一个文件；服务端需允许 ≥128MB 请求体，若返回 413 说明 nginx / PHP 限制未放宽，见 README 第 3 节。</p>
    <label for="cover">封面（选填，仅单个文件时生效）</label>
    <input id="cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp">
    <input type="hidden" name="duration_sec" id="duration_sec" value="">
    <p class="muted" style="font-size:12px" id="duration-hint">选择{{ $item }}后会自动读取时长；未读取到则留空。</p>
    <div style="margin-top:14px"><button type="submit" id="upload-btn">上传并上架</button></div>
    <div id="upload-progress" class="progress" style="display:none"><i id="upload-progress-bar"></i></div>
    <div id="upload-progress-text" class="progress-text" style="display:none"></div>
    <div id="upload-queue" class="upload-queue" style="display:none">
      <ul id="upload-queue-list"></ul>
      <button type="button" class="ghost" id="retry-btn" style="display:none">重试失败项</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>筛选</h2>
  <form id="filter-form" class="row">
    <div>
      <label for="filter-album">{{ $group }}</label>
      <select id="filter-album"><option value="">全部</option></select>
    </div>
    <div>
      <label for="filter-keyword">标题关键字</label>
      <input id="filter-keyword" type="text">
    </div>
    <div><button type="submit">筛选</button></div>
  </form>
</div>

<div class="card">
  <h2>{{ $item }}列表（同一{{ $group }}内拖动行排序：落在行的上半部分插到其之前，下半部分插到其之后）</h2>
  <table>
    <thead><tr><th style="width:56px">顺序</th><th style="width:140px">{{ $group }}</th><th style="width:56px">封面</th><th>标题</th><th style="width:80px">时长</th><th style="width:200px">试听</th><th style="width:220px">操作</th></tr></thead>
    <tbody id="track-rows">
      <tr><td colspan="7" class="muted">加载中…</td></tr>
    </tbody>
  </table>
  <div class="pager" id="pager"></div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  if (!window.requireAdmin()) return;

  const LEVELS = @json($levels);
  const esc = window.adminUi.escapeHtml;
  const fmt = window.adminUi.formatDuration;
  const api = window.adminApi;

  const tbody = document.getElementById('track-rows');
  const pager = document.getElementById('pager');
  const uploadForm = document.getElementById('upload-form');
  const filterForm = document.getElementById('filter-form');
  const uploadAlbum = document.getElementById('upload-album');
  const filterAlbum = document.getElementById('filter-album');
  const filterKeyword = document.getElementById('filter-keyword');

  const state = { page: 1, albumId: '', keyword: '' };
  let albumOptions = [];

  function albumSelect(selected) {
    return '<label>所属' + esc(LEVELS.group) + '</label>' +
      '<select name="album_id">' + albumOptions.map(a =>
        '<option value="' + a.id + '"' + (Number(a.id) === Number(selected) ? ' selected' : '') + '>' + esc(a.title) + '</option>'
      ).join('') + '</select>';
  }

  // 支持从「专辑」页带 ?album_id= 直接跳到该专辑下的音频
  (function initFromQuery() {
    const params = new URLSearchParams(location.search);
    const albumId = params.get('album_id');
    const keyword = params.get('keyword');
    if (albumId) state.albumId = albumId;
    if (keyword) state.keyword = keyword;
  })();

  let filterAlbumSynced = false;

  function fillAlbumOptions(albums) {
    const uploadValue = uploadAlbum.value;
    const filterValue = filterAlbum.value;
    albumOptions = albums || [];

    uploadAlbum.innerHTML = albums.map(a =>
      '<option value="' + a.id + '">' + esc(a.title) + '</option>'
    ).join('');

    filterAlbum.innerHTML = '<option value="">全部</option>' + albums.map(a =>
      '<option value="' + a.id + '">' + esc(a.title) + '</option>'
    ).join('');

    // 重渲染后尽量保持用户选择
    if (uploadValue) uploadAlbum.value = uploadValue;
    if (filterValue) filterAlbum.value = filterValue;

    // 首次加载时把 URL 里的 album_id 同步到筛选框
    if (!filterAlbumSynced) {
      filterAlbumSynced = true;
      if (state.albumId) filterAlbum.value = state.albumId;
      if (state.keyword) filterKeyword.value = state.keyword;
    }
  }

  function rowHtml(t) {
    const cover = t.cover_url ? '<img class="thumb" src="' + esc(t.cover_url) + '" alt="">' : '';
    const albumTitle = t.album_title || (LEVELS.group + ' ' + t.album_id);

    return '' +
      '<tr draggable="true" data-id="' + t.id + '" data-album-id="' + t.album_id + '">' +
        '<td>' + t.sort_order + '</td>' +
        '<td class="muted">' + esc(albumTitle) + '</td>' +
        '<td>' + cover + '</td>' +
        '<td>' + esc(t.title) +
          '<div class="muted" style="font-size:12px">#' + t.id + ' · ' + (t.status ? '已上架' : '已下架') + '</div>' +
        '</td>' +
        '<td>' + fmt(t.duration_sec) + '</td>' +
        '<td>' + (t.url ? '<audio controls preload="none" src="' + esc(t.url) + '"></audio>' : '') + '</td>' +
        '<td>' +
          '<button type="button" class="ghost" data-act="toggle" data-id="' + t.id + '" data-status="' + (t.status ? 0 : 1) + '">' +
            (t.status ? '下架' : '上架') +
          '</button> ' +
          '<button type="button" class="danger" data-act="delete" data-id="' + t.id + '">移除</button> ' +
          '<button type="button" class="ghost" data-act="edit" data-id="' + t.id + '">编辑</button>' +
        '</td>' +
      '</tr>' +
      '<tr class="edit-row" id="edit-track-' + t.id + '" style="display:none">' +
        '<td colspan="7">' +
          '<form class="edit-form" data-edit-id="' + t.id + '" enctype="multipart/form-data">' +
            albumSelect(t.album_id) +
            '<label>' + esc(LEVELS.item) + '标题</label>' +
            '<input type="text" name="title" maxlength="150" value="' + esc(t.title) + '" required>' +
            '<label>封面（留空表示不修改）</label>' +
            (t.cover_url ? '<div class="muted" style="font-size:12px;margin-bottom:6px">当前封面：<img class="thumb" src="' + esc(t.cover_url) + '" alt=""></div>' : '') +
            '<input type="file" name="cover" accept="image/jpeg,image/png,image/webp">' +
            '<div style="margin-top:12px">' +
              '<button type="submit">保存</button> ' +
              '<button type="button" class="ghost" data-act="cancel" data-id="' + t.id + '">取消</button>' +
            '</div>' +
          '</form>' +
        '</td>' +
      '</tr>';
  }

  function renderPager(p) {
    if (!p || p.last_page <= 1) { pager.innerHTML = ''; return; }
    pager.innerHTML =
      '<button type="button" class="ghost" data-page="' + (p.current_page - 1) + '"' + (p.current_page <= 1 ? ' disabled' : '') + '>上一页</button>' +
      '<span class="muted">第 ' + p.current_page + ' / ' + p.last_page + ' 页，共 ' + p.total + ' 条</span>' +
      '<button type="button" class="ghost" data-page="' + (p.current_page + 1) + '"' + (p.current_page >= p.last_page ? ' disabled' : '') + '>下一页</button>';
  }

  async function loadTracks() {
    const params = new URLSearchParams({ page: String(state.page) });
    if (state.albumId) params.set('album_id', state.albumId);
    if (state.keyword) params.set('keyword', state.keyword);

    tbody.innerHTML = '<tr><td colspan="7" class="muted">加载中…</td></tr>';

    try {
      const data = await api.get('/api/admin/tracks?' + params.toString());
      fillAlbumOptions(data.albums || []);

      if (!data.items.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="muted">暂无' + esc(LEVELS.item) + '。</td></tr>';
      } else {
        tbody.innerHTML = data.items.map(rowHtml).join('');
        bindDrag();
      }

      renderPager(data.pagination);
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="7" class="muted">加载失败：' + esc(e.message) + '</td></tr>';
    }
  }

  function bindDrag() {
    window.bindReorder('tbody tr[draggable]', '/api/admin/tracks/reorder', (movingId, targetRow, movingRow) => ({
      album_id: Number(movingRow.dataset.albumId),
      track_id: movingId,
      target_id: targetRow ? Number(targetRow.dataset.id) : null
    }), {
      sameGroup: (a, b) => a.dataset.albumId === b.dataset.albumId,
      groupHint: LEVELS.item + '只能在同一个' + LEVELS.group + '内排序。'
    });
  }

  // 行内操作：上下架 / 移除 / 编辑 / 取消
  tbody.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;

    const act = btn.dataset.act;
    const id = btn.dataset.id;

    if (act === 'cancel') {
      window.toggleEdit('edit-track-' + id);
      return;
    }

    if (act === 'edit') {
      window.toggleEdit('edit-track-' + id);
      return;
    }

    if (act === 'toggle') {
      btn.disabled = true;
      try {
        await api.request('PATCH', '/api/admin/tracks/' + id, { status: Number(btn.dataset.status) });
        await loadTracks();
      } catch (err) {
        alert('操作失败：' + err.message);
        btn.disabled = false;
      }
      return;
    }

    if (act === 'delete') {
      if (!confirm('确认移除该' + LEVELS.item + '？桶内文件与封面会一并删除，不可恢复。')) return;
      btn.disabled = true;
      try {
        await api.del('/api/admin/tracks/' + id);
        await loadTracks();
      } catch (err) {
        alert('操作失败：' + err.message);
        btn.disabled = false;
      }
    }
  });

  // 行内编辑保存：带封面时用 POST + _method=PATCH（PHP 不解析 PATCH 的 multipart body）
  tbody.addEventListener('submit', async (e) => {
    const form = e.target.closest('form[data-edit-id]');
    if (!form) return;
    e.preventDefault();

    const id = form.dataset.editId;
    const fd = new FormData(form);
    const hasFile = fd.get('cover') instanceof File && fd.get('cover').size > 0;

    try {
      if (hasFile) {
        fd.append('_method', 'PATCH');
        await api.request('POST', '/api/admin/tracks/' + id, fd);
      } else {
        await api.request('PATCH', '/api/admin/tracks/' + id, {
          title: fd.get('title'),
          album_id: Number(fd.get('album_id'))
        });
      }
      await loadTracks();
    } catch (err) {
      alert('保存失败：' + err.message);
    }
  });

  pager.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-page]');
    if (!btn || btn.disabled) return;
    state.page = Number(btn.dataset.page);
    loadTracks();
  });

  filterForm.addEventListener('submit', (e) => {
    e.preventDefault();
    state.albumId = filterAlbum.value;
    state.keyword = filterKeyword.value.trim();
    state.page = 1;
    loadTracks();
  });

  // 上传：单文件 / 批量共用 XHR 进度条，文件按顺序串行提交，一次请求只带一个音频
  const audioInput = document.getElementById('audio');
  const durationInput = document.getElementById('duration_sec');
  const hint = document.getElementById('duration-hint');
  const titleInput = document.getElementById('upload-title');
  const coverInput = document.getElementById('cover');
  const uploadBtn = document.getElementById('upload-btn');
  const retryBtn = document.getElementById('retry-btn');
  const progressBox = document.getElementById('upload-progress');
  const progressBar = document.getElementById('upload-progress-bar');
  const progressText = document.getElementById('upload-progress-text');
  const queueBox = document.getElementById('upload-queue');
  const queueList = document.getElementById('upload-queue-list');
  const MAX_AUDIO_BYTES = 100 * 1024 * 1024; // 与 StoreTrackRequest 的 max:102400（KB）对齐
  let tooLarge = false;
  let queue = [];

  function stripExt(name) {
    return (String(name).replace(/\.[^.]+$/, '').trim() || String(name)).slice(0, 150);
  }

  function probeDuration(file) {
    return new Promise((resolve) => {
      const url = URL.createObjectURL(file);
      const probe = new Audio();
      let settled = false;
      let timer = null;
      const done = (value) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        URL.revokeObjectURL(url);
        resolve(value);
      };
      timer = setTimeout(() => done(''), 3000); // 损坏文件不阻塞整批
      probe.preload = 'metadata';
      probe.onloadedmetadata = () => done(
        Number.isFinite(probe.duration) && probe.duration > 0 ? Math.round(probe.duration) : ''
      );
      probe.onerror = () => done('');
      probe.src = url;
    });
  }

  function buildQueue(files) {
    const single = files.length === 1;
    queue = files.map((file) => ({
      file: file,
      title: single && titleInput.value.trim() ? titleInput.value.trim() : stripExt(file.name),
      duration: single ? durationInput.value : '',
      status: 'pending',
      message: ''
    }));
    renderQueue();
  }

  function renderQueue() {
    queueList.innerHTML = queue.map((q, i) => {
      const text = q.status === 'ok' ? '完成'
        : q.status === 'err' ? '失败：' + q.message
        : q.status === 'running' ? '上传中…' : '等待中';
      const cls = q.status === 'ok' ? 'ok' : q.status === 'err' ? 'err' : '';
      return '<li class="' + cls + '">' + (i + 1) + '. ' + esc(q.title) + ' — ' + esc(text) + '</li>';
    }).join('');
    queueBox.style.display = queue.length ? '' : 'none';
    retryBtn.style.display = queue.some(q => q.status === 'err') ? '' : 'none';
  }

  function setProgress(done, fraction, total) {
    const pct = total ? Math.min(100, Math.round((done + fraction) / total * 100)) : 0;
    progressBar.style.width = pct + '%';
    progressText.textContent = '已完成 ' + done + ' / ' + total + '（' + pct + '%）';
    progressBox.style.display = '';
    progressText.style.display = '';
  }

  audioInput.addEventListener('change', () => {
    const files = Array.from(audioInput.files || []);
    durationInput.value = '';
    tooLarge = false;
    if (!files.length) {
      hint.textContent = '选择' + LEVELS.item + '后会自动读取时长；未读取到则留空。';
      return;
    }

    const oversized = files.filter(f => f.size > MAX_AUDIO_BYTES);
    if (oversized.length) {
      tooLarge = true;
      hint.textContent = '有 ' + oversized.length + ' 个文件超过 100MB 上限，请压缩后重新选择。';
      return;
    }

    buildQueue(files);
    if (files.length > 1) {
      hint.textContent = '已选择 ' + files.length + ' 个文件，标题按文件名自动生成，将按顺序逐个上传。';
      return;
    }

    // 单文件：沿用原有的元数据探测，显示时长提示
    const url = URL.createObjectURL(files[0]);
    const probe = new Audio();
    probe.preload = 'metadata';
    probe.onloadedmetadata = () => {
      if (Number.isFinite(probe.duration) && probe.duration > 0) {
        durationInput.value = Math.round(probe.duration);
        if (queue[0]) queue[0].duration = durationInput.value;
        hint.textContent = '已读取时长：' + Math.floor(probe.duration / 60) + ' 分 ' + Math.round(probe.duration % 60) + ' 秒';
      } else {
        hint.textContent = '未能读取时长，将留空（小程序显示 --:--）。';
      }
      URL.revokeObjectURL(url);
    };
    probe.onerror = () => {
      hint.textContent = '浏览器无法解析该' + LEVELS.item + '，时长将留空。';
      URL.revokeObjectURL(url);
    };
    probe.src = url;
  });

  async function runQueue(onlyFailed) {
    if (!uploadAlbum.value) { alert('请先创建' + LEVELS.group + '。'); return; }
    const targets = queue.filter(q => onlyFailed ? q.status === 'err' : q.status !== 'ok');
    if (!targets.length) return;

    uploadBtn.disabled = true;
    retryBtn.disabled = true;
    uploadBtn.textContent = '上传中…';
    let done = queue.length - targets.length;

    for (const q of targets) {
      q.status = 'running';
      renderQueue();
      setProgress(done, 0, queue.length);

      try {
        if (!q.duration) q.duration = await probeDuration(q.file);
        const fd = new FormData();
        fd.append('album_id', uploadAlbum.value);
        fd.append('title', q.title);
        fd.append('audio', q.file);
        if (q.duration) fd.append('duration_sec', String(q.duration));
        const coverFile = coverInput.files && coverInput.files[0];
        if (queue.length === 1 && coverFile && coverFile.size > 0) fd.append('cover', coverFile);

        await api.upload('/api/admin/tracks', fd, (loaded, total) => {
          setProgress(done, total ? loaded / total : 0, queue.length);
        });
        q.status = 'ok';
      } catch (err) {
        q.status = 'err';
        q.message = err.message || '未知错误';
      }
      done++;
      renderQueue();
      setProgress(done, 0, queue.length);
    }

    const ok = queue.filter(q => q.status === 'ok').length;
    const failed = queue.filter(q => q.status === 'err').length;
    window.adminUi.toast(
      '上传完成：成功 ' + ok + ' 个' + (failed ? '，失败 ' + failed + ' 个（可点「重试失败项」）' : ''),
      failed ? 'err' : 'ok'
    );

    uploadBtn.disabled = false;
    retryBtn.disabled = false;
    uploadBtn.textContent = '上传并上架';
    if (!failed) {
      queue = [];
      renderQueue();
      uploadForm.reset();
      durationInput.value = '';
      hint.textContent = '选择' + LEVELS.item + '后会自动读取时长；未读取到则留空。';
    }
    state.page = 1;
    await loadTracks();
  }

  uploadForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (tooLarge) { audioInput.focus(); return; }
    if (!queue.length) { hint.textContent = '请选择' + LEVELS.item + '文件。'; return; }
    await runQueue(false);
  });

  retryBtn.addEventListener('click', () => runQueue(true));

  loadTracks();
})();
</script>
@endsection
