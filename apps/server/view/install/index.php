<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>系统安装 - AI 电商素材工厂</title>
    <link rel="stylesheet" href="/static/app.css?v=20260803-1">
    <link rel="stylesheet" href="/static/experience.css?v=20260731-3">
    <link rel="stylesheet" href="/static/design-tokens.css?v=20260808-1">
    <script defer src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
    <script defer src="/static/console.js?v=20260803-1"></script>
    <script defer src="/static/experience.js?v=20260731-1"></script>
</head>
<body>
<div class="auth-page install-page">
    <header class="auth-brand">
        <div class="brand-lockup"><span class="brand-mark">AI</span><div><strong>AI 电商素材工厂</strong><small>ThinkPHP 安装向导</small></div></div>
    </header>
    <main class="auth-main">
        <section class="auth-card install-card">
            <div class="install-heading"><div><div class="eyebrow">首次部署</div><h2>初始化业务系统</h2><p>检查环境、连接数据库并创建首个管理员。</p></div><span class="install-step">1 / 1</span></div>

            <?php foreach ($errors as $error): ?><div class="flash error" role="alert"><span><?= escape_html($error) ?></span></div><?php endforeach; ?>

            <section class="install-section">
                <div class="install-section-heading"><h3>环境检查</h3><span><?= $canInstall ? '全部通过' : '需要处理' ?></span></div>
                <div class="requirement-grid">
                    <?php foreach ($requirements as $requirement): ?>
                        <div class="requirement-item <?= $requirement['ok'] ? 'ok' : 'failed' ?>"><span><?= escape_html($requirement['label']) ?></span><strong><?= $requirement['ok'] ? '通过' : '未通过' ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </section>

            <form method="post" action="/install" data-install-form>
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <section class="install-section">
                    <div class="install-section-heading"><h3>数据库</h3><span>请使用空数据库</span></div>
                    <div class="form-grid">
                        <div class="form-field full"><label for="db_driver">数据库类型</label><select class="input" id="db_driver" name="db_driver" data-db-driver>
                            <option value="mysql" <?= $values['db_driver'] === 'mysql' ? 'selected' : '' ?>>MySQL 5.7+ / 8.0+</option>
                            <option value="pgsql" <?= $values['db_driver'] === 'pgsql' ? 'selected' : '' ?>>PostgreSQL 13+</option>
                            <option value="sqlite" <?= $values['db_driver'] === 'sqlite' ? 'selected' : '' ?>>SQLite（单机测试）</option>
                        </select></div>
                        <div class="form-field full sqlite-note" data-sqlite-note>SQLite 数据库将保存到 <span class="code-value">database/asset_factory.sqlite</span>。</div>
                        <div class="form-field" data-db-server-field><label for="db_host">数据库主机</label><input class="input code-value" id="db_host" name="db_host" value="<?= escape_html($values['db_host']) ?>"></div>
                        <div class="form-field" data-db-server-field><label for="db_port">端口</label><input class="input" id="db_port" name="db_port" type="number" min="1" max="65535" value="<?= escape_html($values['db_port']) ?>"></div>
                        <div class="form-field" data-db-server-field><label for="db_name">数据库名</label><input class="input code-value" id="db_name" name="db_name" value="<?= escape_html($values['db_name']) ?>"></div>
                        <div class="form-field" data-db-server-field><label for="db_user">数据库用户</label><input class="input code-value" id="db_user" name="db_user" value="<?= escape_html($values['db_user']) ?>" autocomplete="username"></div>
                        <div class="form-field full" data-db-server-field><label for="db_password">数据库密码</label><input class="input" id="db_password" name="db_password" type="password" autocomplete="new-password"></div>
                    </div>
                </section>

                <section class="install-section">
                    <div class="install-section-heading"><h3>管理员账户</h3><span>安装后可在后台修改</span></div>
                    <div class="form-grid">
                        <div class="form-field"><label for="admin_name">管理员名称</label><input class="input" id="admin_name" name="admin_name" value="<?= escape_html($values['admin_name']) ?>" maxlength="80" required></div>
                        <div class="form-field"><label for="admin_email">登录邮箱</label><input class="input" id="admin_email" name="admin_email" type="email" value="<?= escape_html($values['admin_email']) ?>" autocomplete="username" required></div>
                        <div class="form-field"><label for="admin_password">管理员密码</label><input class="input" id="admin_password" name="admin_password" type="password" minlength="12" autocomplete="new-password" required><small>至少 12 位，同时包含字母和数字。</small></div>
                        <div class="form-field"><label for="admin_password_confirmation">确认密码</label><input class="input" id="admin_password_confirmation" name="admin_password_confirmation" type="password" minlength="12" autocomplete="new-password" required></div>
                    </div>
                </section>

                <div class="form-footer"><button class="button primary" type="submit" <?= $canInstall ? '' : 'disabled' ?>>开始安装</button></div>
            </form>
        </section>
    </main>
</div>
<script>
(function () {
    var driver = document.querySelector('[data-db-driver]');
    var serverFields = document.querySelectorAll('[data-db-server-field]');
    var sqliteNote = document.querySelector('[data-sqlite-note]');
    if (!driver) return;
    function updateDatabaseFields() {
        var sqlite = driver.value === 'sqlite';
        serverFields.forEach(function (field) { field.hidden = sqlite; });
        sqliteNote.hidden = !sqlite;
        var port = document.getElementById('db_port');
        if (!sqlite && (port.value === '3306' || port.value === '5432' || port.value === '')) {
            port.value = driver.value === 'pgsql' ? '5432' : '3306';
        }
    }
    driver.addEventListener('change', updateDatabaseFields);
    updateDatabaseFields();
})();
</script>
</body>
</html>
