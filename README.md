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
| `COS_SECRET_ID` / `COS_SECRET_KEY` / `COS_REGION` / `COS_BUCKET` / `COS_ENDPOINT` | 控制台「访问密钥 / 存储桶」取值；`COS_BUCKET` 形如 `name-1250000000`（含 AppId 后缀）；endpoint 用 `https://cos.<region>.myqcloud.com`；**前两项必填，留空上传会报 IMDS 超时，见第 3 节** |
| `COS_CDN_DOMAIN` | **音频对外访问根地址**，不带路径/结尾斜杠。路径 A 填 bucket 默认访问域名 `https://<bucket>.cos.<region>.myqcloud.com`；路径 B 填自有域名 `https://cos.mixyj.cn`。它同时是小程序 `downloadFile` 合法域名 |
| `AUDIO_DRIVER` | `s3`（默认，S3 兼容接口）/ `local`（服务器挂载目录）。业务代码 `Storage::disk('cos')` 不变；切换后执行 `php artisan config:clear` |
| `AUDIO_LOCAL_ROOT` | 仅 `local` 模式：轻量对象存储一键挂载到服务器的目录，如 `/mnt/lightcos` |
| `COS_USE_PATH_STYLE` | 可选，默认 `false`；上传报 403/域名异常时改 `true` |
| `MINIAPP_ANNOUNCEMENT` | 首页公告，可空 |

工程内已接好的部分（不用手工追加片段）：

- `composer.json`：已含 `laravel/framework ^10.10`、`laravel/sanctum ^3.2`、`league/flysystem-aws-s3-v3 ^3.0`、`guzzlehttp/guzzle`
- `config/auth.php`：`wx` guard = `sanctum` + `wx_users` provider，与后台 session 认证隔离
- `config/filesystems.php`：`cos` 磁盘（`s3` / `local` 由 `AUDIO_DRIVER` 切换）；`s3` 分支显式设 `credentials => false`，避免密钥缺失时 AWS SDK 回退实例元数据服务（169.254.169.254）
- `config/services.php`：`wechat` / `cos.cdn_domain` / `admin.password` / `miniapp.announcement`
- `config/app.php`：时区读 `APP_TIMEZONE`；其余为 Laravel 10 官方默认
- `routes/api.php` → `require api.v1.php`（由 `RouteServiceProvider` 套 `/api` 前缀 + `api` 中间件组）
- `routes/web.php` → `require admin.php`（`web` 组：session / cookie / CSRF），`/` 重定向到后台音频页
- `phpunit.xml`：测试用 `sqlite :memory:` + `array` 缓存，`php artisan test` 不依赖 MySQL/Redis/COS

改完配置执行 `php artisan config:clear`。

### 轻量对象存储（Lighthouse 版）：没有自定义域名

> 官方对比表原文：轻量对象存储（Lighthouse 版）访问域名为 `<Bucket-Appid>.cos.<Region>.myqcloud.com`，**不支持自定义源站域名**；标准 COS 才支持。所以控制台找不到「给 bucket 配域名」是产品限制，不是漏配。
> 若你买的是 2025 年后的独立产品「轻量对象存储 LightCOS」（`console.cloud.tencent.com/lightcos`），它**支持**自定义域名：存储桶详情 → 域名管理 → 自定义域名 → 添加域名 + CNAME 解析；那种情况按原方案填 `cos.mixyj.cn` 即可。

两条路径任选，**业务代码都不用改**：

#### 路径 A（推荐）：直接用 bucket 默认访问域名

1. 控制台 → 存储桶 → **访问权限**：改成「公有读私有写」（Lighthouse 版默认私有读写，不改音频会 403）。
2. 文件列表里「分享」任一文件，复制对象地址，取 `https://<Bucket-Appid>.cos.<Region>.myqcloud.com` 这一段。
3. `.env`：

   ```ini
   AUDIO_DRIVER=s3
   COS_BUCKET=listen-book-1250000000
   COS_REGION=ap-guangzhou
   COS_ENDPOINT=https://cos.ap-guangzhou.myqcloud.com
   COS_CDN_DOMAIN=https://listen-book-1250000000.cos.ap-guangzhou.myqcloud.com
   ```

4. mp 后台「downloadFile 合法域名」填与 `COS_CDN_DOMAIN` 一致的地址。若 mp 后台不接受带 bucket 的完整域名，改填地域根域名 `https://cos.<Region>.myqcloud.com`，两者都加上也可以。体验版仍需配置，开发者工具可勾「不校验合法域名」。
5. 默认域名有下载频率限制、无加速，个人体验版够用；正式放量建议换标准 COS + 自定义域名/CDN。

> ⚠️ Lighthouse 版官方只声明「支持 COS SDK」，**未声明兼容 AWS S3 SDK**。本工程用 `league/flysystem-aws-s3-v3` 走 S3 兼容协议，需实测：后台上传一条小音频，若返回 `403 SignatureDoesNotMatch` / `400 InvalidArgument` / `501 Not Implemented`，先试 `COS_USE_PATH_STYLE=true`；仍失败就直接走路径 B，不要反复调密钥。

#### 路径 B（兜底）：一键挂载 + nginx 自有域名

Lighthouse 版支持把存储桶**一键挂载**到轻量应用服务器本地目录（同地域内网互通、内网流量免费），彻底绕开 SDK 兼容性。

1. 控制台 → 轻量应用服务器 → 轻量存储 → 一键挂载，记下挂载目录（如 `/mnt/lightcos`）。
2. `.env`：

   ```ini
   AUDIO_DRIVER=local
   AUDIO_LOCAL_ROOT=/mnt/lightcos
   COS_CDN_DOMAIN=https://cos.mixyj.cn
   ```

   `config/filesystems.php` 的 `cos` 磁盘会切成 `local` 驱动，`Storage::disk('cos')->put/delete` 照常工作。
3. `cos.mixyj.cn` 解析到 `43.133.250.32` 并配 HTTPS，nginx 静态站：

   ```nginx
   server {
       listen 443 ssl http2;
       server_name cos.mixyj.cn;
       root /mnt/lightcos;
       # ssl_certificate / ssl_certificate_key ...
       location / {
           try_files $uri =404;
           add_header Cache-Control "public, max-age=31536000, immutable";
       }
   }
   ```

   object key 形如 `audio/1/xxx.mp3`，与挂载目录结构一致，直接命中。
4. mp 后台 `downloadFile` 合法域名填 `https://cos.mixyj.cn`。
5. 代价：音频流量走服务器公网带宽（轻量服务器带宽较小，多人同时听会卡），体验版/自用没问题。
6. 确保 PHP-FPM 用户对挂载目录可写、nginx 用户可读；切驱动后执行 `php artisan config:clear`。

#### 上传大小（两条路径都要调）

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


## 3. 上传排错

上传封面 / 音频失败时，错误信息会直接显示在后台表单顶部，先看这张表：

| 现象 | 根因 | 处理 |
| --- | --- | --- |
| `Error retrieving credentials from the instance profile metadata service`、`169.254.169.254`、`cURL error 28` | `.env` 里 `COS_SECRET_ID` / `COS_SECRET_KEY` 为空。Laravel 只在两者都非空时才把 `credentials` 传给 AWS SDK；缺了 SDK 就走默认凭证链去找 EC2 实例元数据，本地与轻量服务器没这个服务 → 1 秒超时 | 填真实密钥后 `php artisan config:clear`；或改 `AUDIO_DRIVER=local` 走挂载目录 |
| `对象存储未配置：.env 里的 COS_XXX 为空` | 本工程的前置校验提前拦下（不会再等 IMDS 超时） | 同上 |
| `COS_BUCKET 仍是 .env.example 的模板占位值` | 直接复制模板，没改桶名 | 把 `your-bucket-1250000000` 换成控制台真实桶名，`COS_CDN_DOMAIN` 同步换 |
| `403 SignatureDoesNotMatch` / `400 InvalidArgument` / `501 Not Implemented` | 轻量对象存储（Lighthouse 版）只声明支持 COS SDK，未声明兼容 AWS S3 SDK | 先试 `COS_USE_PATH_STYLE=true`；仍失败切路径 B（`AUDIO_DRIVER=local`） |
| `NoSuchBucket` / `403 AccessDenied` | 桶名 / 地域错，或 bucket 还是「私有读写」 | 核对 `COS_BUCKET`（含 AppId 后缀）与 `COS_REGION`；控制台权限改「公有读私有写」 |
| `UnableToWriteFile`、上传超时 | PHP / nginx 上传限制或网络不可达 | 见第 2 节「上传大小」，并执行下方自检 |

### 配置自检（不用打开浏览器）

```bash
# 生效驱动与密钥填写状态（不打印密钥内容）
php artisan tinker --execute="echo 'driver='.config('filesystems.disks.cos.driver').PHP_EOL; echo 'credentials='.(config('filesystems.disks.cos.key') ? 'filled' : 'EMPTY').PHP_EOL;"

# 真实写入探测：成功返回「COS 写入成功」，桶里会留下 _probe/ 目录，可自行删除
php artisan tinker --execute="try { echo Storage::disk('cos')->put('_probe/selftest.txt','ok') ? 'COS 写入成功' : '写入失败'; } catch (\Throwable \$e) { echo get_class(\$e).': '.\$e->getMessage(); }"
```

> 若执行过 `php artisan config:cache`，改完 `.env` 必须 `php artisan config:clear`（或重新 `config:cache`），否则读到的仍是旧值。切换 `AUDIO_DRIVER` 同理。

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
| downloadFile 合法域名 | 路径 A：`https://<Bucket-Appid>.cos.<Region>.myqcloud.com`（若 mp 后台不接受，改填 `https://cos.<Region>.myqcloud.com`）；路径 B：`https://cos.mixyj.cn` |
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
5. 音频 URL 为公有读长期地址（轻量对象存储 Lighthouse 版**不支持自定义源站域名**，只能用默认访问域名或挂载 + 自有域名）；若改短时效签名 URL，后台连续播放超时会断播。公有读有流量盗刷风险，注意套餐流量。
6. 后台登录为单管理员 + session，无 RBAC / 操作审计；管理接口依赖 HTTPS 保护。
