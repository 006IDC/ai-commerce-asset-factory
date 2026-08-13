# Deployment

## 1. 准备环境

- Linux 服务器，PHP 8.2+（推荐 8.3）和 Composer 2。
- PHP 扩展：PDO 及所选数据库驱动、cURL、Mbstring、OpenSSL、Fileinfo、GD、XML、Zip。
- SQLite、MySQL 5.7+/8.0+ 或 PostgreSQL 13+。
- Nginx 或 Apache。Nginx 必须把站点根目录指向 `apps/server/public/`。
- 独立部署的 Sub2API；如需画布 AI 编辑，再部署 ComfyUI。

## 2. 安装源码

```bash
git clone https://github.com/006IDC/ai-commerce-asset-factory.git
cd ai-commerce-asset-factory/apps/server
composer install --no-dev --optimize-autoloader
cp ../../.env.example .env
```

编辑 `.env`，至少设置 `APP_KEY`、`APP_PUBLIC_URL`、数据库和管理员信息。开发示例域名为 `studio.example.com`，正式部署必须替换为自己的 HTTPS 域名。

运行用户需要以下目录的写权限：

```bash
mkdir -p runtime public/storage database
sudo chown -R www-data:www-data runtime public/storage database
```

如果使用 MySQL 或 PostgreSQL，数据库数据目录不在项目内，可不向 `database/` 授予生产写权限。

## 3. 初始化数据库

CLI 安装：

```bash
php think app:install
php think app:upgrade
php think app:upgrade
```

第二次升级用于确认迁移幂等。也可先配置 Web 服务后访问 `/install` 使用安装向导，两种方式只选一种。完成后会生成 `runtime/install.lock`。

## 4. Nginx 与 PHP-FPM

复制 `infra/nginx/asset-factory.example.conf` 并修改：

- `server_name`、TLS 证书路径和项目绝对路径。
- PHP-FPM socket，例如 `/run/php/php8.3-fpm.sock`。
- 保留 `client_max_body_size 160m` 和 `fastcgi_read_timeout 240s`。
- 仅允许 `index.php` 作为 PHP 入口，拒绝隐藏文件和其他 PHP 路径。

安装 `infra/php/99-asset-factory-upload.ini` 到 PHP-FPM 附加配置目录，然后校验并重载：

```bash
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl restart php8.3-fpm
```

## 5. 连接 Sub2API

按 Sub2API 上游文档独立部署，建议监听 `127.0.0.1:3002`。管理端如需暴露公网，可参考 `infra/nginx/sub2api.example.conf`，使用 `ai-console.example.com` 作为待替换域名。

在 ThinkPHP 管理后台或受保护的 `.env` 中配置网关地址、专用服务 Key 和模型别名。上游账号与渠道密钥只应存在 Sub2API。

## 6. 启动 Worker

生图、商品识别和视频是异步任务。不要只部署 PHP-FPM 而遗漏 Worker。

```bash
cd apps/server
sudo bash scripts/install-worker-service.sh
```

如果不使用该脚本，可以 `infra/systemd/asset-factory-worker.service` 和 `asset-factory-video-worker.service` 为起点，必须根据环境修改用户、路径、数据库服务名和 PHP 路径。

## 7. 可选 ComfyUI 画布编辑

不需要画布 AI 编辑可跳过本节。ComfyUI 不是第二套业务后台，不参与商品上新、详情页、自由创作或视频主链路。

```bash
cd apps/server
sudo scripts/manage-comfyui-runtime.sh install
scripts/manage-comfyui-runtime.sh status
```

运行时必须只监听 `127.0.0.1:8188`。管理后台只读检查状态，不应向 PHP-FPM 授予 `sudo` 或 `systemctl` 权限。

## 8. 部署验证

```bash
cd apps/server
composer validate --strict
composer audit --locked --no-dev
find app config route tests view scripts -type f -name '*.php' -print0 | xargs -0 -n1 php -l
ASSET_FACTORY_ALLOW_TEST_FIXTURE=1 php tests/create_public_test_fixture.php
php tests/run-contracts.php
php think security:test
```

`create_public_test_fixture.php` 只允许写入项目目录内的 SQLite 测试库。不要在生产数据库上运行测试；正式环境测试应使用独立预发环境。

启动服务后检查 `/api/health`、管理员登录、商家注册/登录、商品报告、商品上新、自由创作、任务中心、失败退点和管理后台配置。真实模型冒烟会产生费用，需要显式确认。

## 9. 备份与升级

升级前停止 Worker，备份数据库、`.env`、`public/storage/` 和对象存储配置。然后执行：

```bash
composer install --no-dev --optimize-autoloader
php think app:upgrade
php think security:test
```

备份必须存放在仓库之外，并定期做恢复演练。
