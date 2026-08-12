<?php

declare(strict_types=1);

namespace app\service;

use app\support\Audit;
use app\support\Web;
use RuntimeException;
use think\facade\Db;

final class GenerationQualityAppealService
{
    public const ISSUE_TYPES = [
        'not_marketing_ready' => '没有营销感',
        'selling_point_missing' => '卖点未表达',
        'batch_duplicate' => '套图重复',
        'text_error' => '文字错误',
        'style_drift' => '款式漂移',
        'wrong_role' => '职责错误',
        'crop_occlusion' => '裁切遮挡',
        'other' => '其他',
    ];

    private const LEGACY_ISSUE_TYPES = [
        'layout_text' => '文字、排版或安全区问题',
        'composition' => '构图或画面职责不符合要求',
        'unexpected_content' => '出现未要求的内容',
        'blur_quality' => '清晰度或成像质量问题',
        'identity_mismatch' => '商品主体变形或不一致',
        'requirement_missed' => '没有执行明确要求',
        'too_similar' => '与原参考图过于相似',
        'style_composition' => '风格或构图明显不符',
        'text_layout' => '文字错误、乱码或排版问题',
        'invalid_output' => '图片损坏、空白、比例或数量错误',
        'wrong_style' => '风格或视觉方向不对',
        'crop_layout' => '裁切、构图或版式问题',
        'text_occlusion' => '文字错误、遮挡或乱码',
        'identity_drift' => '商品主体变形或不一致',
    ];

    public const STATUSES = [
        'pending' => '待受理',
        'reviewing' => '审核中',
        'need_info' => '待补充信息',
        'rejected' => '已驳回',
        'refunded' => '已退款',
        'closed' => '已关闭',
    ];

    public static function issueLabel(string $issueType): string
    {
        return self::ISSUE_TYPES[$issueType]
            ?? self::LEGACY_ISSUE_TYPES[$issueType]
            ?? $issueType;
    }

    public function merchantRows(int $merchantId, int $limit = 100): array
    {
        return Db::name('generation_quality_appeals')->alias('q')
            ->leftJoin('assets a', 'a.id = q.asset_id')
            ->leftJoin('generation_jobs j', 'j.id = q.job_id')
            ->where('q.merchant_id', $merchantId)
            ->field('q.*,a.file_url,a.backup_file_url,a.asset_type,j.job_no,j.model')
            ->order('q.id', 'desc')
            ->limit(max(1, min(300, $limit)))
            ->select()
            ->toArray();
    }

    public function eligibleAssets(int $merchantId, int $limit = 80): array
    {
        return Db::name('assets')->alias('a')
            ->join('generation_job_items i', 'i.id = a.generation_item_id')
            ->join('generation_jobs j', 'j.id = i.job_id')
            ->where('a.merchant_id', $merchantId)
            ->where('a.status', 'active')
            ->where('i.status', 'completed')
            ->where('i.charged_credits', '>', 0)
            ->whereNull('i.technical_refund_ledger_id')
            ->whereNull('i.quality_refund_ledger_id')
            ->where('i.completed_at', '>=', date('Y-m-d H:i:s', time() - 7 * 86400))
            ->field('a.id,a.file_url,a.backup_file_url,a.asset_type,a.created_at,i.id as job_item_id,i.charged_credits,j.job_no,j.model')
            ->order('a.id', 'desc')
            ->limit(max(1, min(200, $limit)))
            ->select()
            ->toArray();
    }

    public function submit(
        int $merchantId,
        int $userId,
        int $assetId,
        string $issueType,
        string $description,
        string $ipAddress
    ): array {
        if (!isset(self::ISSUE_TYPES[$issueType]) && !isset(self::LEGACY_ISSUE_TYPES[$issueType])) {
            throw new RuntimeException('请选择有效的质量问题类型。');
        }
        $description = trim($description);
        if (mb_strlen($description) < 10 || mb_strlen($description) > 2000) {
            throw new RuntimeException('问题说明请填写 10 到 2000 个字符。');
        }

        $appeal = Db::transaction(function () use ($merchantId, $userId, $assetId, $issueType, $description): array {
            $asset = Db::name('assets')->where('id', $assetId)->where('merchant_id', $merchantId)->lock(true)->find();
            if (!$asset || (string) ($asset['status'] ?? '') !== 'active') {
                throw new RuntimeException('申诉素材不存在或已删除。');
            }
            $itemId = max(0, (int) ($asset['generation_item_id'] ?? 0));
            $item = $itemId > 0
                ? Db::name('generation_job_items')->where('id', $itemId)->where('merchant_id', $merchantId)->lock(true)->find()
                : null;
            if (!$item || (string) $item['status'] !== 'completed' || (int) ($item['charged_credits'] ?? 0) <= 0) {
                throw new RuntimeException('只有已成功生成并实际扣费的单张结果可以发起质量申诉。');
            }
            if ((int) ($item['technical_refund_ledger_id'] ?? 0) > 0 || (int) ($item['quality_refund_ledger_id'] ?? 0) > 0) {
                throw new RuntimeException('该结果已经退款，不能重复发起质量申诉。');
            }
            if (strtotime((string) ($item['completed_at'] ?? '')) < time() - 7 * 86400) {
                throw new RuntimeException('该结果已超过 7 天质量申诉期。');
            }
            $active = Db::name('generation_quality_appeals')->where('active_item_key', 'item:' . $itemId)->find();
            if ($active) {
                throw new RuntimeException('该结果已有处理中申诉，请勿重复提交。');
            }
            $job = Db::name('generation_jobs')->where('id', (int) $item['job_id'])->find();
            if (!$job) {
                throw new RuntimeException('生成任务已不存在，无法建立质量证据链。');
            }
            $attempts = Db::name('generation_execution_attempts')
                ->where('generation_item_id', $itemId)
                ->order('attempt_no', 'asc')
                ->select()
                ->toArray();
            $evidence = [
                'schema_version' => 'quality_evidence_v1',
                'captured_at' => Web::now(),
                'asset' => [
                    'id' => (int) $asset['id'],
                    'asset_type' => (string) ($asset['asset_type'] ?? ''),
                    'file_url' => (string) ($asset['file_url'] ?? ''),
                    'backup_file_url' => (string) ($asset['backup_file_url'] ?? ''),
                ],
                'job' => [
                    'id' => (int) $job['id'],
                    'job_no' => (string) $job['job_no'],
                    'model' => (string) $job['model'],
                    'original_idea' => $this->originalIdea($job),
                    'route_snapshot' => $this->object($job['route_snapshot_json'] ?? null),
                    'reference_images' => $this->referenceImages($job),
                ],
                'item' => [
                    'id' => $itemId,
                    'position' => (int) $item['position'],
                    'asset_type' => (string) $item['asset_type'],
                    'charged_credits' => (int) $item['charged_credits'],
                    'consume_ledger_id' => max(0, (int) ($item['consume_ledger_id'] ?? 0)) ?: null,
                    'request_id' => (string) ($item['request_id'] ?? ''),
                ],
                'execution_attempts' => array_map(fn (array $attempt): array => $this->publicAttempt($attempt), $attempts),
            ];
            $now = Web::now();
            $appealNo = 'QA' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
            $appealId = (int) Db::name('generation_quality_appeals')->insertGetId([
                'appeal_no' => $appealNo,
                'merchant_id' => $merchantId,
                'user_id' => $userId,
                'job_id' => (int) $job['id'],
                'job_item_id' => $itemId,
                'asset_id' => (int) $asset['id'],
                'product_code' => $this->appealProductCode($job),
                'issue_type' => $issueType,
                'description' => $description,
                'status' => 'pending',
                'charged_credits' => (int) $item['charged_credits'],
                'consume_ledger_id' => max(0, (int) ($item['consume_ledger_id'] ?? 0)) ?: null,
                'refund_ledger_id' => null,
                'active_item_key' => 'item:' . $itemId,
                'refunded_item_key' => null,
                'evidence_snapshot_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'admin_reason' => null,
                'user_visible_reply' => null,
                'reviewed_by_admin_id' => null,
                'submitted_at' => $now,
                'review_started_at' => null,
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->event($appealId, 'submitted', 'merchant_user', $userId, $description, [
                'issue_type' => $issueType,
                'asset_id' => $assetId,
            ]);
            return Db::name('generation_quality_appeals')->where('id', $appealId)->find();
        });
        Audit::log('merchant_user', $userId, 'generation_quality_appeal.submit', 'generation_quality_appeal', (int) $appeal['id'], $ipAddress, [
            'asset_id' => $assetId,
            'issue_type' => $issueType,
        ]);
        return $appeal;
    }

    public function adminRows(string $status = '', int $limit = 300): array
    {
        $query = Db::name('generation_quality_appeals')->alias('q')
            ->leftJoin('merchants m', 'm.id = q.merchant_id')
            ->leftJoin('merchant_users u', 'u.id = q.user_id')
            ->leftJoin('assets a', 'a.id = q.asset_id')
            ->field('q.*,m.name as merchant_name,u.email as user_email,a.file_url,a.backup_file_url');
        if (isset(self::STATUSES[$status])) {
            $query->where('q.status', $status);
        }
        return $query->order('q.id', 'desc')->limit(max(1, min(500, $limit)))->select()->toArray();
    }

    public function adminPage(array $filters, int $page = 1, int $perPage = 40): array
    {
        $query = Db::name('generation_quality_appeals')->alias('q')
            ->leftJoin('merchants m', 'm.id = q.merchant_id')
            ->leftJoin('merchant_users u', 'u.id = q.user_id')
            ->leftJoin('assets a', 'a.id = q.asset_id');
        $status = trim((string) ($filters['status'] ?? ''));
        if (isset(self::STATUSES[$status])) {
            $query->where('q.status', $status);
        }
        $productCode = trim((string) ($filters['product_code'] ?? ''));
        if (array_key_exists($productCode, CreativeProductService::DEFINITIONS)) {
            $query->where('q.product_code', $productCode);
        } else {
            $productCode = '';
        }
        $issueType = trim((string) ($filters['issue_type'] ?? ''));
        if (isset(self::ISSUE_TYPES[$issueType]) || isset(self::LEGACY_ISSUE_TYPES[$issueType])) {
            $query->where('q.issue_type', $issueType);
        } else {
            $issueType = '';
        }
        $keyword = mb_strimwidth(trim((string) ($filters['keyword'] ?? '')), 0, 120, '');
        if ($keyword !== '') {
            $query->where(static function ($nested) use ($keyword): void {
                $nested->whereLike('q.appeal_no', '%' . $keyword . '%')
                    ->whereOrLike('m.name', '%' . $keyword . '%')
                    ->whereOrLike('u.email', '%' . $keyword . '%');
            });
        }
        $perPage = max(10, min(100, $perPage));
        $total = (int) (clone $query)->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);
        $rows = $query
            ->field('q.*,m.name as merchant_name,u.email as user_email,a.file_url,a.backup_file_url')
            ->order('q.id', 'desc')
            ->page($page, $perPage)
            ->select()
            ->toArray();
        return compact('rows', 'total', 'totalPages', 'page', 'perPage', 'status', 'productCode', 'issueType', 'keyword');
    }

    public function detail(int $appealId): ?array
    {
        $appeal = Db::name('generation_quality_appeals')->alias('q')
            ->leftJoin('merchants m', 'm.id = q.merchant_id')
            ->leftJoin('merchant_users u', 'u.id = q.user_id')
            ->leftJoin('assets a', 'a.id = q.asset_id')
            ->where('q.id', $appealId)
            ->field('q.*,m.name as merchant_name,u.email as user_email,a.file_url,a.backup_file_url')
            ->find();
        if (!$appeal) {
            return null;
        }
        $appeal['evidence'] = $this->object($appeal['evidence_snapshot_json'] ?? null);
        $appeal['events'] = Db::name('generation_quality_appeal_events')
            ->where('appeal_id', $appealId)->order('id', 'asc')->select()->toArray();
        return $appeal;
    }

    public function review(
        int $appealId,
        string $action,
        string $reason,
        string $reply,
        int $adminId,
        string $ipAddress
    ): array {
        if (!in_array($action, ['begin', 'request_info', 'reject', 'approve_refund', 'close'], true)) {
            throw new RuntimeException('未知的质量申诉处理动作。');
        }
        $reason = trim($reason);
        $reply = trim($reply);
        if (in_array($action, ['request_info', 'reject', 'approve_refund', 'close'], true)
            && (mb_strlen($reason) < 4 || mb_strlen($reason) > 2000)) {
            throw new RuntimeException('请填写 4 到 2000 个字符的审核依据。');
        }

        $result = Db::transaction(function () use ($appealId, $action, $reason, $reply, $adminId): array {
            $appeal = Db::name('generation_quality_appeals')->where('id', $appealId)->lock(true)->find();
            if (!$appeal) {
                throw new RuntimeException('质量申诉不存在。');
            }
            if (in_array((string) $appeal['status'], ['rejected', 'refunded', 'closed'], true)) {
                if ($action === 'approve_refund' && (string) $appeal['status'] === 'refunded') {
                    return $appeal;
                }
                throw new RuntimeException('该申诉已经结束，不能重复处理。');
            }
            return match ($action) {
                'approve_refund' => $this->approveAndRefund($appeal, $reason, $reply, $adminId),
                'begin' => $this->transition($appeal, 'reviewing', 'review_started', $reason, $reply, $adminId, false),
                'request_info' => $this->transition($appeal, 'need_info', 'information_requested', $reason, $reply, $adminId, false),
                'reject' => $this->transition($appeal, 'rejected', 'rejected', $reason, $reply, $adminId, true),
                'close' => $this->transition($appeal, 'closed', 'closed', $reason, $reply, $adminId, true),
            };
        });
        Audit::log('admin', $adminId, 'generation_quality_appeal.' . $action, 'generation_quality_appeal', $appealId, $ipAddress, [
            'status' => (string) $result['status'],
            'refund_ledger_id' => $result['refund_ledger_id'] ?? null,
        ]);
        return $result;
    }

    private function approveAndRefund(array $appeal, string $reason, string $reply, int $adminId): array
    {
        $item = Db::name('generation_job_items')->where('id', (int) $appeal['job_item_id'])->lock(true)->find();
        $merchant = Db::name('merchants')->where('id', (int) $appeal['merchant_id'])->lock(true)->find();
        if (!$item || !$merchant) {
            throw new RuntimeException('申诉关联的子任务或商户已不存在。');
        }
        if ((string) $item['status'] !== 'completed' || (int) ($item['charged_credits'] ?? 0) <= 0) {
            throw new RuntimeException('该子任务没有可退的已结算额度。');
        }
        if ((int) ($item['technical_refund_ledger_id'] ?? 0) > 0) {
            throw new RuntimeException('该子任务已经执行技术失败退款，不能再质量退款。');
        }
        if ((int) ($item['quality_refund_ledger_id'] ?? 0) > 0) {
            $ledgerId = (int) $item['quality_refund_ledger_id'];
            Db::name('generation_quality_appeals')->where('id', (int) $appeal['id'])->update([
                'status' => 'refunded',
                'refund_ledger_id' => $ledgerId,
                'active_item_key' => null,
                'refunded_item_key' => 'item:' . (int) $item['id'],
                'updated_at' => Web::now(),
            ]);
            return Db::name('generation_quality_appeals')->where('id', (int) $appeal['id'])->find();
        }
        $consumeLedgerId = max(0, (int) ($item['consume_ledger_id'] ?? $appeal['consume_ledger_id'] ?? 0));
        $consumeLedger = $consumeLedgerId > 0
            ? Db::name('credit_ledger')->where('id', $consumeLedgerId)->lock(true)->find()
            : null;
        if (!$consumeLedger || (string) $consumeLedger['type'] !== 'consume') {
            throw new RuntimeException('找不到该图片的原始消费账本，不能执行质量退款。');
        }
        $credits = (int) $item['charged_credits'];
        $idempotencyKey = 'quality-refund:item:' . (int) $item['id'];
        $existingLedgerId = (int) (Db::name('credit_ledger')->where('idempotency_key', $idempotencyKey)->value('id') ?? 0);
        if ($existingLedgerId > 0) {
            $refundLedgerId = $existingLedgerId;
        } else {
            $balance = (int) $merchant['credit_balance'] + $credits;
            Db::name('merchants')->where('id', (int) $merchant['id'])->update([
                'credit_balance' => $balance,
                'updated_at' => Web::now(),
            ]);
            $refundLedgerId = (int) Db::name('credit_ledger')->insertGetId([
                'merchant_id' => (int) $merchant['id'],
                'type' => 'quality_refund',
                'amount' => $credits,
                'balance_after' => $balance,
                'source' => 'quality_appeal',
                'reference_type' => 'generation_job_item',
                'reference_id' => (string) $item['id'],
                'idempotency_key' => $idempotencyKey,
                'note' => '质量申诉 ' . (string) $appeal['appeal_no'] . ' 审核退款',
                'created_at' => Web::now(),
            ]);
        }
        Db::name('generation_job_items')->where('id', (int) $item['id'])->update([
            'quality_refund_ledger_id' => $refundLedgerId,
            'updated_at' => Web::now(),
        ]);
        $now = Web::now();
        Db::name('generation_quality_appeals')->where('id', (int) $appeal['id'])->update([
            'status' => 'refunded',
            'refund_ledger_id' => $refundLedgerId,
            'active_item_key' => null,
            'refunded_item_key' => 'item:' . (int) $item['id'],
            'admin_reason' => $reason,
            'user_visible_reply' => $reply !== '' ? $reply : '质量问题已审核通过，本张图片消耗额度已原路退回账户。',
            'reviewed_by_admin_id' => $adminId,
            'review_started_at' => $appeal['review_started_at'] ?? $now,
            'resolved_at' => $now,
            'updated_at' => $now,
        ]);
        $this->event((int) $appeal['id'], 'approved', 'admin', $adminId, $reason, ['credits' => $credits]);
        $this->event((int) $appeal['id'], 'refunded', 'system', 0, '质量退款已入账。', [
            'credits' => $credits,
            'refund_ledger_id' => $refundLedgerId,
            'idempotency_key' => $idempotencyKey,
        ]);
        $this->recordImprovement($appeal);
        return Db::name('generation_quality_appeals')->where('id', (int) $appeal['id'])->find();
    }

    private function transition(
        array $appeal,
        string $status,
        string $eventType,
        string $reason,
        string $reply,
        int $adminId,
        bool $resolved
    ): array {
        $now = Web::now();
        Db::name('generation_quality_appeals')->where('id', (int) $appeal['id'])->update([
            'status' => $status,
            'active_item_key' => $resolved ? null : $appeal['active_item_key'],
            'admin_reason' => $reason !== '' ? $reason : $appeal['admin_reason'],
            'user_visible_reply' => $reply !== '' ? $reply : $appeal['user_visible_reply'],
            'reviewed_by_admin_id' => $adminId,
            'review_started_at' => $appeal['review_started_at'] ?? $now,
            'resolved_at' => $resolved ? $now : null,
            'updated_at' => $now,
        ]);
        $this->event((int) $appeal['id'], $eventType, 'admin', $adminId, $reason, [
            'status' => $status,
            'reply' => $reply,
        ]);
        return Db::name('generation_quality_appeals')->where('id', (int) $appeal['id'])->find();
    }

    private function recordImprovement(array $appeal): void
    {
        $productCode = (string) $appeal['product_code'];
        $issueType = (string) $appeal['issue_type'];
        $existing = Db::name('quality_improvement_items')
            ->where('product_code', $productCode)
            ->where('dimension_type', 'product')
            ->where('dimension_value', $productCode)
            ->where('issue_type', $issueType)
            ->lock(true)
            ->find();
        $now = Web::now();
        if ($existing) {
            $ids = $this->list($existing['source_appeal_ids_json'] ?? '[]');
            $ids[] = (int) $appeal['id'];
            $ids = array_values(array_unique(array_map('intval', $ids)));
            Db::name('quality_improvement_items')->where('id', (int) $existing['id'])->update([
                'sample_count' => (int) $existing['sample_count'] + 1,
                'approved_count' => (int) $existing['approved_count'] + 1,
                'issue_rate' => 1,
                'source_appeal_ids_json' => json_encode($ids, JSON_UNESCAPED_SLASHES),
                'status' => 'open',
                'updated_at' => $now,
            ]);
            return;
        }
        Db::name('quality_improvement_items')->insert([
            'product_code' => $productCode,
            'dimension_type' => 'product',
            'dimension_value' => $productCode,
            'issue_type' => $issueType,
            'sample_count' => 1,
            'approved_count' => 1,
            'issue_rate' => 1,
            'status' => 'open',
            'source_appeal_ids_json' => json_encode([(int) $appeal['id']], JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function event(
        int $appealId,
        string $eventType,
        string $actorType,
        int $actorId,
        string $message,
        array $metadata
    ): void {
        Db::name('generation_quality_appeal_events')->insert([
            'appeal_id' => $appealId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId > 0 ? $actorId : null,
            'message' => $message !== '' ? mb_strimwidth($message, 0, 2000, '') : null,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => Web::now(),
        ]);
    }

    private function publicAttempt(array $attempt): array
    {
        return [
            'attempt_no' => (int) $attempt['attempt_no'],
            'execution_channel' => (string) $attempt['execution_channel'],
            'capability' => (string) $attempt['capability'],
            'logical_model' => (string) ($attempt['logical_model'] ?? ''),
            'workflow_code' => (string) ($attempt['workflow_code'] ?? ''),
            'workflow_version' => (int) ($attempt['workflow_version'] ?? 0),
            'workflow_hash' => (string) ($attempt['workflow_hash'] ?? ''),
            'client_request_id' => (string) ($attempt['client_request_id'] ?? ''),
            'comfyui_prompt_id' => (string) ($attempt['comfyui_prompt_id'] ?? ''),
            'sub2api_request_id' => (string) ($attempt['sub2api_request_id'] ?? ''),
            'provider_task_id' => (string) ($attempt['provider_task_id'] ?? ''),
            'status' => (string) $attempt['status'],
            'duration_ms' => (int) ($attempt['duration_ms'] ?? 0),
            'error_code' => (string) ($attempt['error_code'] ?? ''),
            'error_message' => (string) ($attempt['error_message'] ?? ''),
            'failed_node' => (string) ($attempt['failed_node'] ?? ''),
            'result_summary' => $this->object($attempt['result_summary_json'] ?? null),
            'started_at' => (string) $attempt['started_at'],
            'completed_at' => $attempt['completed_at'] ?? null,
        ];
    }

    private function originalIdea(array $job): string
    {
        if ((string) ($job['platform'] ?? '') === 'free') {
            return trim((string) (Db::name('free_generation_jobs')->where('job_id', (int) $job['id'])->value('prompt') ?? ''));
        }
        return trim((string) ($job['task_brief'] ?? ''));
    }

    private function appealProductCode(array $job): string
    {
        if ((string) ($job['platform'] ?? '') === 'free') {
            return 'free_create';
        }
        if ((int) ($job['detail_page_project_id'] ?? 0) > 0) {
            return 'detail_page';
        }
        return (string) ($job['output_kind'] ?? 'image') === 'video' ? 'product_video' : 'product_image';
    }

    private function referenceImages(array $job): array
    {
        $references = [];
        if ((string) ($job['platform'] ?? '') === 'free') {
            $free = Db::name('free_generation_jobs')->where('job_id', (int) $job['id'])->find();
            if ($free) {
                $references[] = [
                    'url' => trim((string) ($free['reference_image_url'] ?? '')),
                    'backup_url' => trim((string) ($free['reference_image_backup_url'] ?? '')),
                    'role' => 'primary',
                ];
                foreach ($this->list($free['reference_images_json'] ?? '[]') as $index => $reference) {
                    if (!is_array($reference)) {
                        continue;
                    }
                    $references[] = [
                        'url' => trim((string) ($reference['url'] ?? '')),
                        'backup_url' => trim((string) ($reference['backup_url'] ?? '')),
                        'role' => 'reference_' . ($index + 2),
                    ];
                }
            }
        }
        if ((int) ($job['sku_id'] ?? 0) > 0) {
            $sku = Db::name('skus')->where('id', (int) $job['sku_id'])->find();
            if ($sku) {
                $references[] = [
                    'url' => trim((string) ($sku['source_image_path'] ?? '')),
                    'backup_url' => trim((string) ($sku['source_image_backup_path'] ?? '')),
                    'role' => 'product_source',
                ];
                foreach ($this->list($sku['reference_images_json'] ?? '[]') as $index => $reference) {
                    if (!is_array($reference)) {
                        continue;
                    }
                    $references[] = [
                        'url' => trim((string) ($reference['url'] ?? '')),
                        'backup_url' => trim((string) ($reference['backup_url'] ?? '')),
                        'role' => 'product_reference_' . ($index + 2),
                    ];
                }
            }
        }
        if ((int) ($job['detail_page_project_id'] ?? 0) > 0) {
            $project = Db::name('detail_page_projects')->where('id', (int) $job['detail_page_project_id'])->find();
            if ($project) {
                $references[] = [
                    'url' => trim((string) ($project['source_image_url'] ?? '')),
                    'backup_url' => trim((string) ($project['source_image_backup_url'] ?? '')),
                    'role' => 'detail_product_source',
                ];
            }
        }
        $seen = [];
        return array_values(array_filter($references, static function (array $reference) use (&$seen): bool {
            $url = (string) (($reference['url'] ?? '') ?: ($reference['backup_url'] ?? ''));
            if ($url === '' || isset($seen[$url])) {
                return false;
            }
            $seen[$url] = true;
            return true;
        }));
    }

    private function object(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function list(mixed $value): array
    {
        $decoded = $this->object($value);
        return array_is_list($decoded) ? $decoded : [];
    }
}
