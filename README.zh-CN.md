<h1 align="center">Wallpaper API</h1>

<p align="center">
  <a href="https://github.com/haoyuqi/wallpaper-api/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/haoyuqi/wallpaper-api/actions/workflows/ci.yml/badge.svg?branch=master"></a>
  <a href="https://github.com/haoyuqi/wallpaper-api/actions/workflows/ci.yml?query=branch%3Amaster"><img alt="覆盖率" src="https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fhaoyuqi%2Fwallpaper-api%2Fcoverage-badge%2Fcoverage.json"></a>
  <a href="https://laravel.com/docs/13.x"><img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white"></a>
  <a href="LICENSE"><img alt="MIT 许可证" src="https://img.shields.io/badge/license-MIT-blue.svg"></a>
  <a href="https://github.com/haoyuqi/wallpaper-api/releases/latest"><img alt="最新版本" src="https://img.shields.io/github/release/haoyuqi/wallpaper-api.svg"></a>
</p>

<p align="center">
  <a href="README.md">English</a> | 简体中文
</p>

基于 Laravel 13 的 API，用于收集 Bing 壁纸元数据并按日期提供查询。服务保存规范化后的元数据与原始源 JSON；同步过程不会下载图片。

## 功能

- `GET /api/v1/wallpapers` 支持单个精确日期或包含两端的日期区间，只返回公开元数据。
- `php artisan wallpapers:sync --date=YYYY-MM-DD` 调用已安装的 `download-bing-wallpaper` 命令，校验它输出的 JSON，并记录每一次同步尝试。
- 按来源、市场、日期与条目 ID 做幂等写入，壁纸记录与审计记录在同一事务内完成。
- 输入错误、路由不存在、方法不允许、限流与意外失败都返回稳定的 JSON 错误结构，内部诊断信息不会出现在响应中。
- 按 IP 限流、公开读取的免凭据 CORS，以及 `/up` 健康检查。

## 环境要求

- PHP 8.4.x（8.4.27 或更高），或 PHP 8.x 中的 8.5.12 及更高版本
- Composer 2
- 默认本地配置需要 PDO SQLite；使用 PostgreSQL 时需要 PDO PostgreSQL
- CI 使用 PostgreSQL 18 测试，同时支持 SQLite

CI 与生产镜像使用 PHP 8.4 构建。Composer 也允许 PHP 8.5.12 及更高版本，但本仓库不声明单独的 PHP 8.5 CI 覆盖。可以使用本地 PHP 或自选容器，并在仓库根目录执行下面的命令。

## 快速开始

```bash
git clone https://github.com/haoyuqi/wallpaper-api.git
cd wallpaper-api
composer install
composer check-platform-reqs
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

示例配置使用 SQLite。使用 PostgreSQL 时，请在本地 `.env` 中设置连接，并在迁移前创建应用数据库；不要把凭据提交到版本控制。开发服务器监听 `http://127.0.0.1:8000`，可用 `GET /up` 确认应用能否启动。生产环境不要使用 `artisan serve`。

## 配置

| 变量 | 默认值 | 用途 |
| --- | --- | --- |
| `APP_URL` | `http://localhost` | 部署的公开源地址。 |
| `DB_CONNECTION` | `sqlite` | 数据库驱动：`sqlite`、`pgsql` 或 `mysql`。 |
| `DB_DATABASE`、`DB_HOST`、`DB_PORT`、`DB_USERNAME`、`DB_PASSWORD` | SQLite 文件 | 所选驱动的连接配置。 |
| `CORS_ALLOWED_ORIGINS` | `*` | 允许读取 API 的来源，逗号分隔；留空则关闭跨域读取。 |
| `WALLPAPER_REQUESTS_PER_MINUTE` | `60` | 公开读取的每 IP 请求预算。 |
| `TEST_DB_CONNECTION`、`TEST_DB_*` | PostgreSQL `wallpaper_api_test` | PHPUnit 专用连接；凭据保存在被忽略的 `.env` 中。 |

## 同步元数据

```bash
php artisan wallpapers:sync --date=2026-10-01
```

命令调用已安装的 [download-bing-wallpaper](https://github.com/haoyuqi/download-bing-wallpaper) 3.x Artisan 命令，并校验其 JSON 响应。重复成功查询同一来源标识会更新同一条记录，每次查询都会记录一次同步尝试。Bing 可能没有指定日期的数据。

| 退出码 | 含义 |
| --- | --- |
| `0` | 同步成功，或数据源在该日期没有壁纸。 |
| `1` | 数据源或持久化失败。 |
| `2` | 日期缺失或无效。 |

输出会给出处理结果和审计记录 ID，不包含原始文档或异常详情。

## 查询元数据

```http
GET /api/v1/wallpapers?date=2026-10-01
GET /api/v1/wallpapers?from=2026-10-01&to=2026-10-07
```

| 参数 | 要求 |
| --- | --- |
| `date` | 精确日期查询；不要再传 `from` 和 `to`。 |
| `from`、`to` | 包含两端的区间；两者都必填，且 `from` 不能晚于 `to`。 |

日期必须是真实存在的 `YYYY-MM-DD` 日历日期，且不能带首尾空白。结果按来源日期和 ID 降序排列，只包含公开字段。有效查询返回 HTTP 200，包括结果为空的情况；无效参数返回 HTTP 422 和稳定的错误码。查询只读取已存储的数据，不会访问 Bing。

公开读取默认每个 IP 每分钟 60 次，超限返回 HTTP 429 和 `Retry-After`。第一版没有认证、标签、关键词过滤或分页，因此较大的区间可能产生较大的响应。

## 每日同步

已注册的任务在 UTC 12:00 运行，请求前一个 UTC 日期。部署时需要在单台调度主机上每分钟执行一次 `schedule:run`；重叠锁使用文件缓存，因此该存储必须可写且持久。

```bash
php artisan schedule:list
```

## 测试与质量门禁

| 命令 | 用途 |
| --- | --- |
| `composer validate --strict` | 校验 Composer 清单。 |
| `vendor/bin/pint --test` | 使用 Laravel 预设检查格式。 |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=512M` | 以 Larastan level 5 检查 `app/`、路由、启动配置与数据库定义。 |
| `vendor/bin/phpunit` | 在专用测试连接上运行测试套件。 |
| `composer audit --locked --no-interaction` | 审计锁定的生产与开发依赖。 |

本地默认套件使用 PostgreSQL，且不会请求真实 Bing；SQLite 可作为快速替代，但会跳过 PostgreSQL 专用断言。请把 `.env.example` 复制为 `.env`，并在其中设置 `TEST_DB_USERNAME` 与 `TEST_DB_PASSWORD`；`phpunit.xml` 提供非机密的测试默认值，无需 `.env.testing`。测试基类会拒绝 `wallpaper_api_test` 以外的 PostgreSQL 数据库名。

CI 要求 `app/` 的 PHPUnit 行覆盖率恰好为 100%，不排除任何文件，也不做四舍五入。上方覆盖率徽章会在默认分支每次成功后更新。

## 部署

[生产镜像](Dockerfile) 使用 PHP 8.4 与 Apache，以非 root 用户运行并监听 8080。镜像不含凭据与开发依赖，也不会自动启动调度器或执行迁移。CI 会构建镜像，并用一次性的 PostgreSQL 18 数据库验证它。

运行时凭据、TLS 与代理、持久化锁、迁移、健康检查、备份与回滚见[运维](#运维)一节。非 Docker 部署请使用生产 PHP 执行 `composer install --no-dev` 与 `composer check-platform-reqs --no-dev`。`/up` 只能确认应用已启动，不能证明数据库可用或同步数据是最新的。

## 运维

- 在单台调度主机上每分钟执行一次 `schedule:run`。重叠锁使用文件缓存，因此该主机上的 `storage/framework/cache/data` 必须持久化，并在发布之间共享。
- 使用 `pg_dump --format=custom` 备份，并通过恢复到独立数据库、检查迁移状态与行数来验证可恢复性。`APP_KEY` 需在数据库备份之外单独保存。
- 回滚时恢复上一个已验证的镜像 digest，保持相同的 `APP_KEY` 与兼容的表结构。生产环境不要执行 `migrate:fresh` 或删除表的回滚。
- 关注 HTTP 5xx/429 比例、worker 内存、调度执行、失败审计码与备份时间。`/up` 只能证明应用已启动。
- 内容下架请求应私下处理并保留最简决策记录；不要承诺删除第三方副本。

## 许可证

项目代码采用 [MIT 许可证](LICENSE)。第三方图片与来源内容保留各自权利，代码许可证不授予这些内容的使用权。
