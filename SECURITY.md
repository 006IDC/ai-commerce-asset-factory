# Security policy

## 报告漏洞

请使用仓库 **Security → Advisories → Report a vulnerability** 的私密入口报告安全问题。不要在公开 Issue 中提交漏洞利用、用户数据、服务器地址或任何凭据。

## 密钥规则

- 仓库只允许示例配置，真实凭据必须位于仓库外并限制读权限。
- 管理员密码、Sub2API Key、对象存储密钥、SMTP 密码、验证码私钥和云厂商凭据不得进入前端资源、任务快照或日志。
- 如果凭据被提交到 Git，应立即轮换凭据并从提交历史中彻底移除。

## 部署安全

- `APP_DEBUG=false`、`COOKIE_SECURE=true`，使用随机 `APP_KEY`、强管理员密码和独立数据库账户。
- Nginx 只把 `apps/server/public/` 暴露为站点根目录，不允许执行任意 PHP 文件。
- Sub2API、ComfyUI、Ark Bridge 和不对外服务的管理接口只监听回环地址或受控私网。
- Worker 使用非 root 用户，仅允许写入 `runtime/` 和 `public/storage/`。
- 对外下载必须使用 HTTPS，并保持 DNS/私网校验、响应大小限制和内容类型验证。

## 依赖审计

```bash
cd apps/server
composer validate --strict
composer audit --locked --no-dev
```

依赖升级必须保留 `composer.lock` 并完成全量契约和安装冒烟测试。
