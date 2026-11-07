@extends('admin.layouts.app')
@section('title', '专辑管理')

@section('content')
<h1>专辑管理</h1>

<div class="card">
  <h2>新建专辑</h2>
  <form method="POST" action="{{ route('admin.albums.store') }}" enctype="multipart/form-data">
    @csrf
    <label for="title">专辑名称</label>
    <input id="title" type="text" name="title" maxlength="120" required>
    <label for="description">简介（选填）</label>
    <input id="description" type="text" name="description" maxlength="500">
    <label for="cover">封面（选填，jpg/png/webp ≤5MB）</label>
    <input id="cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp">
    <div style="margin-top:14px"><button type="submit">创建</button></div>
  </form>
</div>

<div class="card">
  <h2>专辑列表（拖动行调整顺序，插到目标行之前）</h2>
  <table>
    <thead><tr><th style="width:60px">顺序</th><th style="width:60px">封面</th><th>名称</th><th style="width:90px">音频数</th><th style="width:140px">操作</th></tr></thead>
    <tbody>
      @forelse($albums as $album)
        <tr draggable="true" data-id="{{ $album->id }}">
          <td>{{ $album->sort_order }}</td>
          <td>@if($album->cover_url)<img class="thumb" src="{{ $album->cover_url }}" alt="">@endif</td>
          <td>{{ $album->title }}<div class="muted" style="font-size:12px">{{ $album->description }}</div></td>
          <td>{{ $album->tracks_count }}</td>
          <td><a class="btn ghost" href="{{ route('admin.tracks.index', ['album_id' => $album->id]) }}">查看音频</a></td>
        </tr>
      @empty
        <tr><td colspan="5" class="muted">还没有专辑，先创建一个。</td></tr>
      @endforelse
    </tbody>
  </table>
  <p class="muted" style="font-size:12px">拖到最后一行之后时，本实现仍按「插到目标之前」处理；如需移到末尾，请拖到目标行的上一行并刷新确认顺序。</p>
</div>
@endsection

@section('scripts')
<script>
window.bindReorder('tbody tr[draggable]', '{{ route('admin.albums.reorder') }}', (movingId, row) => ({
  album_id: movingId,
  target_id: Number(row.dataset.id)
}));
</script>
@endsection
