# Laravel 10 后端（全新独立工程）

这是一个**完整、可直接运行的 Laravel 10 项目**，不是增量补丁：官方 10.x 骨架（`artisan` / `bootstrap/` / `config/` / `app/Http/Kernel.php` / `public/` / `storage/` / `tests/`）已齐备，业务模块（听书 API、微信登录、播放历史、COS 上传、Blade 管理后台）也已接入配置与路由。把整个 `backend/` 目录作为项目根目录部署即可，`composer install` 后**无需再手工合并任何文件**。

服务器 `43.133.250.32`｜域名 `api.mixyj.cn`｜AppID `wxd1742cec14ff6355`｜存储 腾讯云 COS（S3 兼容）

---

## 1. 安装与部署

完整步骤见**项目交付目录**的 `docs/后台部署文档.md`（Laravel 工程根下没有 `docs/`，以交付包中的副本为准）：环境要求、首次部署、`.env` 全部变量、nginx / PHP 上传限制、HTTPS 签发、版本升级、排障与备份。本节只留最快上手路径，避免出现两套说法。

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret          # 后台 JWT 签名密钥，独立于 APP_KEY，只生成一次
# 编辑 .env：DB_* / WX_SECRET / JWT_* / BRAND_* / CONTENT_* / COS_*
php artisan migrate --force
php artisan admin:create        # admins 表初始为空，不建进不去后台
php artisan optimize:clear
```

```bash
chmod -R ug+rw storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache   # 按实际 PHP-FPM 运行用户调整
```

后台入口 `/admin/login`，用 `admin:create` 创建的**用户名或手机号 + 密码**登录，登录后签发 JWT（`Authorization: Bearer`）。其余管理员在后台「管理员」页维护。

首次 `migrate` 建 13 张表：Laravel 标准 4 张（`users`、`password_reset_tokens`、`failed_jobs`、`personal_access_tokens`）+ 业务 6 张（`albums`、`tracks`、`wx_users`、`play_histories`、`play_events`、`catalog_meta`）+ 后台 3 张（`admins`、`admin_refresh_tokens`、`admin_operation_logs`）。

**升级已有部署**：`git pull` → `php artisan migrate --force` → `php artisan optimize:clear` + `view:clear` → 浏览器强刷 → 按部署文档第 8 节冒烟。各版本差异（v0.14.0 引入 JWT、v0.14.1 彻底删与日志、v0.15.0 换专辑 / 批量上传 / 数据面板）见部署文档第 7 节。

> ⚠️ 升级 v0.14.1 前**必须备份 `albums` / `tracks`**：迁移会先清历史软删行、再删 `deleted_at` 列，之后内容删除彻底不可恢复。

---

## 2. 配置要点

全部变量及含义见部署文档第 3 节。三条最容易踩的：

- **品牌与层名**：`BRAND_*` 控制站名 / Logo / 主色，`CONTENT_*` 控制「专辑 / 音频」这类显示称呼。只影响文案，不改 API 路径与表结构；小程序端由 `GET /api/v1/bootstrap` 自动跟上，不需要发版。改完 `config:clear`。
- **`COS_CDN_DOMAIN`** 是音频与封面的对外根地址。封面存 `cover_key`（object key），URL 动态拼，所以换桶/换域名只改这一项，**不用刷库**。
- **`JWT_SECRET` 只生成一次**。轮换会让所有已签发的 access token 立即失效（不损坏数据，但所有人需重新登录）。

工程内已接好的部分（不用手工追加片段）：

- `composer.json`：`laravel/framework ^10.10`、`laravel/sanctum ^3.2`、`tymon/jwt-auth ^2.1`、`league/flysystem-aws-s3-v3 ^3.0`、`guzzlehttp/guzzle`
- `config/auth.php`：`wx` guard = sanctum + `wx_users` 提供者（小程序）；`admin` guard = jwt + `admins` 提供者（后台）；两者与 `web` session 完全隔离
- `config/jwt.php`：`ttl` / `refresh_ttl` 读 `.env` 的 `JWT_TTL` / `JWT_REFRESH_TTL`
- `config/filesystems.php`：`cos` 磁盘（`s3` / `local` 由 `AUDIO_DRIVER` 切换）；`s3` 分支显式设 `credentials => false`，避免密钥缺失时 AWS SDK 回退实例元数据服务（`169.254.169.254`）
- `config/services.php`：`wechat` / `cos.cdn_domain` / `miniapp.announcement`（v0.14.0 起已移除 `admin.password`，后台认证走 `admins` 表）
- `config/brand.php`：`View::share('brand', config('brand'))`，Blade 写 `$brand['name']` / `logo` / `color`，视图不出现站名与色值字面量
- `config/content.php`：`View::composer('admin.*')` 每次渲染注入 `$levels`。**不能用 `View::share`**——那会在 `boot()` 取一次值就固化，改配置视图不跟
- `routes/api.php` → `api.v1.php`（小程序接口）+ `api.admin.php`（`/api/admin/*`，Bearer JWT）
- `routes/web.php` → `admin.php`（`web` 组：session / cookie / CSRF），`/` 重定向到后台音频页。**`/admin/*` 只返回页面骨架，不做服务端鉴权**，数据由 `public/js/admin.js` 带 Bearer 调 `/api/admin/*`
- `phpunit.xml`：测试用 `sqlite :memory:` + `array` 缓存 + 固定 `JWT_SECRET`，`php artisan test` 不依赖 MySQL / Redis / COS

> 若执行过 `php artisan config:cache`，改完 `.env` 必须 `config:clear`（或重新 `config:cache`），否则读到旧值。切换 `AUDIO_DRIVER` 同理。

---

## 3. 排障入口

按症状查部署文档：

| 症状 | 去哪看 |
| --- | --- |
| 上传报 413 / `The audio failed to upload.` / 报「文件必填」 | 第 5 节（nginx 与 PHP 三层限制）+ 第 9.3 节 |
| 上传成功但图片裂开、音频 403 | 第 4.1 / 4.2 节（桶权限、对象 ACL） |
| `cURL error 28` / `169.254.169.254` | 第 3.7 节（COS 凭据为空）+ 第 9.3 节 |
| 换桶后历史音频 404 | 第 4.3 节 |
| 登录被踢 / 401 / 403 错误码 | 第 9.1 节 |
| 后台页面空白、弹窗无反应 | 第 9.2 节（多为 `admin.js` 缓存） |
| 后台改了内容小程序不刷新 | 第 9.4 节（`catalog_meta` 版本号） |
| 忘了管理员密码 / 账号被锁 | 第 10.4 节 |

**跑测试前务必 `php artisan config:clear`**：本机 `.env` 直连生产库，配置缓存存在时 `phpunit.xml` 的 sqlite 覆盖会失效。`tests/CreatesApplication.php` 已有硬护栏（检测到配置缓存或默认连接非 sqlite `:memory:` 直接抛异常终止），**不要绕过**。详见部署文档第 10.6 节。

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

后台页面（v0.14.0 起只返回 Blade 骨架，不做服务端鉴权；无 token 时页面内联脚本跳登录）。`/admin/admins` 与 `/admin/logs` 由服务端在 `<html>` 打 `data-lb-role-gate`，CSS 默认隐藏页面主体，页面加载后由 `js/admin.js` 调 `/api/admin/auth/me` 取**库中实时 role** 确认是 super 才放开，否则跳回音频页；角色被改动后无需重新登录。前端这层只是 UX，真正边界在 `/api/admin/*` 的 `EnsureSuperAdmin`：

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/admin/login` | 登录页（账号 + 密码，JS 提交到 `/api/admin/auth/login`） |
| GET | `/admin/tracks` | 音频页骨架 |
| GET | `/admin/albums` | 专辑页骨架 |
| GET | `/admin/admins` | 管理员页骨架（前端角色门禁，数据接口仅 super） |
| GET | `/admin/logs` | 操作日志页骨架（前端角色门禁，数据接口仅 super） |

后台接口（`Authorization: Bearer <access_token>`）：

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | `/api/admin/auth/login` | `{account, password}`，account 可为用户名或手机号 → `{access_token, refresh_token, expires_in, admin}` |
| POST | `/api/admin/auth/refresh` | `{refresh_token}` → 新的 access + refresh，旧 refresh 立即作废 |
| POST | `/api/admin/auth/logout` | `{refresh_token}`，拉黑 access 并吊销 refresh |
| GET | `/api/admin/auth/me` | 当前管理员资料 |
| POST | `/api/admin/auth/password` | 改自己的密码（需旧密码），成功后旧 token 全部失效 |
| GET/POST | `/api/admin/albums` | 专辑列表 / 新建；列表行内「编辑」可改名称、简介、封面、上下架；「上架音频」列只统计 `status=1`，与 `/api/v1/albums` 的 `track_count` 同口径 |
| PATCH/POST | `/api/admin/albums/{id}` | `{title, description, cover?, status}`，封面留空不修改；**带封面时用 `POST` + `_method=PATCH`**（PHP 不解析 PATCH 的 multipart body） |
| DELETE | `/api/admin/albums/{id}` | **彻底删**：事务内清播放历史与打点事件 → 删其下全部音频 → 删专辑，提交后删桶对象（不可恢复） |
| POST | `/api/admin/albums/reorder` | `{album_id, target_id}` |
| GET/POST | `/api/admin/tracks` | 音频列表（`album_id` / `keyword` / `page` / `per_page`）/ 上传；行内「编辑」可改标题、封面 |
| PATCH/POST | `/api/admin/tracks/{id}` | 标题、封面、上下架；带封面时同样 `POST` + `_method=PATCH` |
| DELETE | `/api/admin/tracks/{id}` | **彻底删**：事务内清该曲目的播放历史与打点事件 → 删记录 → 删桶内音频与封面对象 |
| POST | `/api/admin/tracks/reorder` | `{album_id, track_id, target_id}`，插到 target 之前，target 为空则移到末尾 |
| GET/POST | `/api/admin/admins` | 管理员列表 / 新增（**仅 super**） |
| PATCH/POST | `/api/admin/admins/{id}` | 用户名、手机号、显示名、角色、状态（**仅 super**） |
| DELETE | `/api/admin/admins/{id}` | 软删并吊销其全部凭据；不能删自己，不能删最后一个超级管理员（**仅 super**） |
| POST | `/api/admin/admins/{id}/password` | 重置他人密码（不需旧密码），强制其重新登录（**仅 super**） |
| GET | `/api/admin/logs` | 操作日志分页查询，支持 `admin_id` / `action` / `success` / `from` / `to` / `keyword` / `page` / `per_page`（**仅 super**） |

后台错误码：`40101` 未登录 / token 无效、`40102` access 过期（前端据此自动 refresh）、`40103` 密码已变更或被强制下线、`40301` 账号被禁用、`40302` 角色不足（仅 super）、`42200` 参数校验失败、`42202` 状态约束、`42901` 连续 5 次失败锁定 15 分钟。

**角色与审计**：`EnsureSuperAdmin` 中间件把 `/api/admin/admins/*` 与 `/api/admin/logs` 限给 `super`，普通 `admin` 仍可管内容与排序。`LogAdminOperation` 挂在 `/api/admin` 组外层，**所有写请求自动落 `admin_operation_logs`**（登录失败、限流 429 也记；GET 与 `auth.refresh` 不记），密码 / token 不入库、上传文件只记文件名与大小。日志只追加，`/admin/logs` 提供只读筛选查看，不提供修改或删除入口。

`target_id` 语义：**移动到该项之前**；为空或 0 → 移到末尾。

分组以数据库为准：`/api/admin/tracks/reorder` 的 `album_id` 仅作参考，实际按被移动音频**当前所属专辑**排序；`target_id` 已不在该专辑（页面数据过期、行被删除或移出）时**不返回 422**，而是退化为「移到该专辑末尾」并返回 `degraded: true` + 提示文案，前端据此提示刷新。`/api/admin/albums/reorder` 同理（目标专辑失效 → 移到末尾）。

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
- **删除就是彻底删，并同步清理桶对象**：`albums` / `tracks` 已去掉 `SoftDeletes` 与 `deleted_at` 列。移除音频时在单个事务里删 `play_histories` / `play_events` 对应行 → 删曲目 → 提交后删 `object_key` + 封面 key；移除专辑时按 `track_id` 与 `album_id` 两个维度把关联历史 / 事件清干净，再删其下全部音频与专辑，最后批量删桶对象。**不可恢复**，后台提示会显示实际清理的对象数，若少于预期请到控制台核对残留。`admins` 仍保留软删，用于让操作日志能追溯已移除的账号。
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
php artisan test                      # 使用 sqlite :memory:，无需外部服务；当前 80 passed / 529 assertions
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

后台 JWT 冒烟（先在服务器 `php artisan admin:create` 建好账号）：

```bash
BASE=https://api.mixyj.cn

# 1. 无 token 应返回 401 / code 40101
curl -s -o /dev/null -w '%{http_code}\n' $BASE/api/admin/auth/me

# 2. 登录（account 可传用户名或手机号），取出 access_token
TOKEN=$(curl -s -X POST $BASE/api/admin/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"account":"你的用户名","password":"你的密码"}' \
  | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p')

# 3. 带 Bearer 访问受保护接口
curl -s $BASE/api/admin/auth/me -H "Authorization: Bearer $TOKEN"
curl -s $BASE/api/admin/albums -H "Authorization: Bearer $TOKEN"

# 4. 页面骨架（无 token 也能拿到 HTML，但页面内脚本会跳登录）
curl -s -o /dev/null -w '%{http_code}\n' $BASE/admin/tracks
```

端到端：后台上传一条音频 → 体验版列表可见 → 播放并产生历史 → 后台下架 → 体验版刷新后不可见 → 历史里该条自动隐藏。

**v0.12「我的」模块升级（必须执行一次迁移）**：

```bash
php artisan migrate          # 只新增 play_events 表，不改既有表；可 migrate:rollback 回滚
php artisan optimize:clear   # 清路由/配置缓存，确保 /me/stats、/me/play-events 生效
php artisan route:list --path=me   # 验收：应看到 6 条 /me 路由
php artisan test             # 当时的基线：28 passed / 144 assertions（含事件幂等、补记截断、跨日拆分、清空联动）
```

事件口径：`start` 只建基准、进度跳变与暂停不计入、`catchup` 按 `min(真实经过时间, 曲目剩余时长)` 截断、跨北京自然日拆分归属、用户身份只取 token。上线前的历史收听不回填；详情见 `docs/我的模块/需求规格说明书(SRS)_听书小程序我的模块.md` 与 `docs/ARCHITECTURE.md` ADR-14。

---

## 8. 后台运营能力（v0.15.0）

- `PATCH /api/admin/tracks/{id}` 新增可选 `album_id`：换专辑在单事务内回写 `play_histories` / `play_events` 的冗余 `album_id` 并重排 `sort_order`，`object_key` 与桶对象保持不变。
- 上传仍走 `POST /api/admin/tracks`，前端改为 XHR（`upload.onprogress`）串行队列；脚本版本 `admin.js?v=4`。
- `GET /api/admin/stats?days=30` 只读聚合（所有登录管理员可见），`AdminStatsService` 按 `tracks.album_id` 当前归属统计，SQL 内 `GROUP BY`；不返回 `play_count`。
- 创建入口（新建专辑 / 上传音频 / 新增管理员）放在各自列表表头的「操作」列内（`.th-actions` + 紧凑按钮），点击开原生 `<dialog>` 弹窗表单，`admin.js` 的 `adminUi.bindModal()` 管开关；顶部导航顺序为 专辑 → 音频 → 管理员 → 数据面板 → 操作日志，操作按钮靠左、项目名在右。纯前端改动，无迁移。
- 迁移 `2026_11_11_000001_add_stats_indexes` 只加索引：`git pull` → `php artisan migrate --force` → `php artisan view:clear` → 浏览器强刷拿 `?v=4`。

---

## 9. 已知限制（务必知悉）

1. **个人主体 + 音频/有声读物类目未开放**，且 `requiredBackgroundModes` 正式版需审核 → 当前只保证体验版可用，正式发布大概率被驳回。
2. `wx.login` 的 code 有效期 5 分钟、只能用一次；小程序 `services/auth.js` 在每次冷启动与 401 时重新换取 token。
3. 后台播放期间微信不回调 `onTimeUpdate`，**播放进度只在小程序前台时上报**；切歌 / 暂停 / 回前台时补报，因此后台播放的历史进度会滞后于实际播放位置。
4. `BackgroundAudioManager.setShutdown` 官方文档缺少 `time` 单位与 `autoNext` 语义细项，小程序侧做了能力探测 + JS 兜底，需在真机确认后按实测调整。
5. 音频 URL 为公有读长期地址（当前用 bucket 默认访问域名；若控制台支持自定义域名则优先换 `cos.mixyj.cn`，体验域名官方限制 10QPS、无加速）；若改短时效签名 URL，后台连续播放超时会断播。公有读有流量盗刷风险，注意套餐流量。
6. 后台采用管理员账号 + JWT（`admins` 表）；`role` 已由 `EnsureSuperAdmin` 判权，但**权限点只有两级**（`admin` 管内容、`super` 额外管账号与日志），尚未做按内容分权的细粒度 RBAC。操作审计已落 `admin_operation_logs`，但日志表本身可由能直连数据库的 super 篡改，且没有自动归档任务——需要不可篡改审计时改为写外部追加式日志。管理接口依赖 HTTPS 保护，token 存 `localStorage`（页面已统一转义，但未加 CSP 兜底）。
7. refresh token 默认 7 天有效且**刷新即轮换**，并发请求共用同一次刷新（前端已用单例 promise 去重）；若在多个浏览器同时登录同一账号，退出其中一处不会影响其他会话（各自持有独立的 refresh 记录），但改密或禁用会把所有会话一起踢掉。
8. v0.12 的「累计收听 / 连续天数 / 热力格」依赖小程序回到前台时补记：被系统回收后未再打开、或后台跨曲自动连播的中间曲目可能漏记，属已知少量误差；明细 `play_events` 不自动清理，单用户超 50 万条时再评估归档。**v0.12.1（CR-01）起小程序「我的」页已移除该展示区块，但表、接口、打点与清空联动全部保留**，服务端无需为 v0.12.1 重新发布；是否彻底下线采集链路见需求变更单 Q-11。
