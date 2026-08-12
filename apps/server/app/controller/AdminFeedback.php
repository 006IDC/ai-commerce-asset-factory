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

final class AdminFeedback extends BaseController
{
    public function index(ProductFeedbackService $feedbackService): Response
    {
        $admin = $this->admin();
        $filters = $this->filters($feedbackService);
        $query = $this->feedbackQuery();
        $this->applyFilters($query, $filters);
        $feedbackRows = $query->order('f.id', 'desc')->limit(300)->select()->toArray();

        $summary = [
            'total' => Db::name('product_feedback')->count(),
            'new' => Db::name('product_feedback')->where('status', 'new')->count(),
            'planned' => Db::name('product_feedback')->where('status', 'planned')->count(),
            'resolved' => Db::name('product_feedback')->whereIn('status', ['resolved', 'closed'])->count(),
            'averageRating' => round((float) Db::name('product_feedback')->avg('rating'), 1),
        ];
        $improvementRanking = Db::name('product_feedback')
            ->field('improvement_type,COUNT(*) as total')
            ->group('improvement_type')
            ->order('total', 'desc')
            ->limit(6)
            ->select()
            ->toArray();

        return $this->render('admin/feedback', compact('admin', 'feedbackRows', 'feedbackService', 'filters', 'summary', 'improvementRanking') + [
            'pageTitle' => '用户反馈',
        ]);
    }

    public function update(int $id, ProductFeedbackService $feedbackService): Response
    {
        $admin = $this->admin();
        $feedback = Db::name('product_feedback')->where('id', $id)->find();
        if (!$feedback) {
            return Web::redirect('/admin/feedback', '反馈记录不存在。', 'error');
        }
        $status = trim((string) $this->request->post('status', ''));
        $priority = trim((string) $this->request->post('priority', ''));
        $adminNote = trim((string) $this->request->post('admin_note', ''));
        if (!$feedbackService->valid($feedbackService->statuses(), $status)
            || !$feedbackService->valid($feedbackService->priorities(), $priority)
            || mb_strlen($adminNote) > 2000) {
            return Web::redirect('/admin/feedback', '处理状态、优先级或内部备注不符合要求。', 'error');
        }
        $now = Web::now();
        Db::name('product_feedback')->where('id', $id)->update([
            'status' => $status,
            'priority' => $priority,
            'admin_note' => $adminNote !== '' ? $adminNote : null,
            'handled_by_admin_id' => (int) $admin['id'],
            'handled_at' => in_array($status, ['resolved', 'closed'], true) ? $now : null,
            'updated_at' => $now,
        ]);
        Audit::log('admin', (int) $admin['id'], 'product_feedback.update', 'product_feedback', $id, $this->request->ip(), [
            'status' => $status,
            'priority' => $priority,
        ]);

        return Web::redirect('/admin/feedback', '反馈处理状态已更新。');
    }

    public function export(ProductFeedbackService $feedbackService): Response
    {
        $admin = $this->admin();
        $filters = $this->filters($feedbackService);
        $query = $this->feedbackQuery();
        $this->applyFilters($query, $filters);
        $rows = $query->order('f.id', 'desc')->limit(5000)->select()->toArray();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['编号', '商户', '用户邮箱', '使用模块', '评分', '最大问题', '优先改进方向', '具体建议', '问题说明', '关联信息', '允许回访', '状态', '优先级', '内部备注', '提交时间', '更新时间'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, [
                (int) $row['id'],
                $row['merchant_name'],
                $row['user_email'],
                $feedbackService->label($feedbackService->modules(), $row['module']),
                (int) $row['rating'],
                $feedbackService->label($feedbackService->issueTypes(), $row['issue_type']),
                $feedbackService->label($feedbackService->improvementTypes(), $row['improvement_type']),
                $row['desired_improvement'],
                $row['usage_context'] ?: '',
                $row['related_reference'] ?: '',
                (int) $row['allow_contact'] === 1 ? '是' : '否',
                $feedbackService->label($feedbackService->statuses(), $row['status']),
                $feedbackService->label($feedbackService->priorities(), $row['priority']),
                $row['admin_note'] ?: '',
                $row['created_at'],
                $row['updated_at'],
            ], ',', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        Audit::log('admin', (int) $admin['id'], 'product_feedback.export', 'product_feedback', null, $this->request->ip(), ['count' => count($rows)]);

        return Response::create($content, 'html', 200)->header([
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="product-feedback-' . date('Ymd-His') . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function feedbackQuery(): mixed
    {
        return Db::name('product_feedback')->alias('f')
            ->leftJoin('merchants m', 'm.id = f.merchant_id')
            ->leftJoin('merchant_users u', 'u.id = f.user_id')
            ->field('f.*,m.name as merchant_name,u.name as user_name,u.email as user_email');
    }

    private function filters(ProductFeedbackService $feedbackService): array
    {
        $filters = [
            'keyword' => trim((string) $this->request->get('q', '')),
            'status' => trim((string) $this->request->get('status', '')),
            'module' => trim((string) $this->request->get('module', '')),
            'improvement' => trim((string) $this->request->get('improvement', '')),
            'rating' => max(0, min(5, (int) $this->request->get('rating', 0))),
        ];
        if (!$feedbackService->valid($feedbackService->statuses(), $filters['status'])) {
            $filters['status'] = '';
        }
        if (!$feedbackService->valid($feedbackService->modules(), $filters['module'])) {
            $filters['module'] = '';
        }
        if (!$feedbackService->valid($feedbackService->improvementTypes(), $filters['improvement'])) {
            $filters['improvement'] = '';
        }
        return $filters;
    }

    private function applyFilters(mixed $query, array $filters): void
    {
        if ($filters['keyword'] !== '') {
            $query->whereLike('m.name|u.email|f.desired_improvement|f.usage_context|f.related_reference', '%' . $filters['keyword'] . '%');
        }
        if ($filters['status'] !== '') {
            $query->where('f.status', $filters['status']);
        }
        if ($filters['module'] !== '') {
            $query->where('f.module', $filters['module']);
        }
        if ($filters['improvement'] !== '') {
            $query->where('f.improvement_type', $filters['improvement']);
        }
        if ($filters['rating'] > 0) {
            $query->where('f.rating', $filters['rating']);
        }
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
