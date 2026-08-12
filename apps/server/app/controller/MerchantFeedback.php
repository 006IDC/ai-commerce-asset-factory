<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\ProductFeedbackService;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class MerchantFeedback extends BaseController
{
    public function index(ProductFeedbackService $feedbackService): Response
    {
        [$merchant, $user] = $this->identity();
        $feedbackRows = Db::name('product_feedback')
            ->where('merchant_id', (int) $merchant['id'])
            ->order('id', 'desc')
            ->limit(30)
            ->select()
            ->toArray();

        return $this->render('merchant/feedback', compact('merchant', 'user', 'feedbackRows', 'feedbackService') + [
            'pageTitle' => '意见反馈',
        ]);
    }

    public function submit(ProductFeedbackService $feedbackService): Response
    {
        [$merchant, $user] = $this->identity();
        $module = trim((string) $this->request->post('module', ''));
        $rating = (int) $this->request->post('rating', 0);
        $issueType = trim((string) $this->request->post('issue_type', ''));
        $improvementType = trim((string) $this->request->post('improvement_type', ''));
        $desiredImprovement = trim((string) $this->request->post('desired_improvement', ''));
        $usageContext = trim((string) $this->request->post('usage_context', ''));
        $relatedReference = trim((string) $this->request->post('related_reference', ''));
        $allowContact = $this->request->post('allow_contact') ? 1 : 0;

        if (!$feedbackService->valid($feedbackService->modules(), $module)
            || !$feedbackService->valid($feedbackService->issueTypes(), $issueType)
            || !$feedbackService->valid($feedbackService->improvementTypes(), $improvementType)
            || $rating < 1 || $rating > 5) {
            return Web::redirect('/workspace/feedback', '请选择完整的体验评分和反馈分类。', 'error');
        }
        if (mb_strlen($desiredImprovement) < 10 || mb_strlen($desiredImprovement) > 2000) {
            return Web::redirect('/workspace/feedback', '最希望改进的内容请填写 10 到 2000 个字符。', 'error');
        }
        if (mb_strlen($usageContext) > 2000 || mb_strlen($relatedReference) > 190) {
            return Web::redirect('/workspace/feedback', '问题说明或关联信息超过长度限制。', 'error');
        }
        $recentCount = Db::name('product_feedback')
            ->where('user_id', (int) $user['id'])
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))
            ->count();
        if ($recentCount >= 5) {
            return Web::redirect('/workspace/feedback', '你提交得有些频繁，请稍后再补充反馈。', 'error');
        }

        $now = Web::now();
        $feedbackId = Db::name('product_feedback')->insertGetId([
            'merchant_id' => (int) $merchant['id'],
            'user_id' => (int) $user['id'],
            'module' => $module,
            'rating' => $rating,
            'issue_type' => $issueType,
            'improvement_type' => $improvementType,
            'desired_improvement' => $desiredImprovement,
            'usage_context' => $usageContext !== '' ? $usageContext : null,
            'related_reference' => $relatedReference !== '' ? $relatedReference : null,
            'allow_contact' => $allowContact,
            'status' => 'new',
            'priority' => 'normal',
            'admin_note' => null,
            'handled_by_admin_id' => null,
            'handled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Audit::log('merchant_user', (int) $user['id'], 'product_feedback.submit', 'product_feedback', (int) $feedbackId, $this->request->ip(), [
            'module' => $module,
            'rating' => $rating,
            'issue_type' => $issueType,
            'improvement_type' => $improvementType,
        ]);

        return Web::redirect('/workspace/feedback', '反馈已提交，运营团队会在这里更新处理进度。');
    }

    private function identity(): array
    {
        return [
            Db::name('merchants')->where('id', (int) Session::get('merchant_id'))->find(),
            Db::name('merchant_users')->where('id', (int) Session::get('merchant_user_id'))->find(),
        ];
    }
}
