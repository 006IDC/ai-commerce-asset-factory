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

final class MerchantQuality extends BaseController
{
    public function index(GenerationQualityAppealService $appeals): Response
    {
        [$merchant, $user] = $this->identity();
        $appealRows = $appeals->merchantRows((int) $merchant['id']);
        $eligibleAssets = $appeals->eligibleAssets((int) $merchant['id']);
        $selectedAssetId = max(0, (int) $this->request->get('asset_id', 0));
        return $this->render('merchant/quality_appeals', compact(
            'merchant',
            'user',
            'appealRows',
            'eligibleAssets',
            'selectedAssetId',
            'appeals'
        ) + ['pageTitle' => '生成质量申诉']);
    }

    public function submit(GenerationQualityAppealService $appeals): Response
    {
        [$merchant, $user] = $this->identity();
        try {
            $appeal = $appeals->submit(
                (int) $merchant['id'],
                (int) $user['id'],
                max(0, (int) $this->request->post('asset_id', 0)),
                trim((string) $this->request->post('issue_type', '')),
                trim((string) $this->request->post('description', '')),
                $this->request->ip()
            );
            return Web::redirect(
                '/workspace/quality-appeals',
                '质量申诉 ' . (string) $appeal['appeal_no'] . ' 已提交，生成结果和执行证据已锁定。'
            );
        } catch (RuntimeException $exception) {
            return Web::redirect('/workspace/quality-appeals', $exception->getMessage(), 'error');
        }
    }

    private function identity(): array
    {
        return [
            Db::name('merchants')->where('id', (int) Session::get('merchant_id'))->find(),
            Db::name('merchant_users')->where('id', (int) Session::get('merchant_user_id'))->find(),
        ];
    }
}
