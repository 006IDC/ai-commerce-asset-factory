<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\RedemptionCardService;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class AdminCards extends BaseController
{
    public function index(): Response
    {
        $admin = $this->admin();
        $batches = Db::name('redemption_card_batches')->order('id', 'desc')->limit(100)->select()->toArray();
        foreach ($batches as &$batch) {
            $batch['redeemed_count'] = Db::name('redemption_cards')->where('batch_id', $batch['id'])->where('status', 'redeemed')->count();
            $batch['unused_count'] = Db::name('redemption_cards')->where('batch_id', $batch['id'])->where('status', 'unused')->count();
        }
        unset($batch);
        $recentRedemptions = Db::name('redemption_cards')->alias('c')
            ->leftJoin('redemption_card_batches b', 'b.id = c.batch_id')
            ->leftJoin('merchants m', 'm.id = c.redeemed_by_merchant_id')
            ->field('c.*,b.batch_no,m.name as merchant_name')->where('c.status', 'redeemed')
            ->order('c.redeemed_at', 'desc')->limit(50)->select()->toArray();

        return $this->render('admin/cards', compact('admin', 'batches', 'recentRedemptions') + ['pageTitle' => '点数卡密']);
    }

    public function generate(RedemptionCardService $cards): Response
    {
        $admin = $this->admin();
        $label = trim((string) $this->request->post('label', ''));
        $credits = (int) $this->request->post('credits', 0);
        $count = (int) $this->request->post('count', 0);
        $expiresInput = trim((string) $this->request->post('expires_at', ''));
        $expiresAt = null;
        if ($expiresInput !== '') {
            $timestamp = strtotime($expiresInput);
            if ($timestamp === false || $timestamp <= time()) {
                return Web::redirect('/admin/cards', '过期时间必须晚于当前时间。', 'error');
            }
            $expiresAt = date('Y-m-d H:i:s', $timestamp);
        }
        if (mb_strlen($label) < 2 || mb_strlen($label) > 120 || $credits < 1 || $credits > 1000000 || $count < 1 || $count > 500) {
            return Web::redirect('/admin/cards', '批次名称、点数或生成数量不符合要求。', 'error');
        }
        try {
            $batch = $cards->generateBatch((int) $admin['id'], $label, $credits, $count, $expiresAt);
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/cards', $exception->getMessage(), 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'redemption_card.batch.generate', 'redemption_card_batch', (int) $batch['id'], $this->request->ip(), [
            'batch_no' => $batch['batch_no'],
            'credits' => $credits,
            'count' => $count,
        ]);

        return Web::redirect('/admin/cards', '卡密批次已生成，请点击导出 CSV。');
    }

    public function export(int $id, RedemptionCardService $cards): Response
    {
        try {
            [$batch, $rows] = $cards->exportBatch($id);
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/cards', $exception->getMessage(), 'error');
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['批次号', '卡密', '点数', '状态', '兑换商户ID', '兑换时间', '过期时间'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, [
                $batch['batch_no'],
                $row['code'],
                (int) $row['credits'],
                record_status_label($row['status']),
                $row['redeemed_by_merchant_id'] ?: '',
                $row['redeemed_at'] ?: '',
                $batch['expires_at'] ?: '',
            ], ',', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        Audit::log('admin', (int) $this->admin()['id'], 'redemption_card.batch.export', 'redemption_card_batch', $id, $this->request->ip());

        return Response::create($content, 'html', 200)->header([
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $batch['batch_no'] . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function toggle(int $id, RedemptionCardService $cards): Response
    {
        $admin = $this->admin();
        try {
            $status = $cards->toggleBatch($id);
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/cards', $exception->getMessage(), 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'redemption_card.batch.status', 'redemption_card_batch', $id, $this->request->ip(), ['status' => $status]);

        return Web::redirect('/admin/cards', $status === 'active' ? '卡密批次已启用。' : '卡密批次已停用。');
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
