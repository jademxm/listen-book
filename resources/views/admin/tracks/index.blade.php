@extends('admin.layouts.app')
@php($group = $levels['group'])
@php($item = $levels['item'])
@section('title', $item.'管理')

@section('content')
<h1>{{ $item }}管理</h1>

<div class="card">
  <h2>上传{{ $item }}</h2>
  <form method="POST" action="{{ route('admin.tracks.store') }}" enctype="multipart/form-data" id="upload-form">
    @csrf
    <div class="row">
      <div>
        <label for="album_id">所属{{ $group }}</label>
        <select id="album_id" name="album_id" required>
          @foreach($albums as $album)
            <option value="{{ $album->id }}" @selected((string)request('album_id') === (string)$album->id)>{{ $album->title }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="title">{{ $item }}标题</label>
        <input id="title" type="text" name="title" maxlength="150" required>
      </div>
    </div>
    <label for="audio">{{ $item }}文件（mp3/m4a/aac/wav，≤100MB）</label>
    <input id="audio" type="file" name="audio" accept=".mp3,.m4a,.aac,.wav" required>
    <p class="muted" style="font-size:12px">服务端需允许 ≥128MB 请求体；若上传返回 413 Request Entity Too Large，说明 nginx / PHP 限制未放宽，见 README 第 3 节。</p>
    <label for="cover">封面（选填）</label>
    <input id="cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp">
    <input type="hidden" name="duration_sec" id="duration_sec" value="">
    <p class="muted" style="font-size:12px" id="duration-hint">选择{{ $item }}后会自动读取时长；未读取到则留空。</p>
    <div style="margin-top:14px"><button type="submit" id="upload-btn">上传并上架</button></div>
  </form>
</div>

<div class="card">
  <h2>筛选</h2>
  <form method="GET" action="{{ route('admin.tracks.index') }}" class="row">
    <div>
      <label for="filter_album">{{ $group }}</label>
      <select id="filter_album" name="album_id">
        <option value="">全部</option>
        @foreach($albums as $album)
          <option value="{{ $album->id }}" @selected((string)request('album_id') === (string)$album->id)>{{ $album->title }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label for="keyword">标题关键字</label>
      <input id="keyword" type="text" name="keyword" value="{{ request('keyword') }}">
    </div>
    <div><button type="submit">筛选</button></div>
  </form>
</div>

<div class="card">
  <h2>{{ $item }}列表（同一{{ $group }}内拖动行排序：落在行的上半部分插到其之前，下半部分插到其之后）</h2>
  <table>
    <thead><tr><th style="width:56px">顺序</th><th style="width:140px">{{ $group }}</th><th style="width:56px">封面</th><th>标题</th><th style="width:80px">时长</th><th style="width:200px">试听</th><th style="width:220px">操作</th></tr></thead>
    <tbody>
      @forelse($tracks as $track)
        <tr draggable="true" data-id="{{ $track->id }}" data-album-id="{{ $track->album_id }}">
          <td>{{ $track->sort_order }}</td>
          <td class="muted">{{ optional($track->album)->title ?? ($group.' '.$track->album_id) }}</td>
          <td>@if($track->cover_url)<img class="thumb" src="{{ $track->cover_url }}" alt="">@endif</td>
          <td>{{ $track->title }}<div class="muted" style="font-size:12px">#{{ $track->id }} · {{ $track->status ? '已上架' : '已下架' }}</div></td>
          <td>{{ $track->duration_sec ? sprintf('%02d:%02d', floor($track->duration_sec / 60), $track->duration_sec % 60) : '--:--' }}</td>
          <td><audio controls preload="none" src="{{ $track->url }}"></audio></td>
          <td>
            <form method="POST" action="{{ route('admin.tracks.update', $track) }}" class="inline">@csrf @method('PATCH')
              <input type="hidden" name="status" value="{{ $track->status ? 0 : 1 }}">
              <button class="ghost">{{ $track->status ? '下架' : '上架' }}</button>
            </form>
            <form method="POST" action="{{ route('admin.tracks.destroy', $track) }}" class="inline" onsubmit="return confirm('确认移除该{{ $item }}？桶内文件与封面会一并删除，不可恢复。')">@csrf @method('DELETE')
              <button class="danger">移除</button>
            </form>
            <button type="button" class="ghost" onclick="toggleEdit('edit-track-{{ $track->id }}')">编辑</button>
          </td>
        </tr>
        <tr class="edit-row" id="edit-track-{{ $track->id }}" style="display:none">
          <td colspan="7">
            <form method="POST" action="{{ route('admin.tracks.update', $track) }}" enctype="multipart/form-data" class="edit-form">
              @csrf @method('PATCH')
              <label>{{ $item }}标题</label>
              <input type="text" name="title" maxlength="150" value="{{ $track->title }}" required>
              <label>封面（留空表示不修改）</label>
              @if($track->cover_url)<div class="muted" style="font-size:12px;margin-bottom:6px">当前封面：<img class="thumb" src="{{ $track->cover_url }}" alt=""></div>@endif
              <input type="file" name="cover" accept="image/jpeg,image/png,image/webp">
              <div style="margin-top:12px">
                <button type="submit">保存</button>
                <button type="button" class="ghost" onclick="toggleEdit('edit-track-{{ $track->id }}')">取消</button>
              </div>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="muted">暂无{{ $item }}。</td></tr>
      @endforelse
    </tbody>
  </table>
  <div style="margin-top:16px">{{ $tracks->links() }}</div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  // 层名统一从 config/content.php 下发，避免 JS 里再写一遍中文
  const LEVELS = @json($levels);
  const audioInput = document.getElementById('audio');
  const durationInput = document.getElementById('duration_sec');
  const hint = document.getElementById('duration-hint');
  const MAX_AUDIO_BYTES = 100 * 1024 * 1024; // 与 StoreTrackRequest 的 max:102400（KB）对齐
  let tooLarge = false;

  audioInput.addEventListener('change', () => {
    const file = audioInput.files && audioInput.files[0];
    durationInput.value = '';
    tooLarge = false;
    if (!file) return;
    if (file.size > MAX_AUDIO_BYTES) {
      tooLarge = true;
      hint.textContent = '文件 ' + (file.size / 1024 / 1024).toFixed(1) + 'MB 超过 100MB 上限，请压缩后再上传。';
      return;
    }
    const url = URL.createObjectURL(file);
    const probe = new Audio();
    probe.preload = 'metadata';
    probe.onloadedmetadata = () => {
      if (Number.isFinite(probe.duration) && probe.duration > 0) {
        durationInput.value = Math.round(probe.duration);
        hint.textContent = '已读取时长：' + Math.floor(probe.duration / 60) + ' 分 ' + Math.round(probe.duration % 60) + ' 秒';
      } else {
        hint.textContent = '未能读取时长，将留空（小程序显示 --:--）。';
      }
      URL.revokeObjectURL(url);
    };
    probe.onerror = () => { hint.textContent = '浏览器无法解析该' + LEVELS.item + '，时长将留空。'; URL.revokeObjectURL(url); };
    probe.src = url;
  });

  document.getElementById('upload-form').addEventListener('submit', (e) => {
    if (tooLarge) {
      e.preventDefault();
      audioInput.focus();
      return;
    }
    const btn = document.getElementById('upload-btn');
    btn.disabled = true;
    btn.textContent = '上传中…';
  });
})();

window.bindReorder('tbody tr[draggable]', '{{ route('admin.tracks.reorder') }}', (movingId, targetRow, movingRow) => ({
  album_id: Number(movingRow.dataset.albumId),
  track_id: movingId,
  target_id: targetRow ? Number(targetRow.dataset.id) : null
}), {
  sameGroup: (a, b) => a.dataset.albumId === b.dataset.albumId,
  groupHint: LEVELS.item + '只能在同一个' + LEVELS.group + '内排序。'
});
</script>
@endsection
