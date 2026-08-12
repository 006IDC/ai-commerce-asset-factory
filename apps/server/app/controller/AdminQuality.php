<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\GenerationQualityAppealService;
use app\support\Web;
use RuntimeException;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class AdminQuality extends BaseController
{
    public function index(GenerationQualityAppealService $appeals): Response
    {
        $admin = $this->admin();
        $status = trim((string) $this->request->get('status', ''));
        if ($status !== '' && !isset(GenerationQualityAppealService::STATUSES[$status])) {
            $status = '';
        }
        $pageData = $appeals->adminPage([
            'status' => $status,
            'product_code' => (string) $this->request->get('product_code', ''),
            'issue_type' => (string) $this->request->get('issue_type', ''),
            'keyword' => (string) $this->request->get('q', ''),
        ], max(1, (int) $this->request->get('page', 1)));
        $appealRows = $pageData['rows'];
        $productCode = (string) $pageData['productCode'];
        $issueType = (string) $pageData['issueType'];
        $keyword = (string) $pageData['keyword'];
        $page = (int) $pageData['page'];
        $total = (int) $pageData['total'];
        $totalPages = (int) $pageData['totalPages'];
        $summary = [];
        foreach (GenerationQualityAppealService::STATUSES as $value => $_label) {
            $summary[$value] = (int) Db::name('generation_quality_appeals')->where('status', $value)->count();
        }
        return $this->render('admin/quality_appeals', compact(
            'admin',
            'appeals',
            'appealRows',
            'summary',
            'status',
            'productCode',
            'issueType',
            'keyword',
            'page',
            'total',
            'totalPages'
        ) + ['pageTitle' => '生成质量仲裁']);
    }

    public function detail(int $id, GenerationQualityAppealService $appeals): Response
    {
        $admin = $this->admin();
        $appeal = $appeals->detail($id);
        if (!$appeal) {
            return Web::redirect('/admin/quality-appeals', '质量申诉不存在。', 'error');
        }
        return $this->render('admin/quality_appeal_detail', compact('admin', 'appeals', 'appeal') + [
            'pageTitle' => '质量仲裁 · ' . (string) $appeal['appeal_no'],
        ]);
    }

    public function review(int $id, GenerationQualityAppealService $appeals): Response
    {
        $admin = $this->admin();
        try {
            $result = $appeals->review(
                $id,
                trim((string) $this->request->post('action', '')),
                trim((string) $this->request->post('reason', '')),
                trim((string) $this->request->post('reply', '')),
                (int) $admin['id'],
                $this->request->ip()
            );
            return Web::redirect(
                '/admin/quality-appeals/' . $id,
                '申诉已更新为“' . (GenerationQualityAppealService::STATUSES[(string) $result['status']] ?? (string) $result['status']) . '”。'
            );
        } catch (RuntimeException $exception) {
            return Web::redirect('/admin/quality-appeals/' . $id, $exception->getMessage(), 'error');
        }
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
