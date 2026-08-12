<?php

declare(strict_types=1);

namespace app\controller;

use app\service\NewApiClient;
use app\service\WorkerMonitor;
use think\Response;

final class Api
{
    public function health(NewApiClient $newApi, WorkerMonitor $workerMonitor): Response
    {
        $configuration = $newApi->configuration();
        $worker = $workerMonitor->status();
        return Response::create([
            'status' => 'ok',
            'service' => 'asset-factory-thinkphp',
            'newApiConfigured' => $configuration['configured'],
            'workerStatus' => $worker['health'],
            'workerHealthy' => in_array($worker['health'], ['online', 'recent'], true),
        ], 'json');
    }
}
