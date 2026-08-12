<?php

declare(strict_types=1);

use app\service\RecognitionBillingService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = (new App(dirname(__DIR__)))->initialize();
$billing = $app->make(RecognitionBillingService::class);
$merchantId = (int) Db::name('merchants')->where('status', 'active')->order('id', 'asc')->value('id');
if ($merchantId <= 0) { throw new RuntimeException('Billing conservation test requires an active fixture merchant.'); }
$original = (int) Db::name('merchants')->where('id', $merchantId)->value('credit_balance');
$reference = 'billing-contract-' . bin2hex(random_bytes(6));
$retryReference = $reference . '-retry-2';

try {
    [, $afterReserve] = $billing->reserve($merchantId, 1, $reference);
    $afterRefund = $billing->refund($merchantId, 1, $reference);
    [, $afterRetryReserve] = $billing->reserve($merchantId, 1, $retryReference);
    $afterRetryRefund = $billing->refund($merchantId, 1, $retryReference);
    if ($afterReserve !== $original - 1 || $afterRefund !== $original
        || $afterRetryReserve !== $original - 1 || $afterRetryRefund !== $original) {
        throw new RuntimeException('Reserve/refund/retry conservation failed.');
    }
    $secondRefund = $billing->refund($merchantId, 1, $retryReference);
    if ($secondRefund !== $original) {
        throw new RuntimeException('Retry refund idempotency failed.');
    }
} finally {
    Db::name('credit_ledger')->where('merchant_id', $merchantId)->whereIn('reference_id', [$reference, $retryReference])->delete();
    Db::name('merchants')->where('id', $merchantId)->update(['credit_balance' => $original]);
}

echo "Product-launch reservation, refund and retry conservation completed successfully.\n";
