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
| `COS_CDN_DOMAIN` | **音频对外访问根地址**，不带路径/结尾斜杠。路径 A 填 bucket 默认访问域名 `https://<bucket>.cos.<region>.myqcloud.com`；路径 B 填自有域名 `https://cos.mixyj.cn`。它同时是小程序 `downloadFile` 合法域名。**`COS_BUCKET` / `COS_REGION` / `COS_ENDPOINT` / `COS_CDN_DOMAIN` 四项必须指向同一地域同一桶** |
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

### 轻量对象存储：先分清两个控制台

腾讯云有两个名字近似的产品，**以控制台入口判定，不要看商品名**：

| 控制台入口 | 产品 | 自定义域名 | 默认（体验）域名 |
| --- | --- | --- | --- |
| 轻量应用服务器 → 轻量存储 | 轻量对象存储（Lighthouse 版） | **不支持自定义源站域名**（官方对比表原文） | `<Bucket-Appid>.cos.<Region>.myqcloud.com` |
| `console.cloud.tencent.com/lightcos` | 轻量对象存储 LightCOS（2025 年独立新产品） | **支持**：存储桶详情 → 域名管理 → 自定义域名 → 添加域名 + CNAME 解析 | `<Bucket-Appid>.cos.<Region>.myqcloud.com`，官方称「体验域名」 |

> 官方快速入门原文：存储桶**默认为私有读写，不允许公开访问**；体验域名「限制下载频率不超过 10QPS，无加速效果，仅适合用于测试」，自定义域名「支持 30000QPS，享受访问加速，且不额外收取加速费用」。
> 两个产品的共同点：**桶默认私有读写**。上传成功但链接 403 都是因为它，处理见第 3 节。
> 本工程当前用 bucket 默认访问域名；若你在 lightcos 控制台配好了自定义域名，把 `COS_CDN_DOMAIN` 改成 `https://cos.mixyj.cn` 即可，业务代码不用动。

两条路径任选，**业务代码都不用改**：

#### 路径 A（推荐）：直接用 bucket 默认访问域名

1. 控制台 → 存储桶详情 → **基础配置 > 权限设置** → 编辑**公共访问权限** → 改成「公有读私有写」。桶默认「私有读写」，匿名读必须带签名，不改则音频/封面直链一律 `403 AccessDenied`（见第 3 节）。
2. 文件列表里「分享」任一文件，复制对象地址，取 `https://<Bucket-Appid>.cos.<Region>.myqcloud.com` 这一段。
3. `.env`：

   ```ini
   AUDIO_DRIVER=s3
   COS_BUCKET=listen-book-1250000000
   COS_REGION=ap-guangzhou
   COS_ENDPOINT=https://cos.ap-guangzhou.myqcloud.com
   COS_CDN_DOMAIN=https://listen-book-1250000000.cos.ap-guangzhou.myqcloud.com
   ```

4. mp 后台「downloadFile 合法域名」填与 `COS_CDN_DOMAIN` **完全一致的完整 host**。微信官方明确「不支持配置父域名，使用子域名」，地域根域名 `https://cos.<Region>.myqcloud.com` **不能代替** bucket 子域名，填了不生效。腾讯云 `*.myqcloud.com` 已备案可直接配置。体验版仍需配置，开发者工具仅本地可勾「不校验合法域名」。
5. 默认域名有下载频率限制、无加速，个人体验版够用；正式放量建议换标准 COS + 自定义域名/CDN。

> ✅ **本桶已实测**：通过 S3 兼容接口 `PutObject` 上传成功（`league/flysystem-aws-s3-v3`），此前担心的签名不兼容没有出现。换桶 / 换地域后才报 `403 SignatureDoesNotMatch` / `400 InvalidArgument` / `501 Not Implemented` 时，先试 `COS_USE_PATH_STYLE=true`；仍失败走路径 B，不要反复调密钥。

#### 路径 B（兜底）：一键挂载 + nginx 自有域名

若控制台是轻量应用服务器的「轻量存储」（Lighthouse 版），可把存储桶**一键挂载**到服务器本地目录（同地域内网互通、内网流量免费），彻底绕开 SDK 兼容性。

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

#### 上传大小：nginx / PHP / Laravel 三层必须一致

业务上限是 **100MB**（`StoreTrackRequest` 的 `max:102400`，单位 KB）。**413 是 nginx 在请求体进入 PHP 之前返回的**，Laravel 根本收不到请求，`storage/logs/laravel.log` 里也不会有记录——不要去翻日志找原因。

三层关系必须满足：`nginx client_max_body_size >= PHP post_max_size > PHP upload_max_filesize >= 单文件上限`。

```nginx
# nginx：放在 http{} 或 listen_api 的 server{} 里（默认仅 1m，几十 MB 音频必 413）
client_max_body_size 128m;
fastcgi_read_timeout 300;   # 上传大文件到对象存储较慢，避免 60s 后被切成 504
client_body_timeout 300;
```

```ini
; php.ini（用 php --ini 查实际加载路径；FPM 下改完必须 reload 才生效）
upload_max_filesize = 110M   ; 单个文件，略高于业务 100MB
post_max_size       = 128M   ; 整个请求体，必须大于 upload_max_filesize（含封面与表单字段）
memory_limit        = 256M
max_execution_time  = 300
max_input_time      = 300
```

```bash
nginx -t && nginx -s reload
systemctl reload php-fpm        # 也可能是 php8.2-fpm / service php-fpm reload，按实际版本
php -i | grep -E 'upload_max_filesize|post_max_size'
# 注：CLI 下 max_execution_time 恒显示 0 / max_input_time 显示 -1，是 SAPI 硬编码，不代表配置没生效
```

- **宝塔面板**：网站 → 设置 → 配置文件改 `client_max_body_size`；软件商店 → PHP → 配置修改 → 「上传限制」与 `post_max_size`，改完重启 PHP 服务。
- **本地 LAMP（`my-lamp-project`）已配好**：`nginx.conf` 的 `http{}` 设了 `client_max_body_size 128m` + `fastcgi_read_timeout 300`；`php.ini` 由 `docker-compose.yml` 挂载到 `conf.d/zz-uploads.ini`，已 `nginx -s reload` + php-fpm `USR2` 热重载（未重启容器）。日后若重建 php 容器导致 nginx 502，执行 `docker exec my_nginx nginx -s reload`。
- **诊断**：上传页顶部会显示 FPM 运行时实测的 `upload_max_filesize` / `post_max_size` 与单文件可用上限，低于 100MB 会红字告警；上传失败时验证消息会带出 PHP 错误码和这两个值（本工程已把 Laravel 默认英文 `The audio failed to upload.` 中文化），不用再猜。
- 区分 PHP 层的另两种症状：`post_max_size` 过小 → `$_FILES` 为空、Laravel 报「音频文件 必填」；`upload_max_filesize` 过小 → `$_FILES['audio']['error'] = 1`。两者都不会是 413。

## 3. 上传排错

上传封面 / 音频失败时，错误信息会直接显示在后台表单顶部，先看这张表：

| 现象 | 根因 | 处理 |
| --- | --- | --- |
| **上传音频直接返回 `413 Request Entity Too Large`** | nginx `client_max_body_size` 默认 1m，请求体在到达 PHP 前被拒；Laravel 收不到请求、日志无记录。**封面能传、音频不能传**就是这个特征 | 按第 2 节「上传大小」改 nginx + php.ini 并 reload 两者 |
| **`The audio failed to upload.`（英文）/ `上传失败：…`（中文诊断）** | 请求已到 Laravel，但 `$_FILES['audio']['error'] != 0` → 验证器自动触发 `uploaded` 规则。最常见是服务器 `upload_max_filesize` 仍是 2M，或改了 php.ini 没 reload php-fpm；`post_max_size` 过小则表现为 419 或「必填」 | 刷新上传页看顶部的 **PHP-FPM 实测限制**；按第 2 节改 php.ini 并 `systemctl reload php-fpm`。若仍显示英文提示，说明服务器跑的是旧代码，需先部署 `app/Http/Requests/` 下 4 个文件与 `tracks/index.blade.php` |
| `Error retrieving credentials from the instance profile metadata service`、`169.254.169.254`、`cURL error 28` | `.env` 里 `COS_SECRET_ID` / `COS_SECRET_KEY` 为空。Laravel 只在两者都非空时才把 `credentials` 传给 AWS SDK；缺了 SDK 就走默认凭证链去找 EC2 实例元数据，本地与轻量服务器没这个服务 → 1 秒超时 | 填真实密钥后 `php artisan config:clear`；或改 `AUDIO_DRIVER=local` 走挂载目录 |
| `对象存储未配置：.env 里的 COS_XXX 为空` | 本工程的前置校验提前拦下（不会再等 IMDS 超时） | 同上 |
| `COS_BUCKET 仍是 .env.example 的模板占位值` | 直接复制模板，没改桶名 | 把 `your-bucket-1250000000` 换成控制台真实桶名，`COS_CDN_DOMAIN` 同步换 |
| **上传成功，但图片/音频 URL 打开返回 `403 AccessDenied`（XML）** | 桶默认「私有读写」，匿名读必须带签名。换个不存在的 key 会返回 `404 NoSuchKey`，可反证对象确实已写入、只是没读权限 | 控制台 → 存储桶详情 → 基础配置 > 权限设置 → 公共访问权限改「公有读私有写」；改完用下方 `curl -I` 验证 |
| `403 SignatureDoesNotMatch` / `400 InvalidArgument` / `501 Not Implemented` | 该桶 / 地域不兼容 AWS S3 签名（本桶实测 `PutObject` 已通过，换桶后才可能遇到） | 先试 `COS_USE_PATH_STYLE=true`；仍失败切路径 B（`AUDIO_DRIVER=local`） |
| `NoSuchBucket` / `NoSuchKey` / 404 | 桶名 / 地域 / key 拼错；`COS_BUCKET`、`COS_REGION`、`COS_ENDPOINT`、`COS_CDN_DOMAIN` 必须同地域同桶 | 核对四项配置；控制台文件列表确认对象路径 |
| `UnableToWriteFile`、上传超时 / `504 Gateway Timeout` | PHP / nginx 上传限制、`fastcgi_read_timeout` 过短或网络不可达 | 见第 2 节「上传大小」，并执行下方自检 |

### 权限改完的验证

```bash
curl -I 'https://<bucket>.cos.<region>.myqcloud.com/cover/3/<文件名>.png'
# 期望 200 + Content-Type: image/png；仍是 403 说明权限未生效或该对象 ACL 仍为私有
```

若桶已改「公有读私有写」但**已上传的旧对象**仍 403：在控制台文件列表删掉该对象重传（新对象继承桶权限）。私有读桶的「分享」链接带签名参数，不能当长期 URL 存进数据库。

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
| downloadFile 合法域名 | 路径 A：`https://<Bucket-Appid>.cos.<Region>.myqcloud.com`；路径 B：`https://cos.mixyj.cn`。**必须填完整 host**，微信不支持父域名/泛域名，地域根域名不能替代 bucket 子域名 |
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
5. 音频 URL 为公有读长期地址（当前用 bucket 默认访问域名；若控制台支持自定义域名则优先换 `cos.mixyj.cn`，体验域名官方限制 10QPS、无加速）；若改短时效签名 URL，后台连续播放超时会断播。公有读有流量盗刷风险，注意套餐流量。
6. 后台登录为单管理员 + session，无 RBAC / 操作审计；管理接口依赖 HTTPS 保护。
