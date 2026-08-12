# Configuration

## 配置来源

复制根目录 `.env.example` 为 `apps/server/.env`。部分 AI、存储、SMTP、安全和运营设置也可在管理后台中保存。真实 `.env` 与密钥文件不得进入 Git。

## 必填项

| 配置 | 用途 | 生产要求 |
| --- | --- | --- |
| `APP_KEY` | 应用加密根密钥 | 每套部署独立生成，不得复用示例 |
| `APP_PUBLIC_URL` | 对外绝对地址 | 例如 `https://studio.example.com` |
| `DB_*` | 数据库连接 | 使用最小权限账户和独立密码 |
| `ADMIN_EMAIL` | 首个管理员 | 替换 `admin@example.com` |
| `ADMIN_INITIAL_PASSWORD` | CLI 首次安装密码 | 至少 12 位，同时包含字母和数字；安装后从文件移除 |

`APP_KEY` 生成示例：

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
```

## 数据库

支持 SQLite、MySQL 和 PostgreSQL。SQLite 适合本地评估；生产建议使用 PostgreSQL 或 MySQL。

```dotenv
DB_DRIVER = pgsql
DB_HOST = 127.0.0.1
DB_PORT = 5432
DB_NAME = asset_factory
DB_USER = asset_factory
DB_PASS = replace-with-a-random-database-password
DB_CHARSET = utf8mb4
```

## Sub2API

```dotenv
SUB2API_ENABLED = 1
SUB2API_BASE_URL = http://127.0.0.1:3002
SUB2API_SERVICE_KEY = replace-with-a-random-service-key
SUB2API_TIMEOUT_SECONDS = 450
```

`DEFAULT_IMAGE_MODEL` 和 `DEFAULT_VISION_MODEL` 是业务别名，必须与 Sub2API 对外暴露的模型 ID 一致。不要把上游账号或渠道密钥复制到 ThinkPHP。

## ComfyUI

```dotenv
COMFYUI_ENABLED = 0
COMFYUI_BASE_URL = http://127.0.0.1:8188
COMFYUI_REQUEST_TIMEOUT_SECONDS = 30
COMFYUI_WORKFLOW_TIMEOUT_SECONDS = 600
```

不使用画布 AI 编辑时保持关闭。开启后仍必须只监听回环地址，不得把 ComfyUI API 直接暴露给商家浏览器。

## 内容安全

示例配置默认不发送外部审核请求，以避免未配置网关时产生费用。生产环境应配置实际可用的安全模型，并根据业务风险选择 `CONTENT_SAFETY_FAIL_CLOSED=1`。

## 邮件、验证码与存储

SMTP、极验和对象存储值应在管理后台或受保护的环境配置中填写。对象存储公开域名可使用 `https://assets.example.com` 作为文档模板，不要照抄示例值到生产。
