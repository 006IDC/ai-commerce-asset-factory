<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AiCapability;
use app\service\AdminConfigurationAudit;
use app\service\AdminShellService;
use app\service\NewApiClient;
use app\service\GenerationItemQueue;
use app\service\VideoGenerationQueue;
use app\service\GenerationJobView;
use app\service\PlanService;
use app\service\SystemSettings;
use app\service\WorkerMonitor;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class Admin extends BaseController
{
    public function objectSearch(AdminShellService $shell): Response
    {
        $admin = $this->admin();
        $query = trim((string) $this->request->get('q', ''));
        $results = $shell->search($query);
        return $this->render('admin/object_search', compact('admin', 'query', 'results') + ['pageTitle' => '全局搜索']);
    }

    public function toggleFavorite(AdminShellService $shell): Response
    {
        $admin = $this->admin();
        $path = trim((string) $this->request->post('path', '/admin'));
        $title = trim((string) $this->request->post('title', '管理页面'));
        if (!str_starts_with($path, '/admin') || str_starts_with($path, '/admin/api/')) {
            return Web::redirect('/admin', '收藏地址无效。', 'error');
        }
        $favorite = $shell->toggleFavorite((int) $admin['id'], $path, $title);
        Audit::log('admin', (int) $admin['id'], $favorite ? 'admin_navigation.favorite' : 'admin_navigation.unfavorite', 'admin_page', null, $this->request->ip(), ['path' => $path]);
        return Web::redirect($path, $favorite ? '已加入收藏。' : '已取消收藏。');
    }

    public function configurationAudit(AdminConfigurationAudit $audit): Response
    {
        $admin = $this->admin();
        $rows = $audit->rows();
        $configurationSummary = $audit->summary($rows);
        return $this->render('admin/configuration_audit', compact('admin', 'rows', 'configurationSummary') + ['pageTitle' => '配置审计']);
    }

    public function dashboard(WorkerMonitor $workerMonitor): Response
    {
        $admin = $this->admin();
        $since = date('Y-m-d H:i:s', time() - 86400);
        $failedJobs24 = (int) Db::name('generation_jobs')->where('status', 'failed')->where('updated_at', '>=', $since)->count();
        $completedJobs24 = (int) Db::name('generation_jobs')->whereIn('status', ['completed', 'partial'])->where('updated_at', '>=', $since)->count();
        $finishedJobs24 = $failedJobs24 + $completedJobs24;
        $affectedMerchantIds = Db::name('generation_jobs')->where('status', 'failed')->where('updated_at', '>=', $since)->column('merchant_id');
        $workerPool = $workerMonitor->poolStatuses(10);
        $onlineWorkers = count(array_filter($workerPool, static fn (array $worker): bool => in_array((string) ($worker['health'] ?? ''), ['online', 'recent'], true)));
        $stats = [
            'merchantCount' => Db::name('merchants')->count(),
            'activeMerchantCount' => Db::name('merchants')->where('status', 'active')->count(),
            'jobCount' => Db::name('generation_jobs')->count(),
            'pendingJobCount' => Db::name('generation_jobs')->whereIn('status', ['queued', 'pending_gateway', 'running', 'manual_review'])->count(),
            'businessCredits' => abs((int) Db::name('credit_ledger')->where('amount', '<', 0)->sum('amount')),
            'assetCount' => Db::name('assets')->where('status', 'active')->count(),
        ];
        $operations = [
            'queuedItems' => (int) Db::name('generation_job_items')->whereIn('status', ['queued', 'pending_gateway'])->count(),
            'processingItems' => (int) Db::name('generation_job_items')->whereIn('status', ['processing', 'workflow_processing', 'manual_review'])->count(),
            'visionBacklog' => (int) Db::name('vision_tasks')->whereIn('status', ['queued', 'retrying', 'running'])->count(),
            'failedJobs24' => $failedJobs24,
            'completedJobs24' => $completedJobs24,
            'failureRate24' => $finishedJobs24 > 0 ? round($failedJobs24 * 100 / $finishedJobs24, 1) : 0.0,
            'affectedMerchants24' => count(array_unique(array_map('intval', $affectedMerchantIds))),
            'safetyEvents24' => (int) Db::name('content_violations')->where('created_at', '>=', $since)->count(),
            'onlineWorkers' => $onlineWorkers,
        ];
        $recentMerchants = Db::name('merchants')->order('id', 'desc')->limit(6)->select()->toArray();
        $recentJobs = Db::name('generation_jobs')->alias('j')->leftJoin('merchants m', 'm.id = j.merchant_id')
            ->field('j.*,m.name as merchant_name')->order('j.id', 'desc')->limit(6)->select()->toArray();
        $recentFailedJobs = Db::name('generation_jobs')->alias('j')->leftJoin('merchants m', 'm.id = j.merchant_id')
            ->leftJoin('skus s', 's.id = j.sku_id')
            ->field('j.id,j.job_no,j.merchant_id,j.status,j.error_message,j.updated_at,m.name as merchant_name,s.name as sku_name')
            ->whereIn('j.status', ['failed', 'pending_gateway'])->order('j.updated_at', 'desc')->limit(8)->select()->toArray();
        $workerStatus = $workerMonitor->status();

        return $this->render('admin/dashboard', compact('admin', 'stats', 'operations', 'recentMerchants', 'recentJobs', 'recentFailedJobs', 'workerStatus') + ['pageTitle' => '运营工作台']);
    }

    public function merchants(): Response
    {
        $admin = $this->admin();
        $keyword = trim((string) $this->request->get('q', ''));
        $status = trim((string) $this->request->get('status', ''));
        $query = Db::name('merchants')->alias('m')
            ->leftJoin('merchant_users u', 'u.id = m.owner_user_id')
            ->field('m.*,u.name as owner_name,u.email as owner_email,u.last_login_at as owner_last_login_at,u.last_login_ip as owner_last_login_ip');
        if ($keyword !== '') {
            $query->whereLike('m.name|m.slug|u.email', '%' . $keyword . '%');
        }
        if (in_array($status, ['active', 'suspended'], true)) {
            $query->where('m.status', $status);
        }
        $merchants = $query->order('m.id', 'desc')->limit(200)->select()->toArray();
        $merchantIds = array_map('intval', array_column($merchants, 'id'));
        $subscriptionsByMerchant = [];
        if ($merchantIds !== []) {
            $subscriptions = Db::name('merchant_subscriptions')
                ->whereIn('merchant_id', $merchantIds)
                ->where('status', 'active')
                ->where('expires_at', '>', Web::now())
                ->order('id', 'desc')
                ->select()
                ->toArray();
            foreach ($subscriptions as $subscription) {
                $subscriptionsByMerchant[(int) $subscription['merchant_id']] ??= $subscription;
            }
        }
        foreach ($merchants as &$merchant) {
            $merchant['current_subscription'] = $subscriptionsByMerchant[(int) $merchant['id']] ?? null;
        }
        unset($merchant);

        return $this->render('admin/merchants', compact('admin', 'merchants', 'keyword', 'status') + ['pageTitle' => '商户账号']);
    }

    public function merchantDetail(int $id, PlanService $plans): Response
    {
        $admin = $this->admin();
        $merchant = Db::name('merchants')->where('id', $id)->find();
        if (!$merchant) {
            return Web::redirect('/admin/merchants', '商户不存在。', 'error');
        }
        $members = Db::name('merchant_users')->where('merchant_id', $id)->order('id', 'asc')->select()->toArray();
        $ledger = Db::name('credit_ledger')->where('merchant_id', $id)->order('id', 'desc')->limit(100)->select()->toArray();
        $counts = [
            'skus' => Db::name('skus')->where('merchant_id', $id)->where('status', 'active')->count(),
            'jobs' => Db::name('generation_jobs')->where('merchant_id', $id)->count(),
            'assets' => Db::name('assets')->where('merchant_id', $id)->where('status', 'active')->count(),
        ];
        $subscription = $plans->currentSubscription($id);
        $availablePlans = Db::name('subscription_plans')->order('sort_order', 'asc')->order('id', 'asc')->select()->toArray();
        $subscriptionHistory = Db::name('merchant_subscriptions')->where('merchant_id', $id)->order('id', 'desc')->limit(30)->select()->toArray();

        return $this->render('admin/merchant_detail', compact('admin', 'merchant', 'members', 'ledger', 'counts', 'subscription', 'availablePlans', 'subscriptionHistory') + ['pageTitle' => '商户详情']);
    }

    public function updateMerchantProfile(int $id): Response
    {
        $admin = $this->admin();
        $name = trim((string) $this->request->post('name', ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            return Web::redirect("/admin/merchants/{$id}", '商户名称应为 2 到 160 个字符。', 'error');
        }
        $updated = Db::name('merchants')->where('id', $id)->update(['name' => $name, 'updated_at' => Web::now()]);
        if ($updated === 0 && !Db::name('merchants')->where('id', $id)->find()) {
            return Web::redirect('/admin/merchants', '商户不存在。', 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'merchant.profile.update', 'merchant', $id, $this->request->ip(), ['name' => $name]);

        return Web::redirect("/admin/merchants/{$id}", '商户资料已更新。');
    }

    public function assignMerchantPlan(int $id, PlanService $plans): Response
    {
        $admin = $this->admin();
        $planId = (int) $this->request->post('plan_id', 0);
        $durationDays = (int) $this->request->post('duration_days', 0);
        try {
            $result = $plans->assignByAdmin($planId, $id, (int) $admin['id'], $durationDays);
        } catch (\Throwable $exception) {
            return Web::redirect("/admin/merchants/{$id}", $exception->getMessage(), 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'merchant.plan.assign', 'merchant', $id, $this->request->ip(), $result);

        return Web::redirect("/admin/merchants/{$id}", '已发放“' . $result['planName'] . '”，有效期至 ' . $result['expiresAt'] . '。');
    }

    public function cancelMerchantPlan(int $id, PlanService $plans): Response
    {
        $admin = $this->admin();
        try {
            $subscription = $plans->cancelByAdmin($id);
        } catch (\Throwable $exception) {
            return Web::redirect("/admin/merchants/{$id}", $exception->getMessage(), 'error');
        }
        if (!$subscription) {
            return Web::redirect("/admin/merchants/{$id}", '该商户当前没有生效中的套餐。', 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'merchant.plan.cancel', 'merchant', $id, $this->request->ip(), [
            'subscription_id' => $subscription['id'], 'plan_name' => $subscription['plan_name'],
        ]);

        return Web::redirect("/admin/merchants/{$id}", '商户套餐已取消，现已恢复按次扣费。');
    }

    public function adjustCredits(int $id): Response
    {
        $admin = $this->admin();
        $amount = (int) $this->request->post('amount', 0);
        $note = trim((string) $this->request->post('note', ''));
        if ($amount === 0 || abs($amount) > 1000000 || mb_strlen($note) < 2) {
            return Web::redirect("/admin/merchants/{$id}", '调额数值或备注不符合要求。', 'error');
        }

        try {
            $balance = Db::transaction(function () use ($id, $amount, $note, $admin) {
                $merchant = Db::name('merchants')->where('id', $id)->lock(true)->find();
                if (!$merchant) {
                    throw new \RuntimeException('merchant_not_found');
                }
                $balance = (int) $merchant['credit_balance'] + $amount;
                if ($balance < 0) {
                    throw new \RuntimeException('insufficient_balance');
                }
                Db::name('merchants')->where('id', $id)->update(['credit_balance' => $balance, 'updated_at' => Web::now()]);
                Db::name('credit_ledger')->insert([
                    'merchant_id' => $id,
                    'type' => $amount > 0 ? 'admin_grant' : 'admin_deduct',
                    'amount' => $amount,
                    'balance_after' => $balance,
                    'source' => 'admin_adjustment',
                    'operator_admin_id' => $admin['id'],
                    'note' => $note,
                    'created_at' => Web::now(),
                ]);
                return $balance;
            });
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage() === 'insufficient_balance'
                ? '扣减后余额不能小于 0。'
                : '商户不存在或数据已变化。';
            return Web::redirect("/admin/merchants/{$id}", $message, 'error');
        }

        Audit::log('admin', (int) $admin['id'], 'merchant.credits.adjust', 'merchant', $id, $this->request->ip(), ['amount' => $amount, 'balance_after' => $balance, 'note' => $note]);
        return Web::redirect("/admin/merchants/{$id}", '点数已调整，当前余额为 ' . $balance . '。');
    }

    public function toggleMerchantStatus(int $id): Response
    {
        $admin = $this->admin();
        $merchant = Db::name('merchants')->where('id', $id)->find();
        if (!$merchant) {
            return Web::redirect('/admin/merchants', '商户不存在。', 'error');
        }
        $status = $merchant['status'] === 'active' ? 'suspended' : 'active';
        Db::transaction(function () use ($id, $status) {
            Db::name('merchants')->where('id', $id)->update(['status' => $status, 'updated_at' => Web::now()]);
            Db::name('merchant_users')->where('merchant_id', $id)->update(['status' => $status === 'active' ? 'active' : 'suspended', 'updated_at' => Web::now()]);
        });
        Audit::log('admin', (int) $admin['id'], 'merchant.status.update', 'merchant', $id, $this->request->ip(), ['status' => $status]);

        return Web::redirect("/admin/merchants/{$id}", $status === 'active' ? '商户已恢复。' : '商户及其成员已停用。');
    }

    public function jobs(GenerationJobView $jobView, \app\service\AdminJobAttentionService $attentionJobs): Response
    {
        $admin = $this->admin();
        $status = trim((string) $this->request->get('status', ''));
        $merchantId = max(0, (int) $this->request->get('merchant_id', 0));
        $keyword = mb_strimwidth(trim((string) $this->request->get('q', '')), 0, 120, '');
        $period = trim((string) $this->request->get('period', ''));
        $resultStatus = trim((string) $this->request->get('result', ''));
        $sort = trim((string) $this->request->get('sort', 'latest'));
        $attention = (string) $this->request->get('attention', '') === '1';
        $queue = (string) $this->request->get('queue', '') === '1';
        $page = max(1, (int) $this->request->get('page', 1));
        $perPage = 50;
        $query = Db::name('generation_jobs')->alias('j')
            ->leftJoin('merchants m', 'm.id = j.merchant_id')
            ->leftJoin('skus s', 's.id = j.sku_id')
            ->leftJoin('free_generation_jobs f', 'f.job_id = j.id')
            ->field('j.*,m.name as merchant_name,s.name as sku_name,f.prompt_template_name as free_template_name,f.prompt_template_code as free_template_code,f.subject_text as free_subject_text,f.user_addition as free_user_addition,f.reference_count as free_reference_count,f.reference_fingerprints_json as free_reference_fingerprints_json');
        if ($attention) {
            $attentionJobs->applyAttention($query, $period !== '' ? $period : '24h');
        } elseif ($queue) {
            $attentionJobs->applyQueue($query);
        } elseif (in_array($status, ['pending_gateway', 'queued', 'running', 'manual_review', 'completed', 'partial', 'failed', 'result_missing', 'cancelled'], true)) {
            $query->where('j.status', $status);
        }
        if (in_array($resultStatus, ['available', 'partial', 'missing'], true)) {
            $activeResultCount = "(SELECT COUNT(*) FROM assets ar WHERE ar.job_id = j.id AND ar.status = 'active' AND ar.file_url <> '')";
            $expectedResultCount = "(CASE WHEN j.output_kind = 'video' THEN 1 WHEN j.progress_total > 0 THEN j.progress_total ELSE 1 END)";
            if ($resultStatus === 'available') {
                $query->whereRaw($activeResultCount . ' >= ' . $expectedResultCount);
            } elseif ($resultStatus === 'partial') {
                $query->whereRaw($activeResultCount . ' > 0 AND ' . $activeResultCount . ' < ' . $expectedResultCount);
            } else {
                $query->whereIn('j.status', ['completed', 'partial', 'failed'])
                    ->whereRaw($activeResultCount . ' = 0');
            }
        }
        if ($merchantId > 0) {
            $query->where('j.merchant_id', $merchantId);
        }
        if ($keyword !== '') {
            $query->whereLike('j.job_no|m.name|s.name|j.model', '%' . $keyword . '%');
        }
        $periodHours = ['24h' => 24, '7d' => 168, '30d' => 720][$period] ?? 0;
        if ($periodHours > 0 && !$attention) {
            $query->where('j.created_at', '>=', date('Y-m-d H:i:s', time() - $periodHours * 3600));
        }
        $total = (int) (clone $query)->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $direction = $sort === 'oldest' ? 'asc' : 'desc';
        $jobs = $jobView->enrich($query->order('j.id', $direction)->page($page, $perPage)->select()->toArray());
        $launchQuery = Db::name('vision_tasks')->alias('v')
            ->leftJoin('merchants m', 'm.id = v.merchant_id')
            ->leftJoin('skus s', 's.id = v.sku_id')
            ->where('v.task_type', 'product_launch')
            ->field('v.*,m.name as merchant_name,s.name as sku_name');
        if ($merchantId > 0) { $launchQuery->where('v.merchant_id', $merchantId); }
        if ($keyword !== '') { $launchQuery->whereLike('v.task_no|m.name|s.name|v.model', '%' . $keyword . '%'); }
        if (in_array($status, ['queued', 'running', 'retrying', 'completed', 'failed'], true)) {
            $launchQuery->where('v.status', $status);
        }
        if ($attention) { $launchQuery->where('v.status', 'failed'); }
        if ($queue) { $launchQuery->whereIn('v.status', ['queued', 'running', 'retrying']); }
        if ($periodHours > 0) { $launchQuery->where('v.created_at', '>=', date('Y-m-d H:i:s', time() - $periodHours * 3600)); }
        $launchTasks = $launchQuery->order('v.id', $direction)->limit(80)->select()->toArray();
        foreach ($launchTasks as &$launchTask) {
            $launchTask['diagnostic'] = json_decode((string) ($launchTask['diagnostic_json'] ?? '{}'), true) ?: [];
        }
        unset($launchTask);
        $filteredMerchant = $merchantId > 0 ? Db::name('merchants')->where('id', $merchantId)->find() : null;

        return $this->render('admin/jobs', compact('admin', 'jobs', 'launchTasks', 'status', 'resultStatus', 'merchantId', 'keyword', 'period', 'sort', 'page', 'perPage', 'total', 'totalPages', 'filteredMerchant', 'attention', 'queue') + ['pageTitle' => '生成任务']);
    }

    public function retryVisionTask(int $id, \app\service\VisionTaskQueue $visionTasks): Response
    {
        $admin = $this->admin();
        $task = Db::name('vision_tasks')->where('id', $id)->where('task_type', 'product_launch')->find();
        if (!$task) { return Web::redirect('/admin/jobs', '商品上新任务不存在。', 'error'); }
        try {
            $accepted = $visionTasks->retryProductLaunch($id, (int) $task['merchant_id']);
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/jobs', localized_job_error($exception->getMessage()), 'error');
        }
        if (!$accepted) { return Web::redirect('/admin/jobs', '该商品上新任务当前没有可重试节点。', 'error'); }
        Audit::log('admin', (int) $admin['id'], 'vision_task.retry', 'vision_task', $id, $this->request->ip());
        return Web::redirect('/admin/jobs', '商品上新失败节点已重新排队，已完成节点不会重复执行。');
    }

    public function retryJob(int $id, NewApiClient $newApi, GenerationItemQueue $itemQueue, VideoGenerationQueue $videoQueue): Response
    {
        $admin = $this->admin();
        $job = Db::name('generation_jobs')->where('id', $id)->find();
        $expected = $job
            ? ((string) ($job['output_kind'] ?? 'image') === 'video'
                ? 1
                : max((int) ($job['progress_total'] ?? 0), count(decode_json_list($job['asset_types'] ?? '[]'))))
            : 0;
        $available = $job ? (int) Db::name('assets')->where('job_id', $id)->where('status', 'active')->where('file_url', '<>', '')->count() : 0;
        $resultMissing = $job && in_array((string) $job['status'], ['completed', 'partial'], true) && $expected > $available;
        if (!$job || (!in_array($job['status'], ['failed', 'partial', 'result_missing', 'pending_gateway'], true) && !$resultMissing)) {
            return Web::redirect('/admin/jobs', '该任务当前不可重试。', 'error');
        }
        $routeSnapshot = json_decode((string) ($job['route_snapshot_json'] ?? ''), true);
        $routeSnapshot = is_array($routeSnapshot) ? $routeSnapshot : [];
        $configured = $newApi->hasAvailableModels(
            (string) ($job['model'] ?: $newApi->defaultModel()),
            max(0, (int) ($routeSnapshot['resolvedModelId'] ?? $job['resolved_ai_model_id'] ?? 0)) ?: null,
            trim((string) ($routeSnapshot['resolvedGateway'] ?? '')) ?: null,
            (string) ($job['output_kind'] ?? 'image') === 'image'
                && in_array((string) ($routeSnapshot['capability'] ?? ''), [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT], true)
                    ? (string) $routeSnapshot['capability']
                    : null
        );
        if ((string) ($job['output_kind'] ?? 'image') === 'video') {
            if (!$configured) {
                return Web::redirect('/admin/jobs', '视频模型当前没有可用渠道，任务已保留。', 'error');
            }
            if (!$videoQueue->requeue($id)) {
                return Web::redirect('/admin/jobs', '该视频任务当前不可重试。', 'error');
            }
            Audit::log('admin', (int) $admin['id'], 'video_generation.retry', 'generation_job', $id, $this->request->ip());
            return Web::redirect('/admin/jobs', '视频任务已重新进入独立队列。');
        }
        $hasItems = $itemQueue->hasItems($id);
        $reactivatedItems = 0;
        if ($hasItems) {
            if ($configured) {
                $reactivatedItems = $itemQueue->activatePendingItems($id);
            } else {
                $reactivatedItems = $itemQueue->retryItems($id, 'pending_gateway');
            }
        }
        if ($hasItems && $reactivatedItems === 0) {
            return Web::redirect('/admin/jobs', '没有可重新生成的失败子任务，任务状态未改变。', 'error');
        }
        $completedCount = (int) Db::name('assets')->where('job_id', $id)->where('status', 'active')->count();
        $total = max((int) ($job['progress_total'] ?? 0), count(decode_json_list($job['asset_types'])));
        Db::name('generation_jobs')->where('id', $id)->update([
            'status' => $configured ? 'queued' : 'pending_gateway',
            'error_message' => $configured ? null : '当前任务模型没有可用渠道',
            'progress_current' => min($completedCount, $total),
            'progress_total' => $total,
            'progress_stage' => $configured ? 'queued' : 'pending_gateway',
            'progress_message' => $configured
                ? ($completedCount > 0 ? "已重新排队，将从 {$completedCount}/{$total} 继续生成" : '已重新排队，等待 Worker 处理')
                : '等待管理员配置可用模型渠道',
            'updated_at' => Web::now(),
        ]);
        Audit::log('admin', (int) $admin['id'], 'generation_job.retry', 'generation_job', $id, $this->request->ip());

        return Web::redirect('/admin/jobs', $configured ? '任务已重新进入队列。' : '任务已保留，当前模型渠道仍不可用。');
    }

    public function worker(WorkerMonitor $workerMonitor, SystemSettings $settings, GenerationItemQueue $itemQueue): Response
    {
        $admin = $this->admin();
        $workerStatus = $workerMonitor->status();
        $concurrency = [
            'global' => max(1, min(10, (int) $settings->get('worker_global_concurrency'))),
            'merchant' => max(1, min(5, (int) $settings->get('worker_merchant_concurrency'))),
            'job' => max(1, min(5, (int) $settings->get('worker_job_concurrency'))),
            'vision' => max(2, min(4, (int) $settings->get('worker_vision_concurrency'))),
        ];
        $workerPool = $workerMonitor->poolStatuses(10);
        $itemCounts = $itemQueue->counts();
        return $this->render('admin/worker', compact('admin', 'workerStatus', 'workerPool', 'concurrency', 'itemCounts') + ['pageTitle' => '任务 Worker']);
    }

    public function saveWorkerSettings(SystemSettings $settings): Response
    {
        $admin = $this->admin();
        $global = max(1, min(10, (int) $this->request->post('global_concurrency', 10)));
        $vision = max(2, min(4, (int) $this->request->post('vision_concurrency', 3)));
        $minimumGlobal = $vision + 3;
        if ($global < $minimumGlobal) {
            return Web::redirect('/admin/worker', "当前商品识别并发为 {$vision}，全局并发至少需要 {$minimumGlobal}，以保留 1 个普通任务槽和 2 个商品上新编排槽。", 'error');
        }
        $merchant = max(1, min(5, $global, (int) $this->request->post('merchant_concurrency', 3)));
        $job = max(1, min(5, $merchant, (int) $this->request->post('job_concurrency', 3)));
        $settings->setMany([
            'worker_global_concurrency' => (string) $global,
            'worker_merchant_concurrency' => (string) $merchant,
            'worker_job_concurrency' => (string) $job,
            'worker_vision_concurrency' => (string) $vision,
        ], (int) $admin['id']);
        Audit::log('admin', (int) $admin['id'], 'worker.concurrency.update', 'system_settings', null, $this->request->ip(), compact('global', 'merchant', 'job', 'vision'));
        return Web::redirect('/admin/worker', "并发设置已更新：全局 {$global}、商品识别 {$vision}、单商户 {$merchant}、单任务 {$job}。Worker 无需重启。");
    }

    public function audit(): Response
    {
        $admin = $this->admin();
        $logs = Db::name('audit_logs')->order('id', 'desc')->limit(300)->select()->toArray();
        return $this->render('admin/audit', compact('admin', 'logs') + ['pageTitle' => '审计日志']);
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }

}
