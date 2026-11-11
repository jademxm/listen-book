@extends('admin.layouts.app')
@php($group = $levels['group'])
@php($item = $levels['item'])
@section('title', '数据面板')

@section('content')
<h1>数据面板</h1>

<div class="card">
  <form id="filter-form" class="row">
    <div>
      <label for="f-days">统计范围</label>
      <select id="f-days" name="days">
        <option value="7">近 7 天</option>
        <option value="14">近 14 天</option>
        <option value="30" selected>近 30 天</option>
        <option value="90">近 90 天</option>
      </select>
    </div>
    <div><button type="submit">刷新</button></div>
  </form>
  <p class="muted" style="font-size:12px">
    收听时长与听人数按所选时间窗（北京时间自然日），听完集数为累计；归属按曲目当前所属{{ $group }}。
    不展示「播放次数」：播放历史是覆盖式记录、只保留最后一次，现有数据算不出真实次数；此处只统计听人数、有效收听秒数、听完集数。
  </p>
</div>

<div class="card">
  <h2>概览</h2>
  <div class="stat-cards" id="stat-cards"><span class="muted">加载中…</span></div>
</div>

<div class="card">
  <h2>每日有效收听时长</h2>
  <div class="bars" id="trend-bars"></div>
  <p class="muted" style="font-size:12px" id="trend-hint"></p>
</div>

<div class="card">
  <h2>{{ $group }}榜（含零收听，按有效收听时长排序）</h2>
  <table>
    <thead><tr><th>{{ $group }}</th><th style="width:110px">听人数</th><th style="width:150px">有效收听</th><th style="width:180px">听完集数 / 上架集数</th></tr></thead>
    <tbody id="album-rows"><tr><td colspan="4" class="muted">加载中…</td></tr></tbody>
  </table>
</div>

<div class="card">
  <h2>{{ $item }}榜（前 20）</h2>
  <table>
    <thead><tr><th>{{ $item }}</th><th style="width:110px">听人数</th><th style="width:150px">有效收听</th></tr></thead>
    <tbody id="track-rows"><tr><td colspan="3" class="muted">加载中…</td></tr></tbody>
  </table>
</div>
@endsection

@section('scripts')
<script>
(function () {
  if (!window.requireAdmin()) return;

  const LEVELS = @json($levels);
  const esc = window.adminUi.escapeHtml;
  const api = window.adminApi;
  const daysSel = document.getElementById('f-days');

  function humanSec(sec) {
    sec = Math.max(0, Math.floor(Number(sec) || 0));
    if (sec >= 3600) return Math.floor(sec / 3600) + ' 小时 ' + Math.floor(sec % 3600 / 60) + ' 分';
    if (sec >= 60) return Math.floor(sec / 60) + ' 分 ' + (sec % 60) + ' 秒';
    return sec + ' 秒';
  }

  function renderCards(o) {
    const items = [
      ['小程序用户数', o.users],
      ['上架' + LEVELS.group, o.published_albums],
      ['上架' + LEVELS.item, o.published_tracks],
      ['累计有效收听', humanSec(o.total_listen_sec)],
      ['近 ' + daysSel.value + ' 天活跃用户', o.active_users]
    ];
    document.getElementById('stat-cards').innerHTML = items.map(pair =>
      '<div><span class="muted">' + esc(pair[0]) + '</span><strong>' + esc(pair[1]) + '</strong></div>'
    ).join('');
  }

  function renderTrend(trend) {
    const max = Math.max(1, ...trend.map(d => d.listen_sec));
    document.getElementById('trend-bars').innerHTML = trend.map(d => {
      const pct = Math.max(2, Math.round(d.listen_sec / max * 100));
      const title = d.date + '：' + humanSec(d.listen_sec) + '，' + d.users + ' 人';
      return '<span style="height:' + pct + '%" title="' + esc(title) + '"></span>';
    }).join('');
  }

  function renderAlbums(rows) {
    const el = document.getElementById('album-rows');
    if (!rows.length) {
      el.innerHTML = '<tr><td colspan="4" class="muted">暂无上架' + esc(LEVELS.group) + '。</td></tr>';
      return;
    }
    el.innerHTML = rows.map(r =>
      '<tr><td>' + esc(r.title) + '</td><td>' + r.listeners + '</td><td>' + esc(humanSec(r.listen_sec)) +
      '</td><td>' + r.finished_tracks + ' / ' + r.published_tracks + '</td></tr>'
    ).join('');
  }

  function renderTracks(rows) {
    const el = document.getElementById('track-rows');
    if (!rows.length) {
      el.innerHTML = '<tr><td colspan="3" class="muted">所选时间窗内暂无收听数据。</td></tr>';
      return;
    }
    el.innerHTML = rows.map(r =>
      '<tr><td>' + esc(r.title) + '</td><td>' + r.listeners + '</td><td>' + esc(humanSec(r.listen_sec)) + '</td></tr>'
    ).join('');
  }

  async function load() {
    document.getElementById('stat-cards').innerHTML = '<span class="muted">加载中…</span>';
    try {
      const data = await api.get('/api/admin/stats?days=' + encodeURIComponent(daysSel.value));
      renderCards(data.overview || {});
      renderTrend(data.trend || []);
      renderAlbums(data.albums || []);
      renderTracks(data.tracks || []);
      document.getElementById('trend-hint').textContent =
        '统计区间：' + data.from + ' ~ ' + data.today + '（北京时间自然日，每根柱为一天，悬停看数值）';
    } catch (e) {
      window.adminUi.toast('加载失败：' + e.message, 'err');
      document.getElementById('stat-cards').innerHTML = '<span class="muted">加载失败</span>';
    }
  }

  document.getElementById('filter-form').addEventListener('submit', (e) => {
    e.preventDefault();
    load();
  });

  load();
})();
</script>
@endsection
