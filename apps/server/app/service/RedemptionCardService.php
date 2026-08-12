<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use app\support\Web;
use think\facade\Db;

final class RedemptionCardService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly SecretCipher $cipher)
    {
    }

    public function generateBatch(int $adminId, string $label, int $credits, int $count, ?string $expiresAt): array
    {
        return Db::transaction(function () use ($adminId, $label, $credits, $count, $expiresAt): array {
            $now = Web::now();
            $batchNo = 'CARD-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $batchId = Db::name('redemption_card_batches')->insertGetId([
                'batch_no' => $batchNo,
                'label' => $label,
                'credits' => $credits,
                'total_count' => $count,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'created_by_admin_id' => $adminId,
                'created_at' => $now,
            ]);
            $codes = [];
            for ($index = 0; $index < $count; $index++) {
                do {
                    $code = $this->newCode();
                    $hash = $this->codeHash($code);
                } while (isset($codes[$hash]) || Db::name('redemption_cards')->where('code_hash', $hash)->find());
                $codes[$hash] = $code;
                Db::name('redemption_cards')->insert([
                    'batch_id' => $batchId,
                    'code_hash' => $hash,
                    'code_ciphertext' => $this->cipher->encrypt($code),
                    'code_last4' => substr(str_replace('-', '', $code), -4),
                    'credits' => $credits,
                    'status' => 'unused',
                    'redeemed_by_merchant_id' => null,
                    'redeemed_by_user_id' => null,
                    'redeemed_at' => null,
                    'created_at' => $now,
                ]);
            }

            return ['id' => $batchId, 'batch_no' => $batchNo, 'codes' => array_values($codes)];
        });
    }

    public function exportBatch(int $batchId): array
    {
        $batch = Db::name('redemption_card_batches')->where('id', $batchId)->find();
        if (!$batch) {
            throw new RuntimeException('卡密批次不存在。');
        }
        $cards = Db::name('redemption_cards')->where('batch_id', $batchId)->order('id', 'asc')->select()->toArray();
        foreach ($cards as &$card) {
            $card['code'] = $this->cipher->decrypt((string) $card['code_ciphertext']);
        }
        unset($card);

        return [$batch, $cards];
    }

    public function redeem(string $code, int $merchantId, int $userId): int
    {
        $normalized = $this->normalizeCode($code);
        if (strlen($normalized) !== 18 || !str_starts_with($normalized, 'AF')) {
            throw new RuntimeException('卡密格式不正确。');
        }

        return Db::transaction(function () use ($normalized, $merchantId, $userId): int {
            $card = Db::name('redemption_cards')->where('code_hash', $this->codeHash($normalized))->lock(true)->find();
            if (!$card || $card['status'] !== 'unused') {
                throw new RuntimeException('卡密不存在、已兑换或已失效。');
            }
            $batch = Db::name('redemption_card_batches')->where('id', $card['batch_id'])->find();
            if (!$batch || $batch['status'] !== 'active') {
                throw new RuntimeException('该卡密批次当前不可兑换。');
            }
            if ($batch['expires_at'] && strtotime((string) $batch['expires_at']) < time()) {
                Db::name('redemption_cards')->where('id', $card['id'])->update(['status' => 'expired']);
                throw new RuntimeException('该卡密已经过期。');
            }
            $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            if (!$merchant || $merchant['status'] !== 'active') {
                throw new RuntimeException('当前商户状态不可兑换卡密。');
            }
            $balance = (int) $merchant['credit_balance'] + (int) $card['credits'];
            $now = Web::now();
            Db::name('merchants')->where('id', $merchantId)->update(['credit_balance' => $balance, 'updated_at' => $now]);
            Db::name('redemption_cards')->where('id', $card['id'])->update([
                'status' => 'redeemed',
                'redeemed_by_merchant_id' => $merchantId,
                'redeemed_by_user_id' => $userId,
                'redeemed_at' => $now,
            ]);
            Db::name('credit_ledger')->insert([
                'merchant_id' => $merchantId,
                'type' => 'card_redeem',
                'amount' => (int) $card['credits'],
                'balance_after' => $balance,
                'source' => 'redemption_card',
                'reference_type' => 'redemption_card',
                'reference_id' => (string) $card['id'],
                'note' => '兑换卡密 ' . $batch['batch_no'] . ' / ****' . $card['code_last4'],
                'created_at' => $now,
            ]);

            return (int) $card['credits'];
        });
    }

    public function toggleBatch(int $batchId): string
    {
        $batch = Db::name('redemption_card_batches')->where('id', $batchId)->find();
        if (!$batch) {
            throw new RuntimeException('卡密批次不存在。');
        }
        $status = $batch['status'] === 'active' ? 'disabled' : 'active';
        Db::name('redemption_card_batches')->where('id', $batchId)->update(['status' => $status]);
        return $status;
    }

    private function newCode(): string
    {
        $raw = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($index = 0; $index < 16; $index++) {
            $raw .= self::ALPHABET[random_int(0, $max)];
        }
        return 'AF-' . implode('-', str_split($raw, 4));
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($code)));
    }

    private function codeHash(string $code): string
    {
        return $this->cipher->fingerprint($this->normalizeCode($code), 'redemption_card');
    }
}
