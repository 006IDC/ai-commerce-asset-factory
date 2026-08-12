# ThinkPHP application

这里是 AI Commerce Asset Factory 的 ThinkPHP 8 业务主应用，包含管理后台、商家工作台、数据库结构、任务 Worker、页面资源和契约测试。

完整安装步骤请从仓库根目录的 [`docs/DEPLOYMENT.md`](../../docs/DEPLOYMENT.md) 开始。本地最小运行方式：

```bash
cp ../../.env.example .env
composer install
php think app:install
php -S 127.0.0.1:8080 -t public public/router.php
```

生图、视频和商品识别需要另行部署 Sub2API 并在管理后台配置。ComfyUI 仅用于可选的画布 AI 编辑，不参与商品上新、详情页、自由创作或视频主链路。
