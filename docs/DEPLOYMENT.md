# AI 商品素材工厂 · 部署说明

按环境准备、安装、服务配置、验证、升级与回退的顺序操作。当前公开发行是 `v0.63.14-open-source.1`；`main` 可能包含发行后的维护改动，部署前先选定版本或源码提交并保留标识。

[产品首页](../README.md) · [使用说明](repository-guide.md) · [配置说明](CONFIGURATION.md) · [版本下载](https://github.com/006IDC/ai-commerce-asset-factory/releases)

## 1. 部署前检查

- Linux、PHP 8.2+（推荐 8.3）、Composer 2。
- PHP 扩展：PDO 及所选数据库驱动、cURL、Mbstring、OpenSSL、Fileinfo、GD、XML、Zip。
- 数据库：SQLite、MySQL 5.7+/8.0+ 或 PostgreSQL 13+。
- Nginx 或 Apache，站点根目录指向 `apps/server/public/`，不能暴露仓库根目录。
- 独立部署 Sub2API；需要画布 AI 编辑时再部署 ComfyUI。
- 生产域名与 TLS 证书已准备。文中的 `studio.example.com`、`ai-console.example.com` 及系统路径均为示例，部署时替换。

首次部署与升级都先确认备份及恢复方式。真实模型调用可能收费，模型冒烟需要部署者显式确认。

## 2. 获取源码与配置

以下示例获取 `main` 源码；若使用发行版本，请在[版本下载](https://github.com/006IDC/ai-commerce-asset-factory/releases)核对对应源码和文档。不要把 `main` 等同于已发布安装包。

```bash
git clone https://github.com/006IDC/ai-commerce-asset-factory.git
cd ai-commerce-asset-factory
cp .env.example apps/server/.env
cd apps/server
composer install --no-dev --optimize-autoloader
```

**后续命令默认在 `apps/server/` 执行。** 新终端需先进入同一部署的该目录。

按[配置说明](CONFIGURATION.md)填写 `.env`：

1. 首次部署生成独立 `APP_KEY`，不要复用示例；已有系统升级保留原密钥。
2. 设置真实 HTTPS `APP_PUBLIC_URL`、数据库连接和管理员信息，生产关闭 `APP_DEBUG`。
3. `ADMIN_INITIAL_PASSWORD` 至少 12 位且含字母与数字；安装后从配置中移除初始密码。真实 `.env` 和密钥不进入 Git。

首次生成 `APP_KEY` 的命令如下，将结果填入受保护的配置，不发送到公开日志或问题反馈中：

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
```

创建运行目录；`www-data` 是示例运行用户，先按实际环境替换：

```bash
mkdir -p runtime public/storage database
sudo chown -R www-data:www-data runtime public/storage
```

使用 SQLite 时还需让运行用户可写 `database/`。MySQL/PostgreSQL 数据目录不在项目内时，不需授予该目录生产写权限。

## 3. 初始化数据库

CLI 与 Web 安装向导选择一种，不重复执行首装。

**CLI 安装**：

```bash
php think app:install
php think app:upgrade
```

**Web 安装**：先配置下一节的 Web 服务，再访问 `/install` 完成向导。安装完成应生成 `runtime/install.lock`。

在独立预发环境可再次执行 `php think app:upgrade` 验证迁移幂等；已有系统按升级流程处理，不重新执行首装。

## 4. 配置 Web 与 PHP-FPM

使用仓库根目录的 [Nginx 示例](../infra/nginx/asset-factory.example.conf)，核对：

- `server_name`、TLS 证书、部署绝对路径及 `apps/server/public/` 站点根目录。
- 实际 PHP-FPM socket，例如 `/run/php/php8.3-fpm.sock`。
- 保留 `client_max_body_size 160m`、`fastcgi_read_timeout 240s`。
- 只允许 `index.php` 作为 PHP 入口，拒绝隐藏文件及其他 PHP 路径。

将 [PHP 上传配置](../infra/php/99-asset-factory-upload.ini)安装到当前 PHP-FPM 的附加配置目录。下例按 PHP 8.3 服务命名，其他版本替换为实际服务：

```bash
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl restart php8.3-fpm
```

## 5. 连接 Sub2API

按 Sub2API 上游文档独立部署，建议监听 `127.0.0.1:3002`。管理端确需公网访问时，可参考 [Sub2API Nginx 示例](../infra/nginx/sub2api.example.conf)，替换示例域名并配置 HTTPS。

在业务后台或受保护的 `.env` 中配置网关地址、专用服务 Key 和模型别名。模型别名须与 Sub2API 暴露的模型 ID 一致；上游账号与渠道密钥仅保存在 Sub2API。未配置时 AI 任务不可用。

## 6. 启动 Worker

图片、商品识别与视频依赖异步任务处理，不能只启动 PHP-FPM。

执行前核对实际运行用户和 PHP CLI 路径。安装脚本默认使用 `www-data` 与可发现的 PHP，可通过 `WORKER_USER`、`WORKER_PHP` 指定；脚本会安装并启用 10 个图片 Worker 槽位和 1 个视频 Worker。

```bash
sudo bash scripts/install-worker-service.sh
```

需要自行管理服务时，参考仓库根目录 [图片 Worker 示例](../infra/systemd/asset-factory-worker.service)和[视频 Worker 示例](../infra/systemd/asset-factory-video-worker.service)，按实际环境修改用户、路径、数据库服务名与 PHP 路径。

## 7. 可选：ComfyUI 画布编辑

不需要画布 AI 编辑时跳过。ComfyUI 不参与商品上新、详情页、自由创作或视频主链路。

```bash
sudo scripts/manage-comfyui-runtime.sh install
scripts/manage-comfyui-runtime.sh status
```

仅监听 `127.0.0.1:8188`，不直接向商家浏览器暴露 API。管理后台只读检查状态，不向 PHP-FPM 授予 `sudo` 或 `systemctl` 权限。

## 8. 验证部署

### 隔离环境检查

仅在独立预发/测试环境运行以下命令，不对生产数据库创建夹具或运行测试：

```bash
composer validate --strict
composer audit --locked --no-dev
find app config route tests view scripts -type f -name '*.php' -print0 | xargs -0 -n1 php -l
ASSET_FACTORY_ALLOW_TEST_FIXTURE=1 php tests/create_public_test_fixture.php
php tests/run-contracts.php
php think security:test
```

夹具脚本只允许写入项目目录内的 SQLite 测试库。代码与夹具检查不能替代实际目标环境验证。

### 服务与业务检查

1. 检查 `/api/health`、管理员登录和商家注册/登录。
2. 确认图片与视频 Worker 运行，任务不会只停留在队列。
3. 经显式确认费用后，验证商品报告、商品上新、自由创作、任务中心及失败退点。
4. 核对后台配置和素材访问；需要画布编辑时，再单独检查 ComfyUI 链路。

## 9. 升级与回退

### 正常升级

1. 安排维护窗口，停止当前部署的全部图片和视频 Worker，记录实际服务名。
2. 备份数据库、现有源码版本、`.env`、`public/storage/` 及对象存储配置；备份保存在仓库外并定期演练恢复。
3. 按所选版本说明更新源码，保留真实配置、存储文件与原 `APP_KEY`，在 `apps/server/` 执行：

```bash
composer install --no-dev --optimize-autoloader
php think app:upgrade
```

4. 先在预发验证 `php think security:test` 及上述主流程；目标环境完成升级后，按原服务管理方式启动 Worker，重新检查健康状态和任务消费。

### 异常回退

先停止新增任务并保留错误与迁移记录。文件更新失败时恢复完整的已备份源码版本和对应依赖；涉及数据库迁移时，核对迁移及期间新增业务，再按已验证的备份恢复方案处理。仅恢复文件不能撤回数据库变更，也不要重生成 `APP_KEY` 代替恢复配置。

## 10. 常见问题

| 现象 | 先检查 |
| --- | --- |
| Web 可访问但 AI 不执行 | Sub2API 配置与模型别名、图片/视频 Worker 是否运行 |
| 上传失败或请求超时 | Nginx 请求体上限、PHP 上传配置、FPM 与网关超时配置 |
| 只有画布编辑异常 | 可选 ComfyUI 是否启用、是否只监听回环地址，先核对其状态 |
| 安装或升级失败 | PHP 扩展、数据库连接、目录权限和对应版本迁移记录，不直接重做首装 |

安全问题见[安全政策](../SECURITY.md)，配置细节见[配置说明](CONFIGURATION.md)。
