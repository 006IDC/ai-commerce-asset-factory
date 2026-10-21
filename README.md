# AI 商品素材工厂

## 产品介绍

面向电商商家的开源 AI 商品素材工作台，覆盖商品建档、图片、详情页、视频与素材管理。

## 版本概览

| 项目 | 公开说明 |
| --- | --- |
| 公开发行版本 | [v0.63.14-open-source.1](https://github.com/006IDC/ai-commerce-asset-factory/releases/tag/v0.63.14-open-source.1) |
| 适用环境 | PHP 8.2+ / Composer 2 / ThinkPHP 8；PDO SQLite、MySQL 或 PostgreSQL |
| 使用边界 | AI 任务依赖单独部署和配置的 Sub2API；ComfyUI 仅用于可选画布编辑。真实模型调用可能产生费用。 |

版本以本仓公开 Release 为准；完整技术说明见[运行与维护说明](docs/repository-guide.md)。

## 主要功能

- 商品资料与产品报告、商品上新套图、详情页和自由创作。
- 视频任务、素材管理、点数结算与运营后台。
- 可选 ComfyUI 画布编辑及独立 Worker 任务处理。

## 使用与升级

按[运行与维护说明](docs/repository-guide.md)准备 PHP 扩展与环境变量，执行 Composer 安装、数据库初始化和升级，再启动 Web 与 Worker。未配置 Sub2API 时 AI 任务不可用。生产部署见[部署说明](docs/DEPLOYMENT.md)。

## 版本演进

版本变化见[变更记录](CHANGELOG.md)。适用环境和部署检查以对应版本文档为准。

## 许可证与维护

由[零零陆云计算（006IDC）](https://github.com/006IDC)独立维护。自有源码按 [Apache License 2.0](LICENSE) 发布。第三方来源、版权和许可见 [NOTICE](NOTICE) 与[第三方声明](THIRD_PARTY_NOTICES.md)。
