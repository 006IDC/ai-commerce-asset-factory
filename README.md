# AI 商品素材工厂

面向电商商家的开源 AI 商品素材工作台，覆盖商品建档、图片创作、详情页、视频与素材管理。

[使用文档](docs/repository-guide.md) · [部署说明](docs/DEPLOYMENT.md) · [版本下载](https://github.com/006IDC/ai-commerce-asset-factory/releases) · [更新记录](CHANGELOG.md)

**当前发行：** [v0.63.14-open-source.1](https://github.com/006IDC/ai-commerce-asset-factory/releases/tag/v0.63.14-open-source.1)

## 主要功能

- 商品资料与产品报告、商品上新套图、详情页和自由创作。
- 视频任务、素材管理、点数结算与运营后台。
- 独立 Worker 任务处理，以及可选的 ComfyUI 画布编辑。

## 安装与升级

运行环境：PHP 8.2+、Composer 2、ThinkPHP 8；数据库使用 PDO SQLite、MySQL 或 PostgreSQL。

1. 按[使用文档](docs/repository-guide.md)准备 PHP 扩展与环境变量，安装 Composer 依赖并初始化或升级数据库。
2. 启动 Web 与 Worker；AI 任务需单独部署和配置 Sub2API。
3. 生产环境按[部署说明](docs/DEPLOYMENT.md)配置和检查。

> 未配置 Sub2API 时 AI 任务不可用，真实模型调用可能产生费用。ComfyUI 仅用于可选画布编辑。

## 版本与兼容性

发行版本以 [Releases](https://github.com/006IDC/ai-commerce-asset-factory/releases) 为准，部署和升级要求以对应版本文档为准。源码与既有发行入口见[历史范围与缺口](docs/history-overview.md)。

<details>
<summary>2026-10-22 维护说明</summary>

- 依赖维护：PHPMailer 锁定为 7.1.1，Ark Bridge 使用 volcengine-python-sdk 5.0.53 与 cryptography 50.0.2；Flysystem 保持已修复路径校验漏洞的 3.35.3。兼容性回归随 Server CI 执行。
- 代码扫描维护：收紧素材与工作区地址的可用协议和范围，保留图片预览；Ark Bridge 提供 systemd 250+ 原生加密凭据安装及读取方式。旧部署迁移要求见 [Bridge 说明](services/ark-bridge/README.md)。
- 上述维护不新增发行版本，不改动运行中的服务；完整变化见[更新记录](CHANGELOG.md)。

</details>

## 许可证与维护

由[零零陆云计算（006IDC）](https://github.com/006IDC)独立维护。自有源码按 [Apache License 2.0](LICENSE) 发布；第三方来源、版权与许可见 [NOTICE](NOTICE) 和[第三方声明](THIRD_PARTY_NOTICES.md)。
