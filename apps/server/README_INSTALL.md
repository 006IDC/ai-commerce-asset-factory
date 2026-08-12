# AI 电商素材工厂安装说明

## 环境要求

- PHP 8.2 或更高版本，推荐 PHP 8.3
- PDO、cURL、Mbstring、OpenSSL、Fileinfo
- 三选一：MySQL 5.7+/8.0+、PostgreSQL 13+、SQLite
- Apache 需要启用 `mod_rewrite`

## Apache / 宝塔 Apache

1. 将发行包解压到站点根目录。
2. 确保 PHP 运行用户可写 `.env`、`runtime`、`public/storage` 和 SQLite 使用的 `database` 目录。
3. 访问域名，系统会自动进入 `/install` 安装向导。
4. 填写数据库和管理员信息，完成安装。

## Nginx / 宝塔 Nginx

Nginx 不读取 `.htaccess`，因此必须把站点运行目录指向解压目录下的 `public` 子目录。可参考发行包中的 `nginx-site.example.conf`。

手机拍摄的商品图通常较大，商品上新还支持一次上传最多 6 张。请确认 Nginx 站点配置包含 `client_max_body_size 160m;`，并将发行包中的 `php-upload.example.ini` 安装到 PHP-FPM 的附加配置目录。例如 PHP 8.3 可放到 `/etc/php/8.3/fpm/conf.d/99-asset-factory-upload.ini`。配置保持“单张 24MB、整次请求 160MB”，随后校验 Nginx 配置并重载 Nginx、重启 PHP-FPM。

商品识别和产品报告需要等待视觉模型返回，请同时保留示例配置中的 `fastcgi_read_timeout 240s;`，避免 Nginx 在业务请求超时之前提前断开页面连接。

## 安装后

- 管理后台：`/admin/login`
- 商家登录：`/login`
- 上游模型账号、渠道、密钥、额度、调度和用量统一在独立 Sub2API 控制台配置；ThinkPHP 不提供重复的账号、渠道或上游用量管理入口。
- ThinkPHP 只保留业务需要的模型 ID、用户选择快照、任务结算和调用审计。模型是否可调用及物理上游路由由 Sub2API 决定。
- 业务主提示词在“提示词”中维护；普通页面不展示 ComfyUI 节点、完整组件或工作流工程入口，底层版本只从具体任务诊断和受保护的技术诊断路径追溯。
- Tavily API Key、商户联网开关、搜索强度、缓存和联网分析价格在“联网搜索”中配置；密钥加密保存。
- 生图和商品识别售价在“计费设置”中配置，设置为 0 表示免费。
- 注册赠送积分、注册赠送套餐和商户工作台公告在“运营设置”中配置。
- 日/月无限量套餐在“套餐管理”中创建，商户使用账户积分购买。
- 极验 SenseBot 人机验证、内容审核模型、违禁词和违规处罚在“安全风控”中配置。
- SMTP 在“邮件服务”中配置。
- Worker 心跳、PID、最近任务和错误可在“任务 Worker”页面查看。
- 安装完成后会生成 `runtime/install.lock`，再次访问 `/install` 不会覆盖数据。

从旧网关版本升级时，先备份数据库并停止媒体 Worker，然后执行：

```bash
php think app:upgrade
php think security:test
```

升级命令会删除已停用的 ThinkPHP 上游账号池表，并将原有模型目录迁移为 Sub2API 网关标识。凭据需要单独配置，升级命令不会输出密钥。

## ComfyUI 托管运行时

ComfyUI 不是第二套业务后台，只用于画布 AI 编辑。商品主图、套图、详情页、自由创作和视频全部由 ThinkPHP 直接调用 Sub2API；ThinkPHP 继续负责权限、任务、点数、内容安全、完整提示词模板、素材和审计。ComfyUI 仅作为本机画布工作流执行器，默认只监听 `127.0.0.1:8188`。

发行包已经包含经过审核的 ComfyUI 版本、固定依赖、systemd 单元和电商受控节点。首次部署执行：

```bash
sudo scripts/manage-comfyui-runtime.sh install
```

常用运维命令：

```bash
scripts/manage-comfyui-runtime.sh status
sudo scripts/manage-comfyui-runtime.sh repair
sudo scripts/manage-comfyui-runtime.sh upgrade
```

- `status`：检查服务、API、队列和受控节点，不修改服务器。
- `repair`：重新同步受控节点和 systemd 定义，适合程序升级后的日常修复。
- `upgrade`：重建固定版本运行时，升级前拒绝打断活动队列，失败会恢复上一份运行时。
- `--force` 只用于已经确认可以中断队列的维护窗口。
- 模型文件、输入、输出和用户数据保存在 `/var/lib/asset-factory/comfyui`；程序运行时位于 `/opt/comfyui`。

安装后在管理后台“系统状态”查看 ComfyUI 可用性。ComfyUI 地址通过服务器环境配置管理；技术诊断只读检测版本、设备、队列、节点目录和受控电商节点。不要向 PHP-FPM 授予 `sudo` 或 `systemctl` 权限。

首次管理员默认直接进入 `/admin`。`admin_onboarding_progress` 和原向导服务继续保留；只有在服务器 `.env` 明确设置 `ADMIN_ONBOARDING_ENABLED=true` 时，登录和管理员中间件才恢复向导门禁。

## 异步任务 Worker

网页安装向导运行在 PHP-FPM 权限下，不负责写入系统服务或启动长期驻留进程。安装完成后请选择下面一种 Worker 运行方式，不要同时配置。

### 方式一：一条命令安装常驻服务（推荐）

在程序根目录执行：

```bash
sudo bash scripts/install-worker-service.sh
```

脚本会自动识别程序路径和 PHP CLI，创建 10 个 `asset-factory-worker@N.service` 槽位、立即启动并设置开机自启。默认单商户和单任务最多并发 3；是否提高并发应结合 Sub2API 的实际限流和任务容量评估。宝塔 PHP 不在系统 PATH 时可指定版本路径：

```bash
sudo WORKER_PHP=/www/server/php/83/bin/php WORKER_USER=www bash scripts/install-worker-service.sh
```

### 方式二：宝塔计划任务

无法使用 systemd 时，在宝塔“计划任务”中新建：

- 任务类型：Shell 脚本
- 执行周期：每 1 分钟
- 脚本内容：登录管理后台后，从“任务 Worker”页面复制“宝塔计划任务脚本”

该脚本使用 `flock` 防止上一个生图任务未完成时重复启动，并使用 `--drain` 一次处理完当时的排队任务后退出。不要给命令额外套 `timeout`，否则可能在图片生成过程中强制中断。

配置完成后回到管理后台“任务 Worker”页面，常驻服务应显示“运行正常”，宝塔方式应显示“最近执行正常”。
