<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php
$healthClass = match ($workerStatus['health']) {
    'online' => 'active',
    'recent' => 'queued',
    'stale' => 'failed',
    default => 'disabled',
};
$lastJobLabel = $workerStatus['last_job_status'] ? job_status_label($workerStatus['last_job_status']) : '暂无记录';
?>
<div class="page-actions">
    <div><h2>生成任务自动化</h2><p>根据 Worker 心跳判断队列处理进程是否正常。</p></div>
    <a class="button" href="/admin/worker"><i data-lucide="refresh-cw"></i>刷新状态</a>
</div>
<section class="stats-grid worker-stats">
    <article class="stat green"><small>启用并发</small><strong class="worker-state-value"><?= (int) $concurrency['global'] ?></strong><span>最多 10 个 Worker 槽位</span></article>
    <article class="stat blue"><small>正在生成</small><strong class="worker-state-value"><?= (int) $itemCounts['processing'] ?></strong><span>等待 <?= (int) $itemCounts['queued'] ?> 张</span></article>
    <article class="stat"><small>子任务成功</small><strong class="worker-state-value"><?= (int) $itemCounts['completed'] ?></strong><span>逐图片独立入库</span></article>
    <article class="stat amber"><small>子任务失败</small><strong class="worker-state-value"><?= (int) $itemCounts['failed'] ?></strong><span>支持只重试失败图片</span></article>
</section>
<section class="panel panel-gap">
    <div class="panel-header"><div><h3>图片生成并发</h3><p>设置保存后约 5 秒内生效，无需重启 Worker。上游频繁出现 429 时应降低并发。</p></div></div>
    <div class="panel-body"><form method="post" action="/admin/worker/settings"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><div class="form-grid worker-concurrency-grid">
        <div class="form-field"><label for="global_concurrency">全局并发</label><input class="input" id="global_concurrency" name="global_concurrency" type="number" min="1" max="10" value="<?= (int) $concurrency['global'] ?>" required><small>全站同时请求上游的图片数量；出现 429 时应适当降低。</small></div>
        <div class="form-field"><label for="vision_concurrency">商品识别并发</label><input class="input" id="vision_concurrency" name="vision_concurrency" type="number" min="2" max="4" value="<?= (int) $concurrency['vision'] ?>" required><small>固定保留 2 个商品上新编排槽；全局并发必须至少为此值加 3。</small></div>
        <div class="form-field"><label for="merchant_concurrency">单商户并发</label><input class="input" id="merchant_concurrency" name="merchant_concurrency" type="number" min="1" max="5" value="<?= (int) $concurrency['merchant'] ?>" required><small>单商户最高 5。建议先从 4 开始；出现上游并发限制时立即下调。</small></div>
        <div class="form-field"><label for="job_concurrency">单任务并发</label><input class="input" id="job_concurrency" name="job_concurrency" type="number" min="1" max="5" value="<?= (int) $concurrency['job'] ?>" required><small>单任务最高 5。10 张图设为 4 时，通常分 3 批完成。</small></div>
    </div><div class="form-footer"><button class="button primary" type="submit">保存并发设置</button></div></form></div>
</section>
<section class="panel panel-gap">
    <div class="panel-header"><div><h3>Worker 槽位</h3><p>安装脚本固定守护 10 个轻量进程，超过当前全局并发的槽位自动待命。</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>槽位</th><th>健康状态</th><th>当前状态</th><th>PID</th><th>当前任务</th><th>最后心跳</th></tr></thead><tbody>
    <?php foreach ($workerPool as $worker): ?><tr><td><strong>#<?= (int) $worker['slot'] ?></strong><?= (int) $worker['slot'] <= (int) $concurrency['global'] ? '<small>已启用</small>' : '<small>待命</small>' ?></td><td><span class="badge <?= $worker['health'] === 'online' ? 'active' : 'failed' ?>"><?= escape_html($worker['healthLabel']) ?></span></td><td><?= escape_html($worker['stateLabel']) ?></td><td class="code-value"><?= escape_html($worker['pid'] ?: '-') ?></td><td class="code-value"><?= escape_html($worker['last_job_no'] ?: '-') ?></td><td><?= escape_html($worker['last_heartbeat_at'] ?: '-') ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<div class="content-grid worker-grid">
    <section class="panel">
        <div class="panel-header"><div><h3>进程详情</h3><p>页面每 10 秒自动刷新</p></div><span class="badge <?= escape_html($healthClass) ?>"><?= escape_html($workerStatus['healthLabel']) ?></span></div>
        <div class="panel-body detail-list">
            <div class="detail-row"><span>运行模式</span><strong><?= escape_html($workerStatus['modeLabel']) ?></strong></div>
            <div class="detail-row"><span>进程 PID</span><strong class="code-value"><?= escape_html($workerStatus['pid'] ?: '-') ?></strong></div>
            <div class="detail-row"><span>运行时长</span><strong><?= escape_html($workerStatus['uptimeLabel']) ?></strong></div>
            <div class="detail-row"><span>最后心跳</span><strong><?= escape_html($workerStatus['last_heartbeat_at'] ?: '-') ?></strong></div>
            <div class="detail-row"><span>最近任务</span><strong class="code-value"><?= escape_html($workerStatus['last_job_no'] ?: '-') ?></strong></div>
            <div class="detail-row"><span>任务结果</span><strong><?= escape_html($lastJobLabel) ?></strong></div>
            <div class="detail-row"><span>回收站清理</span><strong>已内置，每小时最多清理 50 张过期素材</strong></div>
            <div class="detail-row"><span>守护服务</span><strong class="code-value"><?= escape_html($workerStatus['serviceName']) ?></strong></div>
        </div>
    </section>
    <section class="panel">
        <div class="panel-header"><div><h3>推荐：一键安装常驻服务</h3><p>适合 Ubuntu、Debian、CentOS 等 systemd 服务器</p></div></div>
        <div class="panel-body">
            <div class="command-label">在程序安装完成后以 root 执行一次</div>
            <div class="command-row"><code class="command-block" data-command-value><?= escape_html($workerStatus['installCommand']) ?></code><button class="icon-button" type="button" data-copy-command data-tooltip="复制命令" aria-label="复制安装命令"><i data-lucide="copy"></i></button></div>
            <div class="command-label">常驻 Worker 实际命令</div>
            <div class="command-row"><code class="command-block" data-command-value><?= escape_html($workerStatus['command']) ?></code><button class="icon-button" type="button" data-copy-command data-tooltip="复制命令" aria-label="复制常驻 Worker 命令"><i data-lucide="copy"></i></button></div>
            <div class="worker-note">安装脚本会创建系统服务、立即启动并设置开机自启，后续无需配置计划任务。</div>
        </div>
    </section>
    <section class="panel full">
        <div class="panel-header"><div><h3>备选：宝塔计划任务</h3><p>不能安装 systemd 服务时使用，不要与常驻服务同时启用</p></div></div>
        <div class="panel-body worker-schedule-layout">
            <div class="worker-schedule-steps"><div><span>1</span><strong>任务类型</strong><small>Shell 脚本</small></div><div><span>2</span><strong>执行周期</strong><small>每 1 分钟</small></div><div><span>3</span><strong>脚本内容</strong><small>复制右侧完整命令</small></div></div>
            <div><div class="command-row"><code class="command-block" data-command-value><?= escape_html($workerStatus['scheduledCommand']) ?></code><button class="icon-button" type="button" data-copy-command data-tooltip="复制宝塔脚本" aria-label="复制宝塔计划任务脚本"><i data-lucide="copy"></i></button></div><div class="worker-note">命令带互斥锁：上一次还在生成时，本次自动跳过；空闲时会排完现有队列再退出。宝塔计划任务为单进程模式，需要图片并发时请使用上方 systemd 安装脚本。不要额外添加 <span class="code-value">timeout</span>。</div></div>
        </div>
    </section>
    <?php if ($workerStatus['last_error']): ?>
    <section class="panel full"><div class="panel-header"><h3>最近错误</h3></div><div class="panel-body"><div class="worker-error"><?= escape_html($workerStatus['last_error']) ?></div></div></section>
    <?php endif; ?>
</div>
<script>
setTimeout(function () { window.location.reload(); }, 10000);
document.querySelectorAll('[data-copy-command]').forEach(function (button) {
    button.addEventListener('click', function () {
        var command = button.parentElement?.querySelector('[data-command-value]')?.textContent || '';
        navigator.clipboard?.writeText(command);
    });
});
</script>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
