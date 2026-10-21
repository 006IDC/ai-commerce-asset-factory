# AI 商品素材工厂 · 变更记录

## 2026-10-22 · 依赖兼容与安全回归维护

- 在当前公开主线上整合既有依赖 PR #1、#16、#17、#18、#19；setup-php 更新至 2.40.0、SBOM Action 更新至 0.24.3，继续固定官方完整提交号。旧 cryptography 50.0.0 提案 #10 由 50.0.2 替代。

- PHPMailer 从 6.12.0 升级并锁定为 7.1.1，保留现有 SMTP 发送接口；Ark Bridge 将 volcengine-python-sdk 从 5.0.42 升级为 5.0.53、cryptography 从 46.0.7 升级为 50.0.2。
- 核对 [Flysystem 官方公告](https://github.com/thephpleague/flysystem/security/advisories/GHSA-cxf4-7mrp-vvpr)：GHSA-cxf4-7mrp-vvpr / CVE-2026-102601 影响不高于 3.35.2；当前锁文件已为修复版 3.35.3，保持该最小版本并增加畸形 UTF-8 与控制字符拒绝回归，不绕过 Composer 安全审计。
- 增加邮件内容构造、真实 Ark SDK 请求序列化与 STS 缓存、合成证书加密往返回归，使用本地模拟响应，不调用付费模型。
- cryptography 50.0.2 要求 Python 3.9+；49.0 起不再提供 Intel macOS 与 32 位 Windows 轮子，部署前需核对平台。项目 CI 继续使用 Linux / Python 3.12。
- 本次维护不新增软件版本、源码标签或发行包发布；真实服务商、TLS 邮件投递及生产部署不在本次合成回归范围。

## 2026-10-21 · 文档与版本导航维护

- 统一项目首页、仓库简介和版本导航；保留原有开源版本、许可及发行记录。
- [历史范围与缺口](docs/history-overview.md)链接本仓实际提交、已有源码标签与正式发行，不新增软件版本。
- 本次只维护公开文档，不改变运行代码或重新发行。

## 0.63.14-open-source.1 - 2026-08-13

- 统一公开环境配置模板，并让本地安装、CI 与发行包使用同一来源。
- 补齐 GitHub Actions 最小权限、完整 SHA 锁定、Dependabot 与发布来源证明。
- 修正公开部署文档中的仓库地址和 Composer 项目标识。

## 0.63.13-open-source.1 - 2026-08-12

- 提供商品建档、产品报告、商品上新、详情页、自由创作、视频任务和素材管理功能。
- 商品上新、详情页、自由创作和视频通过 Sub2API 调用模型。
- ComfyUI 用于画布 AI 编辑。
- 支持后台配置模型、提示词模板、套餐、点数、存储和邮件服务。
- 提供 SQLite、MySQL 和 PostgreSQL 数据库支持及通用部署示例。
