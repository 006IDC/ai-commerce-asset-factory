# Sub2API 集成边界

Sub2API 是独立部署的模型网关，负责上游账号、渠道、物理模型、并发、限流和上游请求日志。ThinkPHP 应用负责商家、商品、提示词模板、任务、点数、素材和业务审计。两者不共用用户体系。

本仓库不包含 Sub2API 服务端、前端、数据库或任何上游密钥。请按上游项目文档独立安装，并优先只监听回环或受控私网地址。

示例：

```dotenv
SUB2API_ENABLED=1
SUB2API_BASE_URL=http://127.0.0.1:3002
SUB2API_SERVICE_KEY=replace-with-a-random-service-key
SUB2API_ADMIN_URL=https://ai-console.example.com
```

这些值必须写入部署环境或管理后台，不得提交到 Git。商品上新、套图、详情页、自由创作和视频都由 ThinkPHP Worker 直接调用 Sub2API；ComfyUI 仅用于画布编辑。

参考上游项目：<https://github.com/Wei-Shaw/sub2api>
