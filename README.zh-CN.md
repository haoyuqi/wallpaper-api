# Wallpaper API

[![CI](https://github.com/haoyuqi/wallpaper-api/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/haoyuqi/wallpaper-api/actions/workflows/ci.yml?query=branch%3Amaster)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/docs/13.x)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Latest release](https://img.shields.io/github/v/release/haoyuqi/wallpaper-api)](https://github.com/haoyuqi/wallpaper-api/releases/latest)

[English](README.md) | [简体中文](README.zh-CN.md)

基于 Laravel 13 的 API 服务，用于收集 Bing 壁纸元数据并按日期查询。
服务保存元数据和源 JSON；同步操作不会下载图片。

## 环境要求

- PHP 8.4 或更高的 PHP 8.x 版本
- Composer 2
- 默认本地配置需要 PDO SQLite；使用 PostgreSQL 时需要 PDO PostgreSQL
- CI 使用 PostgreSQL 18 测试，同时支持 SQLite

可以使用本地 PHP 或自行选择容器环境。在对应环境的仓库根目录执行以下命令。

## 快速开始

```bash
git clone https://github.com/haoyuqi/wallpaper-api.git
cd wallpaper-api
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

示例配置默认使用 SQLite。使用 PostgreSQL 时，请在本地 `.env` 中配置数据库连接，
并在执行迁移前创建应用数据库。不要将凭据提交到版本控制。

本地服务默认地址为 `http://127.0.0.1:8000`。访问 `GET /up` 可以检查应用是否正常启动。
生产环境请使用正式的 Web 服务，不要使用 `artisan serve`。

## 同步元数据

```bash
php artisan wallpapers:sync --date=2026-10-01
```

命令调用已安装的
[download-bing-wallpaper](https://github.com/haoyuqi/download-bing-wallpaper)
3.x Artisan 命令，并验证其 JSON 响应。重复成功查询同一来源标识时会更新已有记录；
每次查询都会记录一次同步尝试。Bing 可能没有指定日期的数据。

退出码：成功或 `not_found` 为 `0`；数据源或持久化失败为 `1`；日期缺失或无效为 `2`。
输出包含处理结果和审计记录 ID，不包含原始文档或异常详情。

## 查询元数据

```http
GET /api/v1/wallpapers?date=2026-10-01
GET /api/v1/wallpapers?from=2026-10-01&to=2026-10-07
```

传入 `date`，或同时传入 `from` 和 `to`，日期必须是有效的 `YYYY-MM-DD` 日历日期。
日期范围包含两端。结果按来源日期和 ID 降序排列，仅包含公开元数据。

有效查询返回 HTTP 200，包括没有结果的情况。无效参数返回 HTTP 422 和稳定的错误码。
查询只读取已存储的数据，不会请求 Bing。参数、curl 示例、返回字段和错误说明见
[API 使用文档](docs/api.md)。

默认每个 IP 每分钟允许 60 次公开查询，超限返回 HTTP 429 和 `Retry-After`。
默认允许不携带凭据的公开跨域读取，可通过 `CORS_ALLOWED_ORIGINS` 和
`WALLPAPER_REQUESTS_PER_MINUTE` 配置。详见 [公开 API 防护](docs/operations/public-api.md)。

第一版不包含认证、标签、关键词过滤或分页。较大的日期范围可能产生较大的响应，
请按客户端需求选择合适的范围。

## 每日同步

已注册的任务在 UTC 12:00 执行，查询前一个 UTC 日期。
部署时需要在一个调度主机上每分钟执行一次 Laravel 的 `schedule:run`。
文件缓存锁需要可写且持久化的存储。

```bash
php artisan schedule:list
```

调度配置、锁、失败日志和恢复操作见 [每日同步说明](docs/operations/daily-sync.md)。

## 生产环境与发布

[生产镜像](Dockerfile) 使用 PHP 8.4 和 Apache，以非 root 用户运行并监听 8080。
镜像不包含凭据、开发依赖和历史图片，不会自动启动调度器或执行迁移。
CI 会构建镜像，并通过隔离的 PostgreSQL 18 数据库进行冒烟验证。

运行时凭据、TLS 与代理、持久化锁、迁移、健康检查、备份恢复和版本回滚见
[部署手册](docs/operations/deployment.md)。`/up` 只检查应用能否启动，不能证明数据库可用
或数据同步及时。实际托管和资源创建独立于代码发布。
发布前检查见 [发布清单](docs/release.md)，改动记录见 [CHANGELOG](CHANGELOG.md)。

## 测试与代码风格

```bash
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
vendor/bin/phpunit
composer audit --locked --no-interaction
```

默认测试使用隔离的内存 SQLite，此模式下会跳过 PostgreSQL 专用测试。
CI 在 PHP 8.4 上分别使用 SQLite 和 PostgreSQL 18 运行测试。
PostgreSQL 测试配置见 [贡献指南](CONTRIBUTING.md)，命名和可读性约定见
[代码风格](docs/code-style.md)。Pint 使用 Laravel 预设，不强制添加严格类型声明。
Larastan 以 level 5 检查应用代码、路由、启动配置和数据库定义，不使用忽略错误的 baseline。
CI 还会验证生产容器；自动化检查不会请求真实 Bing。

## 文档与支持

以下详细文档目前使用英文。

- [架构](docs/architecture.md)：职责、数据流和扩展边界
- [API 使用文档](docs/api.md)：查询参数、curl 示例、响应与错误说明
- [公开 API 防护](docs/operations/public-api.md)：跨域配置、请求限流与资源边界
- [API 与 Composer 契约](docs/contracts/v1.md)：输入、响应和兼容性
- [每日同步](docs/operations/daily-sync.md)：运行与故障排查
- [部署手册](docs/operations/deployment.md)：容器、迁移、备份恢复和回滚
- [发布清单](docs/release.md) 与 [变更记录](CHANGELOG.md)：发布检查与改动说明
- [安全政策](SECURITY.md)：漏洞的私下报告方式
- [代码风格](docs/code-style.md)：贡献约定
- [Issues](https://github.com/haoyuqi/wallpaper-api/issues)：缺陷报告、问题和建议
- [贡献指南](CONTRIBUTING.md)：开发设置和 Pull Request

## 许可证与历史图片

项目代码采用 [MIT 许可证](LICENSE)。第三方图片和来源内容保留各自的权利，
代码许可证不授予这些内容的使用权。

`storage/bing-wallpaper/` 中的历史壁纸作为归档保留。API 运行时不会读取或修改该归档，且 `.gitignore` 已忽略 `storage/bing-wallpaper/` 下的新文件，防止本地运行或 CI 误提交新的壁纸图片。
