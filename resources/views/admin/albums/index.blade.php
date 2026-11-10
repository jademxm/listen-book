@extends('admin.layouts.app')
@php($group = $levels['group'])
@php($item = $levels['item'])
@section('title', $group.'管理')

@section('content')
<h1>{{ $group }}管理</h1>

<div class="card">
  <h2>新建{{ $group }}</h2>
  <form method="POST" action="{{ route('admin.albums.store') }}" enctype="multipart/form-data">
    @csrf
    <label for="title">{{ $group }}名称</label>
    <input id="title" type="text" name="title" maxlength="120" required>
    <label for="description">简介（选填）</label>
    <input id="description" type="text" name="description" maxlength="500">
    <label for="cover">封面（选填，jpg/png/webp ≤5MB）</label>
    <input id="cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp">
    <div style="margin-top:14px"><button type="submit">创建</button></div>
  </form>
</div>

<div class="card">
  <h2>{{ $group }}列表（拖动行调整顺序：落在行的上半部分插到其之前，下半部分插到其之后）</h2>
  <table>
    <thead><tr><th style="width:60px">顺序</th><th style="width:60px">封面</th><th>名称</th><th style="width:90px">上架{{ $item }}</th><th style="width:220px">操作</th></tr></thead>
    <tbody>
      @forelse($albums as $album)
        <tr draggable="true" data-id="{{ $album->id }}">
          <td>{{ $album->sort_order }}</td>
          <td>@if($album->cover_url)<img class="thumb" src="{{ $album->cover_url }}" alt="">@endif</td>
          <td>{{ $album->title }}<div class="muted" style="font-size:12px">{{ $album->description }}</div></td>
          <td>{{ $album->tracks_count }}</td>
          <td>
            <a class="btn ghost" href="{{ route('admin.tracks.index', ['album_id' => $album->id]) }}">查看{{ $item }}</a>
            <button type="button" class="ghost" onclick="toggleEdit('edit-album-{{ $album->id }}')">编辑</button>
            <form method="POST" action="{{ route('admin.albums.destroy', $album) }}" class="inline" onsubmit="return confirm('确认移除该{{ $group }}？其下所有{{ $item }}及桶内文件会一并删除，不可恢复。')">@csrf @method('DELETE')
              <button class="danger">移除</button>
            </form>
            <div class="muted" style="font-size:12px">{{ $album->status ? '已上架' : '已下架' }}</div>
          </td>
        </tr>
        <tr class="edit-row" id="edit-album-{{ $album->id }}" style="display:none">
          <td colspan="5">
            <form method="POST" action="{{ route('admin.albums.update', $album) }}" enctype="multipart/form-data" class="edit-form">
              @csrf @method('PATCH')
              <div class="row">
                <div>
                  <label>{{ $group }}名称</label>
                  <input type="text" name="title" maxlength="120" value="{{ $album->title }}" required>
                </div>
                <div>
                  <label>状态</label>
                  <select name="status">
                    <option value="1" @selected($album->status === 1)>已上架</option>
                    <option value="0" @selected($album->status === 0)>已下架</option>
                  </select>
                </div>
              </div>
              <label>简介</label>
              <input type="text" name="description" maxlength="500" value="{{ $album->description }}">
              <label>封面（留空表示不修改）</label>
              @if($album->cover_url)<div class="muted" style="font-size:12px;margin-bottom:6px">当前封面：<img class="thumb" src="{{ $album->cover_url }}" alt=""></div>@endif
              <input type="file" name="cover" accept="image/jpeg,image/png,image/webp">
              <div style="margin-top:12px">
                <button type="submit">保存</button>
                <button type="button" class="ghost" onclick="toggleEdit('edit-album-{{ $album->id }}')">取消</button>
              </div>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="5" class="muted">还没有{{ $group }}，先创建一个。</td></tr>
      @endforelse
    </tbody>
  </table>
  <p class="muted" style="font-size:12px">拖动后按落点插入：落在行的下半部分即插到该行之后，落在最后一行下半部分即移到末尾。{{ $item }}数为「已上架」数量，与小程序端显示一致。</p>
</div>
@endsection

@section('scripts')
<script>
window.bindReorder('tbody tr[draggable]', '{{ route('admin.albums.reorder') }}', (movingId, targetRow) => ({
  album_id: movingId,
  target_id: targetRow ? Number(targetRow.dataset.id) : null
}));
</script>
@endsection
