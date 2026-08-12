<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\OperationsSettings;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class AdminPlans extends BaseController
{
    public function index(): Response
    {
        $admin = $this->admin();
        $plans = Db::name('subscription_plans')->order('sort_order', 'asc')->order('id', 'asc')->select()->toArray();
        foreach ($plans as &$plan) {
            $plan['subscription_count'] = Db::name('merchant_subscriptions')->where('plan_id', $plan['id'])->count();
            $plan['active_subscription_count'] = Db::name('merchant_subscriptions')
                ->where('plan_id', $plan['id'])
                ->where('status', 'active')
                ->where('expires_at', '>', Web::now())
                ->count();
        }
        unset($plan);

        return $this->render('admin/plans', compact('admin', 'plans') + ['pageTitle' => '套餐管理']);
    }

    public function createPage(): Response
    {
        $admin = $this->admin();
        $plan = $this->emptyPlan();
        return $this->render('admin/plan_form', compact('admin', 'plan') + [
            'pageTitle' => '新建套餐',
            'formAction' => '/admin/plans',
            'isEdit' => false,
        ]);
    }

    public function create(): Response
    {
        $admin = $this->admin();
        try {
            $data = $this->validatedData();
            if (Db::name('subscription_plans')->where('code', $data['code'])->find()) {
                throw new \RuntimeException('套餐代码已存在。');
            }
            $now = Web::now();
            $id = (int) Db::name('subscription_plans')->insertGetId($data + [
                'created_by_admin_id' => $admin['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/plans/new', $exception->getMessage(), 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'plan.create', 'subscription_plan', $id, $this->request->ip(), $data);

        return Web::redirect('/admin/plans', '套餐已创建。');
    }

    public function editPage(int $id): Response
    {
        $admin = $this->admin();
        $plan = Db::name('subscription_plans')->where('id', $id)->find();
        if (!$plan) {
            return Web::redirect('/admin/plans', '套餐不存在。', 'error');
        }

        return $this->render('admin/plan_form', compact('admin', 'plan') + [
            'pageTitle' => '编辑套餐',
            'formAction' => '/admin/plans/' . $id,
            'isEdit' => true,
        ]);
    }

    public function update(int $id): Response
    {
        $admin = $this->admin();
        $plan = Db::name('subscription_plans')->where('id', $id)->find();
        if (!$plan) {
            return Web::redirect('/admin/plans', '套餐不存在。', 'error');
        }
        try {
            $data = $this->validatedData();
            $duplicate = Db::name('subscription_plans')->where('code', $data['code'])->where('id', '<>', $id)->find();
            if ($duplicate) {
                throw new \RuntimeException('套餐代码已存在。');
            }
            Db::name('subscription_plans')->where('id', $id)->update($data + ['updated_at' => Web::now()]);
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/plans/' . $id . '/edit', $exception->getMessage(), 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'plan.update', 'subscription_plan', $id, $this->request->ip(), $data);

        return Web::redirect('/admin/plans', '套餐已更新，已购买套餐仍按购买时的配置执行。');
    }

    public function toggle(int $id): Response
    {
        $admin = $this->admin();
        $plan = Db::name('subscription_plans')->where('id', $id)->find();
        if (!$plan) {
            return Web::redirect('/admin/plans', '套餐不存在。', 'error');
        }
        $status = $plan['status'] === 'active' ? 'inactive' : 'active';
        Db::name('subscription_plans')->where('id', $id)->update(['status' => $status, 'updated_at' => Web::now()]);
        Audit::log('admin', (int) $admin['id'], 'plan.status.update', 'subscription_plan', $id, $this->request->ip(), ['status' => $status]);

        return Web::redirect('/admin/plans', $status === 'active' ? '套餐已上架。' : '套餐已下架，已购买套餐不受影响。');
    }

    public function delete(int $id, OperationsSettings $operations): Response
    {
        $admin = $this->admin();
        try {
            if ((int) $operations->registrationBenefit()['planId'] === $id) {
                throw new \RuntimeException('该套餐正在作为注册赠送套餐使用，请先在运营设置中取消关联。');
            }
            $plan = Db::transaction(function () use ($id): array {
                $plan = Db::name('subscription_plans')->where('id', $id)->lock(true)->find();
                if (!$plan) {
                    throw new \RuntimeException('套餐不存在。');
                }
                $subscriptions = (int) Db::name('merchant_subscriptions')->where('plan_id', $id)->count();
                if ($subscriptions > 0) {
                    throw new \RuntimeException('该套餐已有购买或发放记录，不能删除；请下架套餐以保留商户历史权益。');
                }
                Db::name('subscription_plans')->where('id', $id)->delete();

                return $plan;
            });
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/plans', $exception->getMessage(), 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'plan.delete', 'subscription_plan', $id, $this->request->ip(), [
            'code' => $plan['code'], 'name' => $plan['name'],
        ]);

        return Web::redirect('/admin/plans', '未产生订阅记录的套餐已删除。');
    }

    private function validatedData(): array
    {
        $code = strtolower(trim((string) $this->request->post('code', '')));
        $name = trim((string) $this->request->post('name', ''));
        $description = trim((string) $this->request->post('description', ''));
        $durationDays = (int) $this->request->post('duration_days', 0);
        $priceCredits = (int) $this->request->post('price_credits', -1);
        $tierLevel = (int) $this->request->post('tier_level', 1);
        $audienceLabel = trim((string) $this->request->post('audience_label', ''));
        $dailyLimit = (int) $this->request->post('daily_generation_limit', -1);
        $sortOrder = (int) $this->request->post('sort_order', 0);
        $generationUnlimited = $this->request->post('generation_unlimited') ? 1 : 0;
        $recognitionUnlimited = $this->request->post('recognition_unlimited') ? 1 : 0;
        $skuLimit = (int) $this->request->post('sku_limit', 0);
        $batchImageLimit = (int) $this->request->post('batch_image_limit', 0);
        $concurrentJobLimit = (int) $this->request->post('concurrent_job_limit', 0);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $code)) {
            throw new \RuntimeException('套餐代码应为 2 到 40 位小写字母、数字、短横线或下划线。');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || mb_strlen($description) > 1000) {
            throw new \RuntimeException('套餐名称或说明长度不符合要求。');
        }
        if (mb_strlen($audienceLabel) > 120) {
            throw new \RuntimeException('适用人群说明不能超过 120 个字符。');
        }
        if ($durationDays < 1 || $durationDays > 3650 || $priceCredits < 0 || $priceCredits > 1000000) {
            throw new \RuntimeException('有效天数或购买点数不符合要求。');
        }
        if ($dailyLimit < 0 || $dailyLimit > 100000 || $sortOrder < 0 || $sortOrder > 9999 || $tierLevel < 1 || $tierLevel > 999) {
            throw new \RuntimeException('套餐等级、每日生成上限或排序值不符合要求。');
        }
        if ($skuLimit < 0 || $skuLimit > 100000
            || $batchImageLimit < 0 || $batchImageLimit > generation_job_image_limit()
            || $concurrentJobLimit < 0 || $concurrentJobLimit > 100) {
            throw new \RuntimeException('店铺数、SKU 数、单次批量或并发任务限额不符合要求。');
        }
        if ($generationUnlimited !== 1 && $recognitionUnlimited !== 1) {
            throw new \RuntimeException('套餐至少需要包含一项会员权益。');
        }

        return [
            'code' => $code,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'duration_days' => $durationDays,
            'price_credits' => $priceCredits,
            'tier_level' => $tierLevel,
            'audience_label' => $audienceLabel !== '' ? $audienceLabel : null,
            'generation_unlimited' => $generationUnlimited,
            'recognition_unlimited' => $recognitionUnlimited,
            'daily_generation_limit' => $dailyLimit,
            'sku_limit' => $skuLimit,
            'batch_image_limit' => $batchImageLimit,
            'concurrent_job_limit' => $concurrentJobLimit,
            'status' => (string) $this->request->post('status', 'active') === 'inactive' ? 'inactive' : 'active',
            'sort_order' => $sortOrder,
        ];
    }

    private function emptyPlan(): array
    {
        return [
            'code' => '',
            'name' => '',
            'description' => '',
            'duration_days' => 30,
            'price_credits' => 0,
            'tier_level' => 1,
            'audience_label' => '适合起步阶段的单店商家',
            'generation_unlimited' => 1,
            'recognition_unlimited' => 0,
            'daily_generation_limit' => 0,
            'sku_limit' => 0,
            'batch_image_limit' => 0,
            'concurrent_job_limit' => 0,
            'status' => 'active',
            'sort_order' => 0,
        ];
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
