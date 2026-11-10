/*
 * 后台前端公共层：Bearer token 存取、带自动刷新的请求封装、页面守卫、渲染工具。
 * 无构建步骤、无第三方依赖，直接由 Blade 页面以 <script src> 引入。
 */
(function () {
  'use strict';

  var AT_KEY = 'lb_admin_at';
  var RT_KEY = 'lb_admin_rt';
  var PROFILE_KEY = 'lb_admin_profile';

  function getAccess() {
    return localStorage.getItem(AT_KEY) || '';
  }

  function getRefresh() {
    return localStorage.getItem(RT_KEY) || '';
  }

  function profile() {
    try {
      return JSON.parse(localStorage.getItem(PROFILE_KEY) || '{}') || {};
    } catch (e) {
      return {};
    }
  }

  function setSession(data) {
    if (data.access_token) localStorage.setItem(AT_KEY, data.access_token);
    if (data.refresh_token) localStorage.setItem(RT_KEY, data.refresh_token);
    if (data.admin) localStorage.setItem(PROFILE_KEY, JSON.stringify(data.admin));
  }

  function clearSession() {
    localStorage.removeItem(AT_KEY);
    localStorage.removeItem(RT_KEY);
    localStorage.removeItem(PROFILE_KEY);
  }

  function hasSession() {
    return getAccess() !== '' || getRefresh() !== '';
  }

  function redirectToLogin() {
    clearSession();
    if (location.pathname !== '/admin/login') {
      location.replace('/admin/login');
    }
  }

  // 并发请求同时收到 401 时只发起一次刷新，其余等同一个 promise
  var refreshPromise = null;

  function refreshSession() {
    if (refreshPromise) return refreshPromise;

    var rt = getRefresh();
    if (!rt) return Promise.reject(new Error('没有可用的刷新凭据'));

    refreshPromise = fetch('/api/admin/auth/refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ refresh_token: rt })
    })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (body) {
          if (!res.ok || body.code !== 0) throw new Error(body.message || '登录已失效');
          setSession(body.data);
          return body.data;
        });
      })
      .finally(function () { refreshPromise = null; });

    return refreshPromise;
  }

  function buildOptions(method, body, extra) {
    var options = { method: method, headers: { Accept: 'application/json' } };

    if (body instanceof FormData) {
      options.body = body; // 交给浏览器自动带 boundary，不能手写 Content-Type
    } else if (body !== undefined && body !== null) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }

    if (extra && extra.headers) {
      Object.keys(extra.headers).forEach(function (k) { options.headers[k] = extra.headers[k]; });
    }
    if (extra && extra.signal) options.signal = extra.signal;

    return options;
  }

  function send(method, url, body, extra) {
    var options = buildOptions(method, body, extra);
    var token = getAccess();
    if (token) options.headers.Authorization = 'Bearer ' + token;
    return fetch(url, options);
  }

  function readJson(res) {
    return res.json().catch(function () { return {}; }).then(function (json) {
      return { res: res, json: json };
    });
  }

  function finalize(pair) {
    var res = pair.res;
    var json = pair.json;

    if (res.status === 401) {
      redirectToLogin();
      throw new Error(json.message || '登录已过期');
    }

    if (!res.ok || json.code !== 0) {
      var err = new Error(json.message || ('请求失败（' + res.status + '）'));
      err.status = res.status;
      err.body = json;
      throw err;
    }

    return json.data;
  }

  /**
   * 统一请求：返回 Api 响应体里的 data，失败抛 Error(message)。
   * access 过期（code 40102）时自动刷新并重放一次。
   */
  function request(method, url, body, extra) {
    return send(method, url, body, extra).then(readJson).then(function (pair) {
      if (pair.res.status === 401 && pair.json.code === 40102 && getRefresh()) {
        return refreshSession()
          .then(function () { return send(method, url, body, extra); })
          .then(readJson)
          .then(finalize, function () {
            redirectToLogin();
            throw new Error(pair.json.message || '登录已过期');
          });
      }

      return finalize(pair);
    });
  }

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function formatDuration(seconds) {
    if (!seconds) return '--:--';
    var s = Math.max(0, Math.floor(seconds));
    var m = Math.floor(s / 60);
    return String(m).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
  }

  /** 页面级提示条，样式复用布局里的 .msg */
  function toast(message, kind) {
    var el = document.getElementById('page-msg');
    if (!el) {
      el = document.createElement('div');
      el.id = 'page-msg';
      var main = document.querySelector('main');
      if (main) main.insertBefore(el, main.firstChild);
    }
    el.className = 'msg ' + (kind === 'err' ? 'err' : 'ok');
    el.textContent = message;
    el.style.display = '';
  }

  function hideToast() {
    var el = document.getElementById('page-msg');
    if (el) el.style.display = 'none';
  }

  window.adminSession = {
    getAccess: getAccess,
    getRefresh: getRefresh,
    profile: profile,
    setSession: setSession,
    clearSession: clearSession,
    hasSession: hasSession
  };

  window.adminApi = {
    get: function (url) { return request('GET', url); },
    post: function (url, body) { return request('POST', url, body); },
    patch: function (url, body) { return request('PATCH', url, body); },
    del: function (url) { return request('DELETE', url); },
    request: request,
    refresh: refreshSession
  };

  window.adminUi = {
    escapeHtml: escapeHtml,
    formatDuration: formatDuration,
    toast: toast,
    hideToast: hideToast
  };

  /** 未登录直接跳登录页，返回 false 时页面脚本应中止后续加载 */
  window.requireAdmin = function requireAdmin() {
    if (!hasSession()) {
      redirectToLogin();
      return false;
    }
    return true;
  };

  window.adminLogout = function adminLogout() {
    var rt = getRefresh();
    var done = function () { redirectToLogin(); };
    if (!rt) { done(); return; }

    fetch('/api/admin/auth/logout', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ refresh_token: rt })
    }).catch(function () {}).then(done);
  };
})();
