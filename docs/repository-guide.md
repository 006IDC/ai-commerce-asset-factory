# AI 商品素材工厂 · 使用说明

面向电商商家的 AI 商品素材工作台，覆盖商品资料、产品报告、图片、详情页、视频、素材管理与点数结算。

[产品首页](../README.md) · [部署说明](DEPLOYMENT.md) · [版本下载](https://github.com/006IDC/ai-commerce-asset-factory/releases) · [更新记录](../CHANGELOG.md)

## 使用前确认

- 当前公开发行：`v0.63.14-open-source.1`。部署时核对所选版本的文档；`main` 可能包含发行后的维护改动。
- 需要 PHP 8.2+、Composer 2，以及 PDO 和所选 SQLite/MySQL/PostgreSQL 驱动、cURL、Mbstring、OpenSSL、Fileinfo、GD。生产环境的完整要求见[部署说明](DEPLOYMENT.md)。
- Sub2API 必须独立部署，本仓库不包含其服务端；未配置时可以浏览和配置系统，但 AI 任务不可用。
- ComfyUI 仅用于可选的画布 AI 编辑。商品上新、套图、详情页、自由创作和视频不经过 ComfyUI。
- 真实模型调用可能产生费用，涉及真实模型的测试需部署者显式确认，不应由 CI 自动触发。

## 日常使用流程

1. **配置服务**：在管理后台或受保护的环境配置中设置 Sub2API、模型别名、存储及业务参数，详见[配置说明](CONFIGURATION.md)。
2. **整理商品**：建立商品资料，检查产品报告与识别结果，确认商品信息和创作要求。
3. **创建内容**：选择商品上新套图、详情页、自由创作或视频任务；生图结合后台可编辑的完整提示词模板与用户确认的商品资料。
4. **查看任务与素材**：在任务中心查看执行结果，再到素材管理使用产物。图片与视频任务依赖对应 Worker 持续运行。
5. **按需启用画布**：仅在需要画布 AI 编辑时配置 ComfyUI；它不是第二套业务后台。

ThinkPHP 负责用户、商品、提示词、任务、计费、素材和后台；Sub2API 负责模型接入与请求调度。详细调用关系见[架构说明](ARCHITECTURE.md)。

## 本地启动

以下从仓库根目录开始，仅用于本地评估；生产环境请使用[部署说明](DEPLOYMENT.md)。

```bash
cp .env.example apps/server/.env
cd apps/server
composer install
```

按[配置说明](CONFIGURATION.md)生成 `APP_KEY`，设置管理员信息和至少 12 位、同时包含字母与数字的初始密码，再在 `apps/server/` 执行：

```bash
php think app:install
php think app:upgrade
php -S 127.0.0.1:8080 -t public public/router.php
```

另开两个终端，分别从仓库根目录进入 `apps/server/` 后执行：

```bash
# 终端一：图片等任务
php think jobs:work
```

```bash
# 终端二：视频任务
php think jobs:work-video
```

访问 `http://127.0.0.1:8080`。仅启动 Web 服务不会启动异步任务处理。

## 开发与验证

仅在独立测试环境运行；不要对生产数据库执行测试或创建测试夹具。

```bash
# 从仓库根目录进入主应用
cd apps/server
composer validate --strict
ASSET_FACTORY_ALLOW_TEST_FIXTURE=1 php tests/create_public_test_fixture.php
php tests/run-contracts.php
(cd ../../services/ark-bridge && python -m unittest test_app.py)
(cd ../../services/comfyui-asset-factory-nodes && python -m unittest test_asset_factory_nodes.py)
```

`create_public_test_fixture.php` 只允许写入项目目录内的 SQLite 测试库；测试通过不替代目标环境部署验证。

<details>
<summary>源码目录</summary>

- `apps/server/`：ThinkPHP 主应用、后台、页面、数据库结构与测试。
- `services/ark-bridge/`：可选的回环协议适配服务。
- `services/comfyui-asset-factory-nodes/`：ComfyUI 受控节点。
- `services/sub2api/`：集成边界说明，不包含 Sub2API 本体。
- `infra/`：Nginx、PHP、systemd 与 ComfyUI 示例。
- `docs/`：架构、配置与部署文档。

</details>

## 安全与许可

真实 `.env` 和密钥不进入 Git。安全问题见[安全政策](../SECURITY.md)。项目自有源码采用 [Apache License 2.0](../LICENSE)，第三方版权与许可见 [NOTICE](../NOTICE) 和[第三方声明](../THIRD_PARTY_NOTICES.md)。由[零零陆云计算（006IDC）](https://github.com/006IDC)独立维护。
