<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<div class="page-actions"><div><h2>会员套餐</h2><p>套餐提供功能权限、每日上限和免点权益；点数余额单独用于购买套餐或按次能力。</p></div><a class="button" href="/workspace/usage"><i data-lucide="wallet-cards"></i><span>查看点数与流水</span></a></div>
<?php if ($subscription): ?>
<section class="panel plan-current">
    <div class="panel-header"><div><h3>当前套餐：<?= escape_html($subscription['plan_name']) ?></h3><p>有效期至 <?= escape_html($subscription['expires_at']) ?></p></div><span class="badge active">生效中</span></div>
    <div class="panel-body"><div class="detail-list">
        <div class="detail-row"><span>套餐定位</span><strong><?= escape_html($subscription['audience_label'] ?: '专业电商创作') ?></strong></div>
        <div class="detail-row"><span>整套上架素材</span><strong>已开通</strong></div>
        <div class="detail-row"><span>图片生成</span><strong><?= (int) $subscription['generation_unlimited'] === 1 ? '套餐期内免点数' : '按次扣点' ?></strong></div>
        <div class="detail-row"><span>商品识别</span><strong><?= (int) $subscription['recognition_unlimited'] === 1 ? '套餐期内免点数' : '按次扣点' ?></strong></div>
        <div class="detail-row"><span>今日生成</span><strong><?= number_format($generationUsageToday) ?><?= (int) $subscription['daily_generation_limit'] > 0 ? ' / ' . number_format((int) $subscription['daily_generation_limit']) . ' 张' : ' 张，不限量' ?></strong></div>
    </div></div>
</section>
<?php else: ?>
<div class="gateway-state"><strong>当前为基础账户</strong><p>可使用点数按次创建商品资料、整套上架素材、详情页和画布 AI 编辑；开通会员后可获得套餐内免点与经营权益。</p></div>
<?php endif; ?>
<section class="plan-grid panel-gap">
    <?php foreach ($availablePlans as $plan): ?><article class="plan-card">
        <div class="plan-card-head"><div><small><?= escape_html($plan['audience_label'] ?: ((int) $plan['duration_days'] . ' 天会员')) ?></small><h3><?= escape_html($plan['name']) ?></h3></div><strong><?= number_format((int) $plan['payableCredits']) ?><span>点数</span></strong></div>
        <p><?= escape_html($plan['description'] ?: '购买后立即生效，有效期内按套餐权益计费。') ?></p>
        <?php if ((string) $plan['purchaseMode'] === 'upgrade' && (int) $plan['upgradeCredit'] > 0): ?><div class="plan-upgrade-credit">原套餐剩余价值抵扣 <?= number_format((int) $plan['upgradeCredit']) ?> 点，原价 <?= number_format((int) $plan['price_credits']) ?> 点</div><?php endif; ?>
        <div class="plan-features">
            <div><i data-lucide="package-check"></i><span>整套上架素材：主图与详情页一次创建</span></div>
            <div><i data-lucide="<?= (int) $plan['generation_unlimited'] === 1 ? 'circle-check' : 'circle-minus' ?>"></i><span>图片生成：<?= (int) $plan['generation_unlimited'] === 1 ? '免点数' : '按次扣点' ?></span></div>
            <div><i data-lucide="<?= (int) $plan['recognition_unlimited'] === 1 ? 'circle-check' : 'circle-minus' ?>"></i><span>商品识别：<?= (int) $plan['recognition_unlimited'] === 1 ? '免点数' : '按次扣点' ?></span></div>
            <div><i data-lucide="gauge"></i><span>每日生成：<?= (int) $plan['daily_generation_limit'] > 0 ? number_format((int) $plan['daily_generation_limit']) . ' 张' : '不限制' ?></span></div>
            <div><i data-lucide="layers-3"></i><span>单次生成：<?= (int) ($plan['batch_image_limit'] ?? 0) > 0 ? (int) $plan['batch_image_limit'] . ' 张' : '跟随系统上限' ?></span></div>
            <div><i data-lucide="list-tree"></i><span>同时任务：<?= (int) ($plan['concurrent_job_limit'] ?? 0) > 0 ? (int) $plan['concurrent_job_limit'] . ' 个' : '不额外限制' ?></span></div>
            <div><i data-lucide="calendar-clock"></i><span>有效期：购买后 <?= (int) $plan['duration_days'] ?> 天</span></div>
        </div>
        <form method="post" action="/workspace/plans/<?= (int) $plan['id'] ?>/purchase" onsubmit="return confirm('确认使用 <?= (int) $plan['payableCredits'] ?> 点<?= (string) $plan['purchaseMode'] === 'upgrade' ? '升级到' : '购买' ?> <?= escape_html($plan['name']) ?>？新套餐将立即生效。')">
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
            <?php $insufficient = (int) $merchant['credit_balance'] < (int) $plan['payableCredits']; ?>
            <button class="button primary plan-purchase" type="submit" <?= !$plan['canPurchase'] || $insufficient ? 'disabled' : '' ?>><?php
                if ((string) $plan['purchaseMode'] === 'current') echo '当前套餐';
                elseif ((string) $plan['purchaseMode'] === 'same_level') echo '同等级不可购买';
                elseif ((string) $plan['purchaseMode'] === 'lower') echo '不可降级';
                elseif ($insufficient) echo '点数不足';
                elseif ((string) $plan['purchaseMode'] === 'upgrade') echo '立即升级';
                else echo '立即购买';
            ?></button>
        </form>
    </article><?php endforeach; ?>
    <?php if (!$availablePlans): ?><div class="empty-state"><strong>暂无在售套餐</strong><span>管理员上架套餐后会显示在这里。</span></div><?php endif; ?>
</section>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
