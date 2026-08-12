# AI Commerce Asset Factory

面向电商商家的 AI 商品素材工作台，覆盖商品建档、产品报告、商品上新套图、详情页、自由创作、视频任务、素材管理、点数结算和运营后台。

## 系统组成

```text
浏览器
  → ThinkPHP 8 业务控制面
      ├─ 商品上新 / 详情页 / 自由创作 / 视频 → Sub2API
      ├─ 商品识别与内容安全 → Sub2API
      └─ 画布 AI 编辑 → 可选 ComfyUI → Sub2API
```

- ThinkPHP 应用负责用户、商品资料、提示词模板、任务、计费、素材和后台管理。
- Sub2API 独立部署，负责模型接入和请求调度；本仓库不包含 Sub2API 服务端。
- ComfyUI 仅用于画布编辑。商品上新、套图、详情页、自由创作和视频不经过 ComfyUI。
- 生图使用后台可编辑的完整提示词模板，并结合用户确认的商品资料和创作要求生成内容。

## 目录

- `apps/server/`：ThinkPHP 主应用、后台、页面、数据库结构和测试。
- `services/ark-bridge/`：可选的回环协议适配服务。
- `services/comfyui-asset-factory-nodes/`：ComfyUI 受控节点源码。
- `services/sub2api/`：Sub2API 集成边界说明，不包含 Sub2API 本体。
- `infra/`：Nginx、PHP、systemd 和 ComfyUI 的通用示例。
- `docs/`：架构、配置和部署说明。

## 本地启动

需要 PHP 8.2+、Composer 2、PDO SQLite/MySQL/PostgreSQL、cURL、Mbstring、OpenSSL、Fileinfo 和 GD。

```bash
cp .env.example apps/server/.env
cd apps/server
composer install
```

编辑 `.env`，生成 `APP_KEY`，设置至少 12 位的强管理员密码，然后：

```bash
php think app:install
php think app:upgrade
php -S 127.0.0.1:8080 -t public public/router.php
```

另开终端启动 Worker：

```bash
cd apps/server
php think jobs:work
php think jobs:work-video
```

浏览器打开 `http://127.0.0.1:8080`。未配置 Sub2API 时可浏览和配置系统，AI 任务不会可用。生产部署见 [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)。

## 测试

```bash
cd apps/server
composer validate --strict
ASSET_FACTORY_ALLOW_TEST_FIXTURE=1 php tests/create_public_test_fixture.php
php tests/run-contracts.php
(cd ../../services/ark-bridge && python -m unittest test_app.py)
(cd ../../services/comfyui-asset-factory-nodes && python -m unittest test_asset_factory_nodes.py)
```

涉及真实模型的测试可能产生费用，必须由部署者显式确认，不应在 CI 中自动触发。

## 文档与许可证

- [架构](docs/ARCHITECTURE.md)
- [配置](docs/CONFIGURATION.md)
- [部署](docs/DEPLOYMENT.md)
- [安全政策](SECURITY.md)

项目自有源码按 Apache License 2.0 发布。第三方组件保持各自许可证和版权，详见 [NOTICE](NOTICE) 和 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。
