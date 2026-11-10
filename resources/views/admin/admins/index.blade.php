@extends('admin.layouts.app')
@section('title', '管理员')

@section('content')
<h1>管理员</h1>

<div class="card">
  <h2>新增管理员</h2>
  <form id="create-form">
    <div class="row">
      <div>
        <label for="create-username">用户名</label>
        <input id="create-username" type="text" name="username" maxlength="32" required placeholder="3-32 位字母、数字、下划线">
      </div>
      <div>
        <label for="create-phone">手机号</label>
        <input id="create-phone" type="text" name="phone" maxlength="11" required placeholder="11 位手机号">
      </div>
    </div>
    <div class="row">
      <div>
        <label for="create-name">显示名（选填）</label>
        <input id="create-name" type="text" name="name" maxlength="32">
      </div>
      <div>
        <label for="create-role">角色</label>
        <select id="create-role" name="role">
          <option value="admin">普通管理员</option>
          <option value="super">超级管理员</option>
        </select>
      </div>
    </div>
    <label for="create-password">初始密码</label>
    <input id="create-password" type="password" name="password" maxlength="64" required placeholder="至少 8 位，需含字母和数字" autocomplete="new-password">
    <div style="margin-top:14px"><button type="submit" id="create-btn">创建</button></div>
  </form>
</div>

<div class="card">
  <h2>管理员列表</h2>
  <table>
    <thead>
      <tr>
        <th>用户名</th>
        <th style="width:130px">手机号</th>
        <th style="width:110px">角色</th>
        <th style="width:90px">状态</th>
        <th style="width:170px">最后登录</th>
        <th style="width:230px">操作</th>
      </tr>
    </thead>
    <tbody id="admin-rows">
      <tr><td colspan="6" class="muted">加载中…</td></tr>
    </tbody>
  </table>
  <p class="muted" style="font-size:12px">不能禁用或删除当前登录的账号；系统始终保留至少一个启用的超级管理员。</p>
</div>
@endsection

@section('scripts')
<script>
(function () {
  if (!window.requireAdmin()) return;

  const esc = window.adminUi.escapeHtml;
  const api = window.adminApi;
  const tbody = document.getElementById('admin-rows');
  const createForm = document.getElementById('create-form');

  const ROLE_LABEL = { super: '超级管理员', admin: '普通管理员' };

  function rowHtml(a) {
    const self = a.is_self;

    return '' +
      '<tr data-id="' + a.id + '">' +
        '<td>' + esc(a.username) +
          (a.name ? '<div class="muted" style="font-size:12px">' + esc(a.name) + '</div>' : '') +
          (self ? '<div class="muted" style="font-size:12px">当前登录</div>' : '') +
        '</td>' +
        '<td>' + esc(a.phone) + '</td>' +
        '<td>' + (ROLE_LABEL[a.role] || esc(a.role)) + '</td>' +
        '<td>' + (a.status ? '启用' : '<span class="muted">已禁用</span>') + '</td>' +
        '<td class="muted">' + esc(a.last_login_at || '—') + '</td>' +
        '<td>' +
          '<button type="button" class="ghost" data-act="edit" data-id="' + a.id + '">编辑</button> ' +
          '<button type="button" class="ghost" data-act="password" data-id="' + a.id + '">重置密码</button> ' +
          (self ? '' : '<button type="button" class="danger" data-act="delete" data-id="' + a.id + '">移除</button>') +
        '</td>' +
      '</tr>' +
      '<tr class="edit-row" id="edit-admin-' + a.id + '" style="display:none">' +
        '<td colspan="6">' +
          '<form class="edit-form" data-edit-id="' + a.id + '">' +
            '<div class="row">' +
              '<div>' +
                '<label>用户名</label>' +
                '<input type="text" name="username" maxlength="32" value="' + esc(a.username) + '" required>' +
              '</div>' +
              '<div>' +
                '<label>手机号</label>' +
                '<input type="text" name="phone" maxlength="11" value="' + esc(a.phone) + '" required>' +
              '</div>' +
            '</div>' +
            '<div class="row">' +
              '<div>' +
                '<label>显示名</label>' +
                '<input type="text" name="name" maxlength="32" value="' + esc(a.name || '') + '">' +
              '</div>' +
              '<div>' +
                '<label>角色</label>' +
                '<select name="role">' +
                  '<option value="admin"' + (a.role === 'admin' ? ' selected' : '') + '>普通管理员</option>' +
                  '<option value="super"' + (a.role === 'super' ? ' selected' : '') + '>超级管理员</option>' +
                '</select>' +
              '</div>' +
              '<div>' +
                '<label>状态</label>' +
                '<select name="status"' + (self ? ' disabled' : '') + '>' +
                  '<option value="1"' + (a.status ? ' selected' : '') + '>启用</option>' +
                  '<option value="0"' + (a.status ? '' : ' selected') + '>禁用</option>' +
                '</select>' +
              '</div>' +
            '</div>' +
            '<div style="margin-top:12px">' +
              '<button type="submit">保存</button> ' +
              '<button type="button" class="ghost" data-act="cancel" data-id="' + a.id + '">取消</button>' +
            '</div>' +
          '</form>' +
        '</td>' +
      '</tr>';
  }

  async function loadAdmins() {
    tbody.innerHTML = '<tr><td colspan="6" class="muted">加载中…</td></tr>';

    try {
      const data = await api.get('/api/admin/admins');
      tbody.innerHTML = data.items.map(rowHtml).join('');
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="6" class="muted">加载失败：' + esc(e.message) + '</td></tr>';
    }
  }

  tbody.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;

    const act = btn.dataset.act;
    const id = btn.dataset.id;

    if (act === 'edit' || act === 'cancel') {
      window.toggleEdit('edit-admin-' + id);
      return;
    }

    if (act === 'password') {
      const password = prompt('输入新密码（至少 8 位，需含字母和数字）');
      if (password === null) return;

      btn.disabled = true;
      try {
        await api.post('/api/admin/admins/' + id + '/password', { password: password.trim() });
        window.adminUi.toast('密码已重置，该账号需重新登录');
      } catch (err) {
        alert('重置失败：' + err.message);
      } finally {
        btn.disabled = false;
      }
      return;
    }

    if (act === 'delete') {
      if (!confirm('确认移除该管理员？其已签发的登录凭据会立即失效。')) return;
      btn.disabled = true;
      try {
        await api.del('/api/admin/admins/' + id);
        window.adminUi.toast('管理员已移除');
        await loadAdmins();
      } catch (err) {
        alert('移除失败：' + err.message);
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
    const statusSelect = form.querySelector('select[name=status]');

    try {
      await api.patch('/api/admin/admins/' + id, {
        username: fd.get('username'),
        phone: fd.get('phone'),
        name: fd.get('name'),
        role: fd.get('role'),
        // 自己那一行的状态选择框被禁用，FormData 里没有值，回填 1
        status: statusSelect.disabled ? 1 : Number(fd.get('status'))
      });
      window.adminUi.toast('已保存');
      await loadAdmins();
    } catch (err) {
      alert('保存失败：' + err.message);
    }
  });

  createForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const fd = new FormData(createForm);
    const btn = document.getElementById('create-btn');
    btn.disabled = true;
    btn.textContent = '创建中…';

    try {
      await api.post('/api/admin/admins', {
        username: fd.get('username'),
        phone: fd.get('phone'),
        name: fd.get('name') || null,
        role: fd.get('role'),
        password: fd.get('password')
      });
      window.adminUi.toast('管理员已创建');
      createForm.reset();
      await loadAdmins();
    } catch (err) {
      window.adminUi.toast('创建失败：' + err.message, 'err');
    } finally {
      btn.disabled = false;
      btn.textContent = '创建';
    }
  });

  loadAdmins();
})();
</script>
@endsection
