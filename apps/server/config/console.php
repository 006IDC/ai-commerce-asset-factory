<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'app:install' => \app\command\Install::class,
        'app:upgrade' => \app\command\Upgrade::class,
        'jobs:work'   => \app\command\WorkGenerationJobs::class,
        'jobs:work-video' => \app\command\WorkVideoJobs::class,
        'security:test' => \app\command\TestContentSafety::class,
    ],
];
