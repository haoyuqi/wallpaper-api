# Wallpaper API

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
查询只读取已存储的数据，不会请求 Bing。字段和示例见 [v1 契约](docs/contracts/v1.md)。

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

## 测试与代码风格

```bash
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpunit
```

默认测试使用隔离的内存 SQLite，此模式下会跳过 PostgreSQL 专用测试。
CI 在 PHP 8.4 上分别使用 SQLite 和 PostgreSQL 18 运行测试。
PostgreSQL 测试配置见 [贡献指南](CONTRIBUTING.md)，命名和可读性约定见
[代码风格](docs/code-style.md)。Pint 使用 Laravel 预设，不强制添加严格类型声明。

## 文档与支持

以下详细文档目前使用英文。

- [架构](docs/architecture.md)：职责、数据流和扩展边界
- [公开 API 防护](docs/operations/public-api.md)：跨域配置、请求限流与资源边界
- [API 与 Composer 契约](docs/contracts/v1.md)：输入、响应和兼容性
- [每日同步](docs/operations/daily-sync.md)：运行与故障排查
- [代码风格](docs/code-style.md)：贡献约定
- [Issues](https://github.com/haoyuqi/wallpaper-api/issues)：缺陷报告、问题和建议
- [贡献指南](CONTRIBUTING.md)：开发设置和 Pull Request

## 许可证与历史图片

项目代码采用 [MIT 许可证](LICENSE)。第三方图片和来源内容保留各自的权利，
代码许可证不授予这些内容的使用权。

`storage/bing-wallpaper/` 中的历史壁纸作为归档保留。API 运行时不会读取或修改该归档。
