# Laravel 10 后端（全新独立工程）

这是一个**完整、可直接运行的 Laravel 10 项目**，不是增量补丁：官方 10.x 骨架（`artisan` / `bootstrap/` / `config/` / `app/Http/Kernel.php` / `public/` / `storage/` / `tests/`）已齐备，业务模块（听书 API、微信登录、播放历史、COS 上传、Blade 管理后台）也已接入配置与路由。把整个 `backend/` 目录作为项目根目录部署即可，`composer install` 后**无需再手工合并任何文件**。

服务器 `43.133.250.32`｜域名 `api.mixyj.cn`｜AppID `wxd1742cec14ff6355`｜存储 腾讯云 COS（S3 兼容）

---

## 1. 安装

环境：PHP ≥ 8.1（`pdo_mysql`、`pdo_sqlite`、`fileinfo`、`mbstring`、`openssl`）、Composer 2、MySQL 5.7+/8.0、nginx + PHP-FPM。

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# 编辑 .env：填 DB_* / WX_SECRET / ADMIN_PASSWORD / COS_*（见第 2 节）
php artisan migrate
php artisan serve                 # 本地开发，默认 http://127.0.0.1:8000
```

部署时权限：

```bash
chmod -R ug+rw storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache   # 按实际 PHP-FPM 运行用户调整
```

后台入口 `https://api.mixyj.cn/admin/login`，密码为 `ADMIN_PASSWORD`。

首次 `migrate` 会建 8 张表：Laravel 标准 4 张（`users`、`password_reset_tokens`、`failed_jobs`、`personal_access_tokens`）+ 业务 4 张（`albums`、`tracks`、`wx_users`、`play_histories`）。

---

## 2. 配置

所有可填项都在 `.env.example`，**无需修改 `config/*`**（已内置好）。

| 变量 | 说明 |
| --- | --- |
| `APP_ENV` / `APP_DEBUG` / `APP_URL` | 上线改为 `production` / `false` / `https://api.mixyj.cn` |
| `APP_TIMEZONE` | 默认 `Asia/Shanghai` |
| `DB_*` | MySQL 连接信息 |
| `CACHE_DRIVER` | 默认 `file`，有 Redis 可改 `redis` |
| `SESSION_DRIVER` | 默认 `file`，后台登录依赖它 |
| `WX_APPID` / `WX_SECRET` | AppID 已填；AppSecret 在 mp 后台生成，**只存服务端** |
| `ADMIN_PASSWORD` | 后台登录密码，单管理员 session，务必设强密码 |
| `COS_SECRET_ID` / `COS_SECRET_KEY` / `COS_REGION` / `COS_BUCKET` / `COS_ENDPOINT` / `COS_CDN_DOMAIN` | bucket 形如 `name-1250000000`；endpoint 用 `https://cos.<region>.myqcloud.com`；CDN 域名即小程序 `downloadFile` 合法域名 |
| `COS_USE_PATH_STYLE` | 可选，默认 `false`；上传报 403/域名异常时改 `true` |
| `MINIAPP_ANNOUNCEMENT` | 首页公告，可空 |

工程内已接好的部分（不用手工追加片段）：

- `composer.json`：已含 `laravel/framework ^10.10`、`laravel/sanctum ^3.2`、`league/flysystem-aws-s3-v3 ^3.0`、`guzzlehttp/guzzle`
- `config/auth.php`：`wx` guard = `sanctum` + `wx_users` provider，与后台 session 认证隔离
- `config/filesystems.php`：`cos` 磁盘（`s3` 驱动，S3 兼容协议）
- `config/services.php`：`wechat` / `cos.cdn_domain` / `admin.password` / `miniapp.announcement`
- `config/app.php`：时区读 `APP_TIMEZONE`；其余为 Laravel 10 官方默认
- `routes/api.php` → `require api.v1.php`（由 `RouteServiceProvider` 套 `/api` 前缀 + `api` 中间件组）
- `routes/web.php` → `require admin.php`（`web` 组：session / cookie / CSRF），`/` 重定向到后台音频页
- `phpunit.xml`：测试用 `sqlite :memory:` + `array` 缓存，`php artisan test` 不依赖 MySQL/Redis/COS

改完配置执行 `php artisan config:clear`。

### COS 与上传大小

COS bucket 设为**公有读、私有写**；在 COS 控制台给 `cos.mixyj.cn` 绑定自定义域名并配好 CDN + HTTPS。若上传报 403 或域名解析异常，设 `COS_USE_PATH_STYLE=true`，或改用带 bucket 的 endpoint（`https://<bucket>.cos.<region>.myqcloud.com`）。

```ini
; php.ini
upload_max_filesize = 128M
post_max_size = 128M
max_execution_time = 300
```

```nginx
client_max_body_size 128m;
```

改完 reload PHP-FPM 与 nginx。

---

## 3. 生产部署

nginx 站点根目录指向 `backend/public`，`try_files $uri $uri/ /index.php?$query_string;`，PHP 请求转给 PHP-FPM；HTTPS 证书覆盖 `api.mixyj.cn`。

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> 改动路由后需 `php artisan route:clear && php artisan route:cache`（业务路由由 `require` 引入，缓存的是编译后的路由表，不受影响）。
> 若反代后生成的 URL 是 `http://`：确认 nginx 传了 `X-Forwarded-Proto`，并把 `app/Http/Middleware/TrustProxies.php` 的 `$proxies` 设为反代 IP 或 `'*'`。`APP_ENV=production` 时 `AppServiceProvider` 已自动 `URL::forceScheme('https')`。

---

## 4. 接口一览

统一响应 `{ code, message, data, request_id }`，`code = 0` 为成功。

| 方法 | 路径 | 鉴权 | 说明 |
| --- | --- | --- | --- |
| POST | `/api/v1/auth/login` | 限流 30/min | `{code}` → `{token, user}` |
| GET | `/api/v1/bootstrap` | 无 | 版本戳 + 公告 |
| GET | `/api/v1/catalog/version` | 无 | 版本戳 |
| GET | `/api/v1/albums?page=&per_page=` | 无 | 已上架专辑 |
| GET | `/api/v1/albums/{id}/tracks?page=&per_page=` | 无 | 播放列表，按 sort_order |
| GET | `/api/v1/me/histories` | Bearer | 播放历史 |
| POST | `/api/v1/me/histories` | Bearer | `{track_id, progress_sec, duration_sec}` upsert |
| DELETE | `/api/v1/me/histories/{trackId}` | Bearer | 删单条 |
| DELETE | `/api/v1/me/histories` | Bearer | 清空 |

后台（session + CSRF）：

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET/POST | `/admin/login` | 登录 |
| POST | `/admin/logout` | 退出 |
| GET/POST | `/admin/albums` | 专辑列表 / 新建 |
| POST | `/admin/albums/reorder` | `{album_id, target_id}` |
| GET/POST | `/admin/tracks` | 音频列表（搜索/试听）/ 上传 |
| PATCH | `/admin/tracks/{id}` | 标题、封面、上下架 |
| DELETE | `/admin/tracks/{id}` | 软删 |
| POST | `/admin/tracks/reorder` | `{album_id, track_id, target_id}`，插到 target 之前，target 为空则移到末尾 |

`target_id` 语义：**移动到该项之前**；为空或 0 → 移到末尾。

---

## 5. 上传实现说明

- 单文件 `multipart` 直传，扩展名白名单 `mp3/m4a/aac/wav` + 文件头签名校验 + ≤100MB；object key 由服务端用 ULID 生成，不使用原始文件名。
- **时长由上传页浏览器**通过 `<audio>` 的 `loadedmetadata` 读取后随表单提交（`duration_sec`），服务端不依赖 ffmpeg / 队列；读不到则存 `null`，小程序显示 `--:--`。
- 上传失败会清理已写入 COS 的孤儿对象；音频删除为软删，COS 对象保留便于恢复。
- 所有后台写操作都会 `CatalogService::bump()`，版本号参与缓存键，小程序按版本戳自动拉新数据，无需手动清缓存。

---

## 6. 小程序侧配置（mp 后台）

| 项 | 值 |
| --- | --- |
| request 合法域名 | `https://api.mixyj.cn` |
| downloadFile 合法域名 | `https://cos.mixyj.cn` |
| 后台能力 | `app.json` 的 `requiredBackgroundModes: ["audio"]` 已声明（开发版/体验版直接生效） |
| 隐私 | 配置《小程序用户隐私保护指引》，声明收集 `openid`、播放记录 |

开发者工具可勾选「不校验合法域名」做本地联调；**真机预览/体验版必须先配好合法域名 + HTTPS 证书**。

---

## 7. 验证

```bash
cp .env.example .env && php artisan key:generate
composer install
php artisan test                      # 使用 sqlite :memory:，无需外部服务
php artisan config:clear && php artisan route:clear
php artisan route:list --path=v1
php artisan route:list --path=admin
find app config routes database tests bootstrap -name '*.php' -print0 | xargs -0 -n1 php -l
```

```bash
curl -s https://api.mixyj.cn/api/v1/bootstrap
curl -s https://api.mixyj.cn/api/v1/albums
curl -s -X POST https://api.mixyj.cn/api/v1/auth/login \
  -H 'Content-Type: application/json' -d '{"code":"小程序 wx.login 拿到的 code"}'
```

端到端：后台上传一条音频 → 体验版列表可见 → 播放并产生历史 → 后台下架 → 体验版刷新后不可见 → 历史里该条自动隐藏。

---

## 8. 已知限制（务必知悉）

1. **个人主体 + 音频/有声读物类目未开放**，且 `requiredBackgroundModes` 正式版需审核 → 当前只保证体验版可用，正式发布大概率被驳回。
2. `wx.login` 的 code 有效期 5 分钟、只能用一次；小程序 `services/auth.js` 在每次冷启动与 401 时重新换取 token。
3. 后台播放期间微信不回调 `onTimeUpdate`，**播放进度只在小程序前台时上报**；切歌 / 暂停 / 回前台时补报，因此后台播放的历史进度会滞后于实际播放位置。
4. `BackgroundAudioManager.setShutdown` 官方文档缺少 `time` 单位与 `autoNext` 语义细项，小程序侧做了能力探测 + JS 兜底，需在真机确认后按实测调整。
5. 音频 URL 使用 COS 公有读 + 防盗链的长期有效地址；若改成短时效签名 URL，后台连续播放超时会断播。
6. 后台登录为单管理员 + session，无 RBAC / 操作审计；管理接口依赖 HTTPS 保护。
