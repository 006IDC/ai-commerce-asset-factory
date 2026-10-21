# AI 商品素材工厂

## 产品介绍

面向电商商家的开源 AI 商品素材工作台，覆盖商品建档、图片、详情页、视频与素材管理。

## 版本概览

<table>
<thead><tr><th width="90">项目</th><th>公开说明</th></tr></thead>
<tbody>
  <tr><td>项目</td><td>AI 商品素材工厂（开源项目）</td></tr>
  <tr><td>公开发行版本</td><td><a href="https://github.com/006IDC/ai-commerce-asset-factory/releases/tag/v0.63.14-open-source.1">v0.63.14-open-source.1</a></td></tr>
  <tr><td>适用环境</td><td>PHP 8.2+ / Composer 2 / ThinkPHP 8；PDO SQLite、MySQL 或 PostgreSQL</td></tr>
  <tr><td>使用边界</td><td>AI 任务依赖单独部署和配置的 Sub2API；ComfyUI 仅用于可选画布编辑。真实模型调用可能产生费用。</td></tr>

</tbody>
</table>

版本以本仓公开 Release 为准；完整技术说明见[运行与维护说明](docs/repository-guide.md)。

## 主要功能

- 商品资料与产品报告、商品上新套图、详情页和自由创作。
- 视频任务、素材管理、点数结算与运营后台。
- 可选 ComfyUI 画布编辑及独立 Worker 任务处理。

## 使用与升级

按[运行与维护说明](docs/repository-guide.md)准备 PHP 扩展与环境变量，执行 Composer 安装、数据库初始化和升级，再启动 Web 与 Worker。未配置 Sub2API 时 AI 任务不可用。生产部署见[部署说明](docs/DEPLOYMENT.md)。

## 版本演进

版本变化见[变更记录](CHANGELOG.md)，源码与既有发行入口见[历史范围与缺口](docs/history-overview.md)。适用环境和部署检查以对应版本文档为准。

2026-10-22 依赖维护：PHPMailer 锁定为 7.1.1，Ark Bridge 使用 volcengine-python-sdk 5.0.53 与 cryptography 50.0.2；Flysystem 保持已修复路径校验漏洞的 3.35.3。本次不新增发行版本，兼容性回归随 Server CI 执行。

## 许可证和维护

由[零零陆云计算（006IDC）](https://github.com/006IDC)独立维护。自有源码按 [Apache License 2.0](LICENSE) 发布。第三方来源、版权和许可见 [NOTICE](NOTICE) 与[第三方声明](THIRD_PARTY_NOTICES.md)。
