@extends('admin.layouts.app')
@section('title', '音频管理')

@section('content')
<h1>音频管理</h1>

<div class="card">
  <h2>上传音频</h2>
  <form method="POST" action="{{ route('admin.tracks.store') }}" enctype="multipart/form-data" id="upload-form">
    @csrf
    <div class="row">
      <div>
        <label for="album_id">所属专辑</label>
        <select id="album_id" name="album_id" required>
          @foreach($albums as $album)
            <option value="{{ $album->id }}" @selected((string)request('album_id') === (string)$album->id)>{{ $album->title }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="title">音频标题</label>
        <input id="title" type="text" name="title" maxlength="150" required>
      </div>
    </div>
    <label for="audio">音频文件（mp3/m4a/aac/wav，≤100MB）</label>
    <input id="audio" type="file" name="audio" accept=".mp3,.m4a,.aac,.wav" required>
    <label for="cover">封面（选填）</label>
    <input id="cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp">
    <input type="hidden" name="duration_sec" id="duration_sec" value="">
    <p class="muted" style="font-size:12px" id="duration-hint">选择音频后会自动读取时长；未读取到则留空。</p>
    <div style="margin-top:14px"><button type="submit" id="upload-btn">上传并上架</button></div>
  </form>
</div>

<div class="card">
  <h2>筛选</h2>
  <form method="GET" action="{{ route('admin.tracks.index') }}" class="row">
    <div>
      <label for="filter_album">专辑</label>
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
  <h2>音频列表（同一专辑内拖动行排序，插到目标行之前）</h2>
  @foreach($tracks->getCollection()->groupBy('album_id') as $albumId => $group)
    <h3 style="font-size:14px;margin:16px 0 8px">{{ optional($group->first()->album)->title ?? ('专辑 '.$albumId) }}</h3>
    <table>
      <thead><tr><th style="width:60px">顺序</th><th style="width:60px">封面</th><th>标题</th><th style="width:90px">时长</th><th style="width:240px">试听</th><th style="width:220px">操作</th></tr></thead>
      <tbody data-album-id="{{ $albumId }}">
        @foreach($group as $track)
          <tr draggable="true" data-id="{{ $track->id }}">
            <td>{{ $track->sort_order }}</td>
            <td>@if($track->cover_url)<img class="thumb" src="{{ $track->cover_url }}" alt="">@endif</td>
            <td>{{ $track->title }}<div class="muted" style="font-size:12px">#{{ $track->id }} · {{ $track->status ? '已上架' : '已下架' }}</div></td>
            <td>{{ $track->duration_sec ? sprintf('%02d:%02d', floor($track->duration_sec / 60), $track->duration_sec % 60) : '--:--' }}</td>
            <td><audio controls preload="none" src="{{ $track->url }}"></audio></td>
            <td>
              <form method="POST" action="{{ route('admin.tracks.update', $track) }}" class="inline">@csrf @method('PATCH')
                <input type="hidden" name="status" value="{{ $track->status ? 0 : 1 }}">
                <button class="ghost">{{ $track->status ? '下架' : '上架' }}</button>
              </form>
              <form method="POST" action="{{ route('admin.tracks.destroy', $track) }}" class="inline" onsubmit="return confirm('确认移除该音频？')">@csrf @method('DELETE')
                <button class="danger">移除</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endforeach
  @if($tracks->isEmpty())<p class="muted">暂无音频。</p>@endif
  <div style="margin-top:16px">{{ $tracks->links() }}</div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const audioInput = document.getElementById('audio');
  const durationInput = document.getElementById('duration_sec');
  const hint = document.getElementById('duration-hint');

  audioInput.addEventListener('change', () => {
    const file = audioInput.files && audioInput.files[0];
    durationInput.value = '';
    if (!file) return;
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
    probe.onerror = () => { hint.textContent = '浏览器无法解析该音频，时长将留空。'; URL.revokeObjectURL(url); };
    probe.src = url;
  });

  document.getElementById('upload-form').addEventListener('submit', () => {
    const btn = document.getElementById('upload-btn');
    btn.disabled = true;
    btn.textContent = '上传中…';
  });
})();

window.bindReorder('tbody[data-album-id] tr[draggable]', '{{ route('admin.tracks.reorder') }}', (movingId, row) => ({
  album_id: Number(row.closest('tbody').dataset.albumId),
  track_id: movingId,
  target_id: Number(row.dataset.id)
}));
</script>
@endsection
