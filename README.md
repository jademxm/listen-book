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

> **升级已有部署**：每次更新代码后执行 `php artisan migrate`。`2026_11_08_000001_add_cover_key_to_albums_and_tracks_table` 会给 `albums` / `tracks` 加 `cover_key` 列，并把历史 `cover_url` 里的完整 URL 反推成 object key 回填。只加列、不删列、不删数据，执行前建议备份。

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
| `BRAND_NAME` / `BRAND_LOGO` / `BRAND_COLOR` | **品牌三项，换项目只改这里**。站名留空回退 `APP_NAME`；Logo 填站内相对路径或完整 URL，留空只显示文字；主色作用于按钮 / 链接 / 选中态。视图统一读 `$brand`（`config/brand.php`，由 `AppServiceProvider` `View::share`），改完 `php artisan config:clear` 生效 |
| `CONTENT_GROUP_NAME` / `CONTENT_ITEM_NAME` | **内容层名的显示称呼**，默认「专辑 / 音频」。只影响后台界面与小程序里的文案，不改 API 路径、表名、字段名。改成「课程 / 章节」后小程序通过 `GET /bootstrap` 自动跟上，**不需要重新发版**。改完 `php artisan config:clear` |
| `CONTENT_MAX_DEPTH` | 内容树最大层级，默认 `2`。当前实现固定两层（容器 → 叶子），此项仅随 bootstrap 下发，为多层结构预留 |
| `COS_SECRET_ID` / `COS_SECRET_KEY` / `COS_REGION` / `COS_BUCKET` / `COS_ENDPOINT` | 控制台「访问密钥 / 存储桶」取值；`COS_BUCKET` 形如 `name-1250000000`（含 AppId 后缀）；endpoint 用 `https://cos.<region>.myqcloud.com`；**前两项必填，留空上传会报 IMDS 超时，见第 3 节** |
| `COS_CDN_DOMAIN` | **音频与封面共用的对外访问根地址**，不带路径/结尾斜杠。当前填标准 COS 的 bucket 默认访问域名 `https://<bucket>.cos.<region>.myqcloud.com`；路径 B 或日后启用自定义域名时填 `https://cos.mixyj.cn`。它同时是小程序 `downloadFile` 合法域名。**封面与音频 URL 都由它动态拼接，换桶/换域名只改这一项，不用刷数据库**。**`COS_BUCKET` / `COS_REGION` / `COS_ENDPOINT` / `COS_CDN_DOMAIN` 四项必须指向同一地域同一桶** |
| `AUDIO_DRIVER` | `s3`（默认，S3 兼容接口）/ `local`（服务器挂载目录）。业务代码 `Storage::disk('cos')` 不变；切换后执行 `php artisan config:clear` |
| `AUDIO_LOCAL_ROOT` | 仅 `local` 模式：轻量对象存储一键挂载到服务器的目录，如 `/mnt/lightcos` |
| `COS_USE_PATH_STYLE` | 可选，默认 `false`；上传报 403/域名异常时改 `true` |
| `MINIAPP_ANNOUNCEMENT` | 首页公告，可空 |

工程内已接好的部分（不用手工追加片段）：

- `composer.json`：已含 `laravel/framework ^10.10`、`laravel/sanctum ^3.2`、`league/flysystem-aws-s3-v3 ^3.0`、`guzzlehttp/guzzle`
- `config/auth.php`：`wx` guard = `sanctum` + `wx_users` provider，与后台 session 认证隔离
- `config/filesystems.php`：`cos` 磁盘（`s3` / `local` 由 `AUDIO_DRIVER` 切换）；`s3` 分支显式设 `credentials => false`，避免密钥缺失时 AWS SDK 回退实例元数据服务（169.254.169.254）
- `config/services.php`：`wechat` / `cos.cdn_domain` / `admin.password` / `miniapp.announcement`
- `config/brand.php`：品牌站名 / Logo / 主色，读 `.env` 的 `BRAND_*`；`AppServiceProvider` 已 `View::share('brand', config('brand'))`，Blade 里写 `$brand['name']` / `$brand['logo']` / `$brand['color']`，视图不出现站名与色值字面量
- `config/content.php`：内容层名与层级上限，读 `.env` 的 `CONTENT_*`。`AppServiceProvider` 用 `View::composer('admin.*')` 每次渲染注入 `$levels`，后台 Blade 写 `$levels['group']` / `$levels['item']`；`GET /api/v1/bootstrap` 的 `content` 段由它下发。**不用 `View::share`**——那会在 `boot()` 取一次值就固化，改 config 视图不跟
- `config/app.php`：时区读 `APP_TIMEZONE`；其余为 Laravel 10 官方默认
- `routes/api.php` → `require api.v1.php`（由 `RouteServiceProvider` 套 `/api` 前缀 + `api` 中间件组）
- `routes/web.php` → `require admin.php`（`web` 组：session / cookie / CSRF），`/` 重定向到后台音频页
- `phpunit.xml`：测试用 `sqlite :memory:` + `array` 缓存，`php artisan test` 不依赖 MySQL/Redis/COS

改完配置执行 `php artisan config:clear`。

### 对象存储：标准 COS 与轻量存储的差别

**当前部署使用腾讯云标准对象存储 COS + bucket 默认访问域名**，`AUDIO_DRIVER=s3` 走 S3 兼容接口。标准 COS 与轻量对象存储用同一套 S3 API、同样的 endpoint 格式、同样的 `<bucket>-<AppId>` 桶名，业务代码无需区分；差别只在控制台能力与默认域名限速。

腾讯云有两个名字近似的轻量产品，**以控制台入口判定，不要看商品名**（日后回退到轻量存储时参考）：

| 控制台入口 | 产品 | 自定义域名 | 默认（体验）域名 |
| --- | --- | --- | --- |
| 轻量应用服务器 → 轻量存储 | 轻量对象存储（Lighthouse 版） | **不支持自定义源站域名**（官方对比表原文） | `<Bucket-Appid>.cos.<Region>.myqcloud.com` |
| `console.cloud.tencent.com/lightcos` | 轻量对象存储 LightCOS（2025 年独立新产品） | **支持**：存储桶详情 → 域名管理 → 自定义域名 → 添加域名 + CNAME 解析 | `<Bucket-Appid>.cos.<Region>.myqcloud.com`，官方称「体验域名」 |

> 官方快速入门原文：存储桶**默认为私有读写，不允许公开访问**；体验域名「限制下载频率不超过 10QPS，无加速效果，仅适合用于测试」，自定义域名「支持 30000QPS，享受访问加速，且不额外收取加速费用」。
> 两个产品的共同点：**桶默认私有读写**。上传成功但链接 403 都是因为它，处理见第 3 节。
> 本工程当前用**标准 COS 的 bucket 默认访问域名**。标准 COS 支持自定义源站域名 / CDN 加速（需域名已备案 + CNAME + 证书），要启用时把 `COS_CDN_DOMAIN` 改成 `https://cos.mixyj.cn` 即可，业务代码和数据库都不用动。

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
5. 默认域名有下载频率限制、无 CDN 加速，个人体验版够用；正式放量可在标准 COS 上绑自定义域名 + CDN，只改 `COS_CDN_DOMAIN`。

> ✅ **本桶已实测**：通过 S3 兼容接口 `PutObject` 上传成功（`league/flysystem-aws-s3-v3`），此前担心的签名不兼容没有出现。换桶 / 换地域后才报 `403 SignatureDoesNotMatch` / `400 InvalidArgument` / `501 Not Implemented` 时，先试 `COS_USE_PATH_STYLE=true`；仍失败走路径 B，不要反复调密钥。

#### 对象权限：桶级一次配置，不写进代码

桶设成「公有读私有写」后，新上传的 `audio/*`、`cover/*` 应当自动匿名可读。COS 创建对象时默认不建 ACL，权限继承桶；控制台显示「所有用户可读」就是这个结果。

> ⚠️ **2026-11-09 修正**：标准 COS 的 S3 兼容层**会**把 `x-amz-acl: private` 当成对象 ACL 生效。此前这里写「不映射」是错的，实际现象是「后台上传成功，但图片裂开 / 音频 403，必须到控制台逐个改成公有读」。
>
> **根因**：`league/flysystem-aws-s3-v3` 的 `AwsS3V3Adapter::upload()`（155/171 行）按 `determineAcl()` 默认 `private` 调用 SDK，AWS SDK `ObjectUploader::promise()` 第 89 行也无条件写 `'ACL' => $this->acl`，于是每次 PutObject 都带 `x-amz-acl: private`，对象被显式设为私有、优先级高于桶权限。
>
> **已修**：`AudioStorageService::putToCosWithoutAcl()` 在 `s3` 驱动下改走底层 `S3Client::upload($bucket, $key, $stream, null, ...)`——第 4 参数传 `null` 时**完全不发 ACL 头**（>16MB 分片上传的 `CreateMultipartUpload` 同样不带），对象保持 `default` 继承桶权限。`AUDIO_DRIVER=local` 仍走 Flysystem；`ContentType` 按扩展名显式传入，与原先行为一致。
>
> **不要**用 `['visibility' => 'public']` 修：那会变成逐对象 `public-read` ACL，撞单账号 **1000 条** ACL/Policy 配额（`PolicyFull`），且桶权限此后对这些对象失效。官方口径是「若不需要对文件进行特别的 ACL 控制，请把 `x-cos-acl`、`ACL` 等参数置空，保持继承存储桶权限」。

**新上传**部署该改动后立即正常。**历史对象**仍是显式私有，需清一次：

1. **控制台（最快）**：存储桶 → 文件列表 → 勾选多个对象 → **更多操作 > 修改访问权限** → 选「**继承权限**」。官方明确支持多选批量改。
2. **脚本（可重复、覆盖全部页）**：

   ```bash
   cd /var/www/listen-book
   php scripts/fix-cos-object-acl.php            # dry-run，列出待处理对象
   php scripts/fix-cos-object-acl.php --apply    # 逐个把对象 ACL 置为 default
   ```

   脚本复用 `.env` 的 `COS_*` 配置，遍历 `audio/`、`cover/` 调 `putObjectAcl(['ACL' => 'default'])`。`default` = 继承桶权限、不创建对象级 ACL，不消耗配额。若 COS 的 S3 兼容层对该值返回 `InvalidArgument`，改用上面的控制台批量方式。
3. 验证：`curl -I 'https://<bucket>.cos.<region>.myqcloud.com/audio/1/xxx.mp3'` 期望 `200`。

若以后不想依赖「公有读」这条桶 ACL，可改用**桶策略**（控制台 → 权限管理 → Policy 权限设置）：一条规则允许匿名用户对该桶 `/*` 执行 `cos:GetObject`，不随对象数量增长、不占对象级 ACL 配额。**对象级 ACL 优先级高于桶策略**，必须先按上面把旧对象清成「继承」。

> 安全提示：匿名可读意味着拿到直链就能下载，这是小程序 `BGM.src` 直链播放的必要条件。要收敛可在控制台配「防盗链」白名单并**允许空 Referer**（播放器请求常不带 Referer）；改成私有读 + 预签名 URL 则需改 URL 生成与 catalog 缓存策略（签名会被 1 小时缓存固化后过期），不是改个配置项就行。
>
> 另注：官方说明「对象的访问权限只在通过**默认域名**访问时有效，通过自定义域名访问时以存储桶权限为准」——本工程用 bucket 默认域名，所以对象 ACL 会生效。

#### 换桶 / 从轻量存储迁到标准 COS

`AudioStorageService`、Controller、Resource、小程序**全部不用改**，只动配置与对象：

1. **建桶**：标准 COS 控制台新建桶，存储类型选**标准存储**（不要低频/归档，直链播放有取回延迟），公共访问权限改「公有读私有写」。
2. **搬对象**：旧桶对象**原样复制，object key 保持不变**（`audio/{album_id}/*.mp3`、`cover/{album_id}/*.png`）。`tracks.object_key` 是相对路径，DB 不用动。用 COS 控制台「数据迁移」或 `coscmd` / `rclone` / `aws s3 sync`。
   > ⚠️ 只改 `.env` 不搬对象，所有历史音频会立刻 404（URL 全被拼到新桶）。若按「先不管迁移」暂时不搬，历史音频/封面不可播是预期行为；key 不变，补搬后自动恢复。
3. **拉代码并迁移**（新增列 + 回填历史封面）：

   ```bash
   cd /var/www/listen-book
   php artisan migrate          # 加 cover_key 并回填历史 cover_url
   ```

4. **改 `.env` 四项**（必须同地域同桶）：

   ```ini
   COS_BUCKET=<新桶名>-<AppId>
   COS_REGION=ap-nanjing
   COS_ENDPOINT=https://cos.ap-nanjing.myqcloud.com
   COS_CDN_DOMAIN=https://<新桶名>-<AppId>.cos.ap-nanjing.myqcloud.com
   ```

5. **清两个缓存**（漏掉 `cache:clear` 会继续吐 1 小时前的旧桶 URL）：

   ```bash
   php artisan config:clear && php artisan cache:clear && php artisan view:clear
   ```

6. **mp 后台**：`downloadFile 合法域名` 加新 host（完整 host、不带路径、不支持父域名/泛域名）；`request` 的 `https://api.mixyj.cn` 不变。建议新旧域名并存一段时间再下线旧桶（每类域名有数量上限、每月修改次数有限）。
7. **验证**：

   ```bash
   curl -I 'https://<新桶域名>/audio/1/<某条真实 key>.mp3'    # 200
   curl -s https://api.mixyj.cn/api/v1/albums | head -c 500      # cover_url 已是新域名
   ```

   后台试传一条音频 + 封面，体验版真机确认能播、封面能显示。

> **封面为什么不用刷库**：`albums.cover_url` / `tracks.cover_url` 历史上存的是完整 URL，换域名后会全挂。本次改造新增 `cover_key` 列存 object key，`cover_url` 由模型 accessor 用 `COS_CDN_DOMAIN` 动态拼；migration 会把历史完整 URL 反推成 key 回填。以后换桶/换域名只改 `.env` 第 4 项即可。`cover_url` 列保留作旧数据兼容（无 key 时回退原值），新数据不再写入。

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
sudo nginx -t && sudo nginx -s reload
sudo systemctl reload php8.2-fpm   # 也可能是 php-fpm / php8.1-fpm，按实际版本
# 验证：用下方 _ini.php 临时探针确认 FPM 实际生效值；不要用 CLI 的 php -i（原因见下）
```

> ⚠ **`sudo -u www-data php -i | grep -E "upload_max_filesize|post_max_size"` 不能用来判断上传限制。**
> 命令行是 `cli` SAPI，读 `/etc/php/8.x/cli/php.ini`；Web 上传走 `fpm-fcgi` SAPI，读 `/etc/php/8.x/fpm/php.ini`（外加各自的 `conf.d/`）。这是**两份独立文件**：把 CLI 那份改成 120M/128M，对上传毫无影响，于是就出现「命令行显示 120M/128M，但页面提示 2M/8M」。
> 上传失败提示里的数值来自 Web 进程 `ini_get()`，才是真实生效值；提示还会给出本次请求**实际加载的 php.ini 绝对路径**，照着改即可。
> 另注：CLI 下 `max_execution_time` 恒显示 0、`max_input_time` 显示 -1，是 SAPI 硬编码，不代表配置没生效。

#### Ubuntu apt 安装的 PHP：改 FPM 专用 ini

```bash
# 1. 确认站点实际用的 FPM 版本（装了多个 PHP 版本时最容易改错）
grep -R 'fastcgi_pass' /etc/nginx/sites-enabled/
systemctl list-units --type=service | grep fpm

# 2. 改 FPM 专用配置（下面以 8.2 为例，按上一步结果替换）
sudo nano /etc/php/8.2/fpm/php.ini
#    upload_max_filesize = 110M
#    post_max_size       = 128M
#    memory_limit        = 256M
#    max_execution_time  = 300
#    max_input_time      = 300

# 3. FPM 是常驻进程，改完必须 reload，否则永远读旧值
sudo systemctl reload php8.2-fpm
sudo nginx -t && sudo systemctl reload nginx

# 4. 验证：用下方 _ini.php 探针确认 FPM 生效值（或看上传失败提示里的「实际加载的 php.ini」）
```

若改完 `/etc/php/8.2/fpm/php.ini` 并 reload 后页面仍是 2M，检查优先级更高的覆盖项：

```bash
# pool 级 php_admin_value 会覆盖 php.ini，且不能被 ini_set 改写
grep -Rni 'php_admin_value\|php_value' /etc/php/8.2/fpm/pool.d/ | grep -Ei 'upload|post_max|memory'
# 站点 nginx 配置里的 fastcgi_param PHP_VALUE / PHP_ADMIN_VALUE 同理
```

清掉覆盖项后 `sudo systemctl restart php8.2-fpm`（`php_admin_value` 变更通常需要 restart 而非 reload）。

不部署新代码也能确认 FPM 到底读了哪份配置（路径按站点实际 `public/` 调整，**用完务必删除**）：

```bash
echo '<?php echo "SAPI=".PHP_SAPI."\nini=".var_export(php_ini_loaded_file(),true)."\nscan=".var_export(php_ini_scanned_files(),true)."\nupload=".ini_get("upload_max_filesize")."\npost=".ini_get("post_max_size")."\n";' | sudo tee /var/www/listen-book/public/_ini.php
curl -s https://api.mixyj.cn/_ini.php
sudo rm -f /var/www/listen-book/public/_ini.php
```

`SAPI=fpm-fcgi` 说明走对了进程，输出里的 `ini=` 就是必须修改的那份文件。

- **宝塔面板**：网站 → 设置 → 配置文件改 `client_max_body_size`；软件商店 → PHP → 配置修改 → 「上传限制」与 `post_max_size`，改完重启 PHP 服务。
- **本地 LAMP（`my-lamp-project`）已配好**：`nginx.conf` 的 `http{}` 设了 `client_max_body_size 128m` + `fastcgi_read_timeout 300`；`php.ini` 由 `docker-compose.yml` 挂载到 `conf.d/zz-uploads.ini`，已 `nginx -s reload` + php-fpm `USR2` 热重载（未重启容器）。日后若重建 php 容器导致 nginx 502，执行 `docker exec my_nginx nginx -s reload`。
- **诊断**：上传页保持简洁、不显示运行时信息；上传失败时验证消息会带出 PHP 错误码、FPM 实测的 `upload_max_filesize` / `post_max_size` 与实际加载的 ini 路径（本工程已把 Laravel 默认英文 `The audio failed to upload.` 中文化）。平时要查看 FPM 生效值，用上方 `_ini.php` 临时探针（用完删除）。
- 区分 PHP 层的另两种症状：`post_max_size` 过小 → `$_FILES` 为空、Laravel 报「音频文件 必填」；`upload_max_filesize` 过小 → `$_FILES['audio']['error'] = 1`。两者都不会是 413。

#### 域名与 HTTPS：api.mixyj.cn

小程序 `miniprogram/services/config.js` 的 `API_BASE` 与 `.env.example` 的 `APP_URL` 都写死 `https://api.mixyj.cn`，**这个域名不通 HTTPS，真机体验版所有请求都会失败**。现成配置在交付目录 `deploy/nginx/api.mixyj.cn.conf`（不在 `backend/` 内），按现有 `php8.4-fpm` socket 与 `root /var/www/listen-book/public` 对齐，保留原有 PHP 转发写法，只补 80 跳转、443、ACME 校验与 `fastcgi_read_timeout 300`。

**只需要 `api.mixyj.cn` 一个域名**：后台 `/admin/login` 与接口 `/api/v1/*` 由 `routes/web.php`（`admin.php`）和 `routes/api.php`（`api.v1.php`）挂到同一个 Laravel 入口 `public/index.php`，nginx 里一个 `server_name` + `location / try_files` 就能同时服务两者，不需要给后台单独开域名或 server。`downloadFile` 合法域名填 COS 桶域名是音频直链的用途，与业务域名无关。

微信官方对证书的要求：**必须被系统信任**（不能用自签证书）、**证书域名与访问域名一致**、**在有效期内**、**服务器配置的信任链完整**、**TLS 1.2 及以上**（iOS 还需满足 ATS）。因此 nginx 里 `ssl_certificate` 必须用 `fullchain.pem`（或腾讯云下载的 `_bundle.crt`），只配站点证书 `cert.pem` 会因链不完整在 iOS 上失败；`ssl_protocols TLSv1.2 TLSv1.3;` 即可，不要为兼容旧 Android 去开 TLS 1.0/1.1。

配置必须分三阶段。**先把含 443 的 `api.mixyj.cn.conf` 放上去，会在证书签发前就报 `cannot load certificate ".../fullchain.pem": No such file or directory`**；此时 `nginx -t` 失败会让 `&&` 后面的 `reload` 不执行，线上仍跑旧配置、服务不中断，按阶段 1 换成纯 80 的 `00-http-only.conf` 即可恢复。

```bash
# ===== 阶段 0：DNS + 防火墙 =====
# mixyj.cn 加一条 A 记录 api → 43.133.250.32；腾讯云轻量控制台「防火墙」放行 TCP 443
dig +short api.mixyj.cn
sudo ufw allow 80/tcp && sudo ufw allow 443/tcp

# ===== 阶段 1：纯 80 引导 =====
# 本地执行：scp deploy/nginx/00-http-only.conf ubuntu@43.133.250.32:/tmp/listen.conf
sudo cp /tmp/listen.conf /etc/nginx/sites-available/listen
sudo nginx -t && sudo systemctl reload nginx
curl -I http://api.mixyj.cn
# 确认 ACME 目录可读（输出 ok 再签证书）
sudo mkdir -p /var/www/listen-book/public/.well-known/acme-challenge
echo ok | sudo tee /var/www/listen-book/public/.well-known/acme-challenge/probe >/dev/null
curl -s http://api.mixyj.cn/.well-known/acme-challenge/probe
sudo rm -f /var/www/listen-book/public/.well-known/acme-challenge/probe

# ===== 阶段 2：签证书（snap 安装，自带 systemd timer 续期）=====
sudo apt update && sudo apt install -y snapd
sudo snap install core && sudo snap refresh core
sudo snap install --classic certbot
sudo ln -sf /snap/bin/certbot /usr/bin/certbot
sudo certbot certonly --webroot -w /var/www/listen-book/public -d api.mixyj.cn \
  --agree-tos -m 你的邮箱 --no-eff-email
sudo certbot renew --dry-run

# ===== 阶段 3：换成完整配置启用 HTTPS =====
# 本地执行：scp deploy/nginx/api.mixyj.cn.conf ubuntu@43.133.250.32:/tmp/listen.conf
sudo cp /tmp/listen.conf /etc/nginx/sites-available/listen
sudo nginx -t && sudo systemctl reload nginx
```

> `00-http-only.conf` 不做 301 跳转、保留 PHP 转发，签证书期间后台仍可用 http 访问；换成 `api.mixyj.cn.conf` 后 80 才统一跳 https。
> 若用腾讯云免费 DV 证书替代 certbot：控制台申请后下载 **nginx 版**，把 `*_bundle.crt`（含中间链）与 `*.key` 放到 `/etc/nginx/ssl/`，改配置里那两行路径，跳过阶段 2，其余相同。
> 大陆服务器要求域名已做**腾讯云接入备案**，未备案时 80/443 会被拦截；`mixyj.cn` 已备案，新增子域名一般直接继承，若 80/443 不通先查备案接入商。

最后三项：

- mp 后台「request 合法域名」填 `https://api.mixyj.cn`（不带路径、不带端口，微信不支持父域名/泛域名）；`downloadFile` 填 COS 域名，见第 6 节。
- `.env` 设 `APP_ENV=production`、`APP_DEBUG=false`、`APP_URL=https://api.mixyj.cn`，执行 `php artisan config:clear`。`AppServiceProvider` 在 production 会 `URL::forceScheme('https')`，配合 nginx 的 `fastcgi_param HTTPS on`，后台生成的链接不会退回 http。
- 验证：
  ```bash
  curl -I http://api.mixyj.cn                      # 301 → https
  curl -s https://api.mixyj.cn/api/v1/bootstrap   # {"code":0,...}
  openssl s_client -connect api.mixyj.cn:443 -servername api.mixyj.cn </dev/null 2>/dev/null | openssl x509 -noout -subject -dates
  ```

## 3. 上传排错

上传封面 / 音频失败时，错误信息会直接显示在后台表单顶部，先看这张表：

| 现象 | 根因 | 处理 |
| --- | --- | --- |
| **上传音频直接返回 `413 Request Entity Too Large`** | nginx `client_max_body_size` 默认 1m，请求体在到达 PHP 前被拒；Laravel 收不到请求、日志无记录。**封面能传、音频不能传**就是这个特征 | 按第 2 节「上传大小」改 nginx + php.ini 并 reload 两者 |
| **`The audio failed to upload.`（英文）/ `上传失败：…`（中文诊断）** | 请求已到 Laravel，但 `$_FILES['audio']['error'] != 0` → 验证器自动触发 `uploaded` 规则。最常见是服务器 `upload_max_filesize` 仍是 2M，或改了 php.ini 没 reload php-fpm；`post_max_size` 过小则表现为 419 或「必填」 | 按失败提示里给出的 php.ini 绝对路径修改（**不是** CLI 那份）并 `sudo systemctl reload php8.x-fpm`；命令行 `php -i` 显示 120M 而失败提示 2M，就是改错了文件，详见第 2 节。若仍显示英文提示，说明服务器跑的是旧代码，需先部署 `app/Http/Requests/` 下的文件 |
| `Error retrieving credentials from the instance profile metadata service`、`169.254.169.254`、`cURL error 28` | `.env` 里 `COS_SECRET_ID` / `COS_SECRET_KEY` 为空。Laravel 只在两者都非空时才把 `credentials` 传给 AWS SDK；缺了 SDK 就走默认凭证链去找 EC2 实例元数据，本地与轻量服务器没这个服务 → 1 秒超时 | 填真实密钥后 `php artisan config:clear`；或改 `AUDIO_DRIVER=local` 走挂载目录 |
| `对象存储未配置：.env 里的 COS_XXX 为空` | 本工程的前置校验提前拦下（不会再等 IMDS 超时） | 同上 |
| `COS_BUCKET 仍是 .env.example 的模板占位值` | 直接复制模板，没改桶名 | 把 `your-bucket-1250000000` 换成控制台真实桶名，`COS_CDN_DOMAIN` 同步换 |
| **上传成功，但图片裂开 / 音频 403 `AccessDenied`（XML）** | ① 桶默认「私有读写」；② **桶已公有读、但每个新对象仍 403** → 旧代码经 Flysystem 带了 `x-amz-acl: private`，对象级 ACL 覆盖桶权限。换个不存在的 key 返回 `404 NoSuchKey` 可反证对象已写入 | ① 控制台把桶改「公有读私有写」；② 部署 `AudioStorageService` 新代码（上传不再发 ACL 头），并对**历史对象**跑 `php scripts/fix-cos-object-acl.php --apply` 或控制台批量改「继承权限」，详见第 2 节「对象权限」 |
| `PolicyFull` / `your policy or acl has reached the limit` | 给每个对象写了文件级 ACL / Policy，单主账号规则总数超过 1000 条 | 删掉多余对象 ACL（或把对象 ACL 置回 `default` 继承）；代码里**不要**传 `visibility` / `ACL`，权限统一由桶级「公有读私有写」或一条桶策略控制，见第 2 节「对象权限」 |
| `403 SignatureDoesNotMatch` / `400 InvalidArgument` / `501 Not Implemented` | 该桶 / 地域不兼容 AWS S3 签名（本桶实测 `PutObject` 已通过，换桶后才可能遇到） | 先试 `COS_USE_PATH_STYLE=true`；仍失败切路径 B（`AUDIO_DRIVER=local`） |
| `NoSuchBucket` / `NoSuchKey` / 404 | 桶名 / 地域 / key 拼错；`COS_BUCKET`、`COS_REGION`、`COS_ENDPOINT`、`COS_CDN_DOMAIN` 必须同地域同桶 | 核对四项配置；控制台文件列表确认对象路径 |
| **换桶后封面全部打不开 / 历史音频 404** | 只改了 `.env` 没搬对象（key 不变复制到新桶即可）；或漏了 `php artisan cache:clear`，API 还在吐缓存里 1 小时前的旧桶 URL；封面还需 `php artisan migrate` 完成 `cover_key` 回填 | 按第 2 节「换桶 / 从轻量存储迁到标准 COS」搬对象、跑迁移、清 `config` + `cache` 两个缓存 |
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
| GET | `/api/v1/bootstrap` | 无 | 版本戳 + 公告 + 内容层名（`content.level_names` / `max_depth`） |
| GET | `/api/v1/catalog/version` | 无 | 版本戳（落库 `catalog_meta`，只增不减） |
| GET | `/api/v1/albums?page=&per_page=` | 无 | 已上架专辑 |
| GET | `/api/v1/albums/{id}/tracks?page=&per_page=` | 无 | 播放列表，按 sort_order |
| GET | `/api/v1/me/histories` | Bearer | 播放历史 |
| POST | `/api/v1/me/histories` | Bearer | `{track_id, progress_sec, duration_sec}` upsert |
| DELETE | `/api/v1/me/histories/{trackId}` | Bearer | 删单条 |
| DELETE | `/api/v1/me/histories` | Bearer | 清空（v0.12 起连带清空 `play_events`，统计/徽章同步重置） |
| GET | `/api/v1/me/stats` | Bearer | v0.12 聚合统计：成绩单、累计收听、连续天数、84 天热力数据、专辑完成度、最近续播；**v0.12.1 起小程序只消费成绩单/专辑/续播字段，真实收听 4 字段保留兼容，接口零改动** |
| POST | `/api/v1/me/play-events` | Bearer | v0.12 批量收听事件（≤50 条/次），`client_event_id` 幂等；返回 `accepted/duplicated/rejected` |

后台（session + CSRF）：

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET/POST | `/admin/login` | 登录 |
| POST | `/admin/logout` | 退出 |
| GET/POST | `/admin/albums` | 专辑列表 / 新建；列表行内「编辑」可改名称、简介、封面、上下架；「上架音频」列只统计 `status=1`，与 `/api/v1/albums` 的 `track_count` 同口径 |
| PATCH | `/admin/albums/{id}` | `{title, description, cover?, status}`，封面留空不修改 |
| DELETE | `/admin/albums/{id}` | 级联移除专辑 + 其下全部音频 + 对应桶对象（不可恢复） |
| POST | `/admin/albums/reorder` | `{album_id, target_id}` |
| GET/POST | `/admin/tracks` | 音频列表（搜索/试听）/ 上传；列表行内「编辑」可改标题、封面 |
| PATCH | `/admin/tracks/{id}` | 标题、封面、上下架 |
| DELETE | `/admin/tracks/{id}` | 软删音频 + 同步删桶内音频与封面对象 |
| POST | `/admin/tracks/reorder` | `{album_id, track_id, target_id}`，插到 target 之前，target 为空则移到末尾 |

`target_id` 语义：**移动到该项之前**；为空或 0 → 移到末尾。

分组以数据库为准：`/admin/tracks/reorder` 的 `album_id` 仅作参考，实际按被移动音频**当前所属专辑**排序；`target_id` 已不在该专辑（页面数据过期、行被删除或移出）时**不返回 422**，而是退化为「移到该专辑末尾」并返回 `{ok:true, degraded:true, message}`，前端据此提示刷新。`/admin/albums/reorder` 同理（目标专辑失效 → 移到末尾）。

后台拖拽的落点决定方向：落在目标行的**上半部分**＝插到该行之前，**下半部分**＝插到该行之后；落在最后一行下半部分即移到末尾（前端传 `target_id: null`）。音频列表按 `album_id` + `sort_order` 排序后**单表分页**（每页 30 条），不再按专辑分组渲染——分组原先做在分页之后，同一专辑跨页会出现两个同名表头。跨专辑拖拽在前端直接拒绝（`dragover` 给「禁止」光标、落下时提示），后端也按音频**当前所属专辑**重排，不会把音频拖到别的专辑里。

### 目录版本号（`catalog_version`）

客户端列表缓存的有效性完全靠这个数字判断：小程序每次进列表页先取 `/catalog/version`，与本地缓存的版本号**严格相等**才复用，否则重新拉取。所以它必须**单调递增**。

- 值存在 `catalog_meta` 表（单行 `key='catalog_version'`），后台任何写操作（上传 / 编辑 / 上下架 / 删除 / 拖拽排序）成功后 `value = value + 1`。
- **不能存进 Cache**。`php artisan cache:clear`、`optimize:clear` 都会把它清掉，旧的 `Cache::get(key, 1)` 会回退到 1；客户端手里那个更大的旧号一旦和服务端重新涨上来的值撞上，就会命中旧列表、从此不再刷新 —— 表现就是「后台拖了顺序，小程序纹丝不动」。
- 迁移插入的初始值是**当前时间戳**（约 1.79e9），远大于历史上用过的 1..N，上线后所有客户端必然强制刷新一次。
- 版本号同时是服务端列表缓存的命名空间（`catalog:v{version}:albums`），bump 后自然读新缓存，无需逐项清理。
- 漏跑 `php artisan migrate` 不会 500：`version()` 退化为时间派生值（每次都不同 → 等于禁用缓存），日志里会有一条「版本表不可用」的 warning。看到它就去补迁移。

---

## 5. 上传实现说明

- 单文件 `multipart` 直传，扩展名白名单 `mp3/m4a/aac/wav` + 文件头签名校验 + ≤100MB；object key 由服务端用 ULID 生成，不使用原始文件名。
- **时长由上传页浏览器**通过 `<audio>` 的 `loadedmetadata` 读取后随表单提交（`duration_sec`），服务端不依赖 ffmpeg / 队列；读不到则存 `null`，小程序显示 `--:--`。
- 上传失败会清理已写入 COS 的孤儿对象；上传走底层 SDK 不发 ACL 头，对象继承桶的「公有读私有写」。
- **删除会同步清理桶对象**：移除音频时删 `object_key` + 封面 key；移除专辑时在 DB 事务里软删专辑及其全部音频（含此前已软删记录）后，批量删除专辑封面、所有音频与封面的桶对象。DB 仍是软删（避免播放历史出现孤儿引用），但**桶文件已删除、不可恢复**，后台提示会显示实际清理的对象数，若少于预期请到控制台核对残留。
- `AudioStorageService::deleteMany()` 用 `CoverUrl::keyFrom()` 把相对 key 与历史完整 `cover_url` 统一归一化并去重；单个对象删除失败（已不存在 / 权限不足）不影响其余，也不阻断后台请求。S3 与 `local` 两种驱动都走 `Storage::disk('cos')->delete()`。
- 列表行内编辑封面时**留空表示不修改**；换新封面只覆盖 `cover_key`（对外 `cover_url` 由模型 accessor 动态生成），旧的 COS 对象保留（不做自动清理）。编辑表单放在独立的非拖拽行里，避免与拖动排序抢事件。
- **封面存 object key，不存完整 URL**：`albums` / `tracks` 的 `cover_key` 由后台上传写入，`cover_url` 由 `App\Support\CoverUrl::resolve()` 在读取时用 `COS_CDN_DOMAIN` 拼出；API、后台 Blade、小程序都读 `cover_url`，契约不变。
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

**v0.12「我的」模块升级（必须执行一次迁移）**：

```bash
php artisan migrate          # 只新增 play_events 表，不改既有表；可 migrate:rollback 回滚
php artisan optimize:clear   # 清路由/配置缓存，确保 /me/stats、/me/play-events 生效
php artisan route:list --path=me   # 验收：应看到 6 条 /me 路由
php artisan test             # 28 passed / 144 assertions（含事件幂等、补记截断、跨日拆分、清空联动）
```

事件口径：`start` 只建基准、进度跳变与暂停不计入、`catchup` 按 `min(真实经过时间, 曲目剩余时长)` 截断、跨北京自然日拆分归属、用户身份只取 token。上线前的历史收听不回填；详情见 `docs/我的模块/需求规格说明书(SRS)_听书小程序我的模块.md` 与 `docs/ARCHITECTURE.md` ADR-14。

---

## 8. 已知限制（务必知悉）

1. **个人主体 + 音频/有声读物类目未开放**，且 `requiredBackgroundModes` 正式版需审核 → 当前只保证体验版可用，正式发布大概率被驳回。
2. `wx.login` 的 code 有效期 5 分钟、只能用一次；小程序 `services/auth.js` 在每次冷启动与 401 时重新换取 token。
3. 后台播放期间微信不回调 `onTimeUpdate`，**播放进度只在小程序前台时上报**；切歌 / 暂停 / 回前台时补报，因此后台播放的历史进度会滞后于实际播放位置。
4. `BackgroundAudioManager.setShutdown` 官方文档缺少 `time` 单位与 `autoNext` 语义细项，小程序侧做了能力探测 + JS 兜底，需在真机确认后按实测调整。
5. 音频 URL 为公有读长期地址（当前用 bucket 默认访问域名；若控制台支持自定义域名则优先换 `cos.mixyj.cn`，体验域名官方限制 10QPS、无加速）；若改短时效签名 URL，后台连续播放超时会断播。公有读有流量盗刷风险，注意套餐流量。
6. 后台登录为单管理员 + session，无 RBAC / 操作审计；管理接口依赖 HTTPS 保护。
7. v0.12 的「累计收听 / 连续天数 / 热力格」依赖小程序回到前台时补记：被系统回收后未再打开、或后台跨曲自动连播的中间曲目可能漏记，属已知少量误差；明细 `play_events` 不自动清理，单用户超 50 万条时再评估归档。**v0.12.1（CR-01）起小程序「我的」页已移除该展示区块，但表、接口、打点与清空联动全部保留**，服务端无需为 v0.12.1 重新发布；是否彻底下线采集链路见需求变更单 Q-11。
