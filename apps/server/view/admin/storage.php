<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions">
    <div><h2>商户素材存储</h2><p>远程存储可保留本地副本，历史素材支持分批迁移。</p></div>
    <form method="post" action="/admin/storage/test"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="button" type="submit">测试读写</button></form>
</div>
<div class="content-grid settings-layout">
    <section class="panel">
        <div class="panel-header"><div><h3>存储方式</h3><p>支持本地存储及主流对象存储服务</p></div></div>
        <div class="panel-body">
            <form method="post" action="/admin/storage/settings" data-storage-form>
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <div class="storage-options">
                    <?php foreach (['local' => ['server', '本地存储', '图片保存在当前服务器'], 'cos' => ['cloud', '腾讯云 COS', '适合国内腾讯云部署'], 'oss' => ['cloud-cog', '阿里云 OSS', '适合国内阿里云部署'], 'r2' => ['cloudy', 'Cloudflare R2', '适合海外与 CDN 场景']] as $value => [$icon, $label, $description]): ?>
                        <label class="storage-option"><input type="radio" name="driver" value="<?= $value ?>" <?= $configuration['driver'] === $value ? 'checked' : '' ?>><span><i data-lucide="<?= $icon ?>"></i><strong><?= $label ?></strong><small><?= $description ?></small></span></label>
                    <?php endforeach; ?>
                </div>

                <div class="settings-section">
                    <div class="form-grid">
                        <div class="form-field full"><label for="path_prefix">素材目录</label><input class="input code-value" id="path_prefix" name="path_prefix" value="<?= escape_html($configuration['pathPrefix']) ?>" placeholder="generated" required><small>系统会继续按商户和日期自动分目录。</small></div>
                    </div>
                </div>

                <div class="advanced-settings remote-storage-settings" data-remote-storage>
                    <div class="storage-provider-note" data-storage-note></div>
                    <div class="form-grid">
                        <div class="form-field"><label for="bucket">Bucket 名称</label><input class="input code-value" id="bucket" name="bucket" value="<?= escape_html($configuration['bucket']) ?>" data-remote-required></div>
                        <div class="form-field"><label for="region">地域标识</label><input class="input code-value" id="region" name="region" value="<?= escape_html($configuration['region']) ?>" placeholder="ap-guangzhou" data-remote-required></div>
                        <div class="form-field full"><label for="endpoint">服务端点</label><input class="input code-value" id="endpoint" name="endpoint" type="url" value="<?= escape_html($configuration['endpoint']) ?>" placeholder="https://account-id.r2.cloudflarestorage.com"><small>COS 和 OSS 留空时按 Bucket 与地域自动生成；R2 必须填写账户端点。</small></div>
                        <div class="form-field"><label for="access_key">AccessKey</label><input class="input code-value" id="access_key" name="access_key" type="password" placeholder="<?= $configuration['accessKeyConfigured'] ? '已安全保存，留空表示不修改' : '请输入 AccessKey' ?>" autocomplete="new-password"></div>
                        <div class="form-field"><label for="secret_key">SecretKey</label><input class="input code-value" id="secret_key" name="secret_key" type="password" placeholder="<?= $configuration['secretKeyConfigured'] ? '已安全保存，留空表示不修改' : '请输入 SecretKey' ?>" autocomplete="new-password"></div>
                        <div class="form-field full"><label for="public_url">素材访问域名</label><input class="input code-value" id="public_url" name="public_url" type="url" value="<?= escape_html($configuration['publicUrl']) ?>" placeholder="https://assets.example.com" data-remote-required><small>填写已开启公开读取或绑定 CDN 的域名，不要以斜杠结尾。</small></div>
                        <div class="form-field full"><label class="toggle-field"><input type="checkbox" name="local_backup_enabled" value="1" <?= $configuration['localBackupEnabled'] ? 'checked' : '' ?>><span><strong>同时保留本地副本</strong><small>远程存储故障时，系统可以读取服务器上的备份文件。</small></span></label></div>
                    </div>
                </div>
                <div class="form-footer"><button class="button primary" type="submit">保存存储配置</button></div>
            </form>
        </div>
    </section>
    <section class="panel"><div class="panel-header"><h3>当前状态</h3></div><div class="panel-body detail-list">
        <div class="detail-row"><span>存储方式</span><strong><?= escape_html($configuration['driverLabel']) ?></strong></div>
        <div class="detail-row"><span>配置状态</span><strong><span class="badge <?= $configuration['configured'] ? 'active' : 'pending' ?>"><?= $configuration['configured'] ? '可用' : '待完善' ?></span></strong></div>
        <div class="detail-row"><span>素材目录</span><strong class="code-value"><?= escape_html($configuration['pathPrefix']) ?></strong></div>
        <?php if ($configuration['remote']): ?>
            <div class="detail-row"><span>Bucket</span><strong class="code-value"><?= escape_html($configuration['bucket'] ?: '-') ?></strong></div>
            <div class="detail-row"><span>服务端点</span><strong class="code-value"><?= escape_html($configuration['resolvedEndpoint'] ?: '-') ?></strong></div>
            <div class="detail-row"><span>访问域名</span><strong class="code-value"><?= escape_html($configuration['publicUrl'] ?: '-') ?></strong></div>
            <div class="detail-row"><span>访问密钥</span><strong><?= $configuration['accessKeyConfigured'] && $configuration['secretKeyConfigured'] ? '已安全保存' : '未设置' ?></strong></div>
            <div class="detail-row"><span>本地冗余</span><strong><?= $configuration['localBackupEnabled'] ? '已启用' : '未启用' ?></strong></div>
        <?php else: ?>
            <div class="detail-row"><span>文件目录</span><strong class="code-value">public/storage/<?= escape_html($configuration['pathPrefix']) ?></strong></div>
        <?php endif; ?>
    </div></section>
</div>
<section class="panel panel-gap">
    <div class="panel-header"><div><h3>历史素材迁移</h3><p>上传到当前远程存储，校验成功后切换主地址并保留本地备份</p></div><div class="button-row"><form method="post" action="/admin/storage/migrations/scan"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="button" type="submit">扫描本地素材</button></form><form method="post" action="/admin/storage/migrations/process"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="button primary" type="submit" <?= !$configuration['remote'] || !$configuration['configured'] ? 'disabled' : '' ?>>迁移下一批</button></form></div></div>
    <div class="stats-grid migration-stats">
        <article class="stat"><small>本地素材图</small><strong><?= (int) $migrationStatus['localAssets'] ?></strong><span>等待迁移的有效素材</span></article>
        <article class="stat"><small>本地 SKU 图</small><strong><?= (int) $migrationStatus['localSkus'] ?></strong><span>等待迁移的商品参考图</span></article>
        <article class="stat"><small>本地详情页原图</small><strong><?= (int) $migrationStatus['localDetailProjects'] ?></strong><span>等待迁移的实拍参考图</span></article>
        <article class="stat"><small>待处理</small><strong><?= (int) $migrationStatus['pending'] ?></strong><span>包含可重试失败任务</span></article>
        <article class="stat"><small>迁移完成</small><strong><?= (int) $migrationStatus['completed'] ?></strong><span>本地副本仍然保留</span></article>
    </div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>类型</th><th>来源</th><th>目标</th><th>状态</th><th>尝试</th><th>错误</th><th>更新时间</th></tr></thead><tbody>
        <?php foreach ($migrationStatus['recent'] as $row): ?><tr><td><?= $row['source_type'] === 'sku' ? 'SKU 参考图' : ($row['source_type'] === 'detail_project' ? '详情页实拍图' : '生成素材') ?> #<?= (int) $row['source_id'] ?></td><td class="code-value"><?= escape_html($row['source_url']) ?></td><td class="code-value"><?= escape_html($row['target_url'] ?: '-') ?></td><td><span class="badge <?= escape_html($row['status']) ?>"><?= escape_html(record_status_label($row['status'])) ?></span></td><td><?= (int) $row['attempts'] ?> / 5</td><td><?= escape_html($row['error_message'] ?: '-') ?></td><td><?= escape_html($row['updated_at']) ?></td></tr><?php endforeach; ?>
        <?php if (!$migrationStatus['recent']): ?><tr><td colspan="7"><div class="empty-state"><strong>暂无迁移任务</strong><span>切换远程存储后先扫描本地素材。</span></div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
