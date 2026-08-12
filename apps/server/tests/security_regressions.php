<?php

declare(strict_types=1);

use app\middleware\SecurityHeaders;
use app\support\AuthSession;
use think\App;
use think\Request;
use think\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertSecurityRegression(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$oldPasswordHash = password_hash('OldSessionPassword!2026', PASSWORD_DEFAULT);
$newPasswordHash = password_hash('NewSessionPassword!2026', PASSWORD_DEFAULT);
$fingerprint = AuthSession::fingerprint($oldPasswordHash);
assertSecurityRegression($fingerprint !== '', 'Authenticated session fingerprint must not be empty.');
assertSecurityRegression(AuthSession::matches($fingerprint, $oldPasswordHash), 'Current password hash must retain its session.');
assertSecurityRegression(!AuthSession::matches($fingerprint, $newPasswordHash), 'Password changes must invalidate existing sessions.');
assertSecurityRegression(!AuthSession::matches('', $oldPasswordHash), 'Legacy sessions without a fingerprint must re-authenticate.');

(new App(dirname(__DIR__)))->initialize();
$request = (new Request())->withServer([
    'HTTPS' => 'on',
    'SERVER_PORT' => '443',
    'PATH_INFO' => '/admin/login',
    'REQUEST_URI' => '/admin/login',
]);
$response = (new SecurityHeaders())->handle(
    $request,
    static fn (Request $request): Response => Response::create('ok')
);
$headers = $response->getHeader();
assertSecurityRegression(str_contains((string) ($headers['Content-Security-Policy'] ?? ''), "object-src 'none'"), 'CSP must block object embedding.');
assertSecurityRegression((string) ($headers['Strict-Transport-Security'] ?? '') === 'max-age=15552000', 'HTTPS responses must enable HSTS.');
assertSecurityRegression(str_contains((string) ($headers['Cache-Control'] ?? ''), 'no-store'), 'Authentication and workspace responses must not be cached.');

$authSource = (string) file_get_contents(dirname(__DIR__) . '/app/controller/Auth.php');
$registrationCodeMethod = strstr($authSource, 'public function sendRegistrationCode');
$registrationCodeMethod = is_string($registrationCodeMethod)
    ? strstr($registrationCodeMethod, 'public function geetestRegister', true)
    : false;
assertSecurityRegression(
    is_string($registrationCodeMethod) && !str_contains($registrationCodeMethod, '该邮箱已经注册'),
    'Registration-code responses must not reveal whether an account exists.'
);

foreach (['codex-ssh.exp', 'codex-scp.exp'] as $script) {
    assertSecurityRegression(
        !is_file(dirname(__DIR__) . '/scripts/' . $script),
        $script . ' is an internal deployment helper and must not enter the public source package.'
    );
}

$installerSource = (string) file_get_contents(dirname(__DIR__) . '/app/support/Installer.php');
$cliInstallSource = (string) file_get_contents(dirname(__DIR__) . '/app/command/Install.php');
$upgradeSource = (string) file_get_contents(dirname(__DIR__) . '/app/command/Upgrade.php');
$buildReleaseSource = (string) file_get_contents(dirname(__DIR__) . '/scripts/build-release.sh');
$reloadPosition = strpos($installerSource, '$this->reloadRuntimeConfiguration();');
$initializePosition = strpos($installerSource, '$this->initializeApplication();');
assertSecurityRegression(
    $reloadPosition !== false && $initializePosition !== false && $reloadPosition < $initializePosition,
    'Browser installation must reload its new runtime database configuration before canonical initialization.'
);
assertSecurityRegression(
    str_contains($cliInstallSource, '->initializeApplication()')
        && str_contains($upgradeSource, '->initializeApplication()'),
    'Browser, CLI installation and upgrades must share one canonical initialization sequence.'
);
assertSecurityRegression(
    str_contains($buildReleaseSource, 'Refusing a production release from a dirty Git worktree')
        && str_contains($buildReleaseSource, 'SOURCE_REVISION must be a full 40-character Git commit hash'),
    'Production releases must reject dirty or unversioned source by default.'
);

$routes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
foreach (['/', 'releases', 'login', 'forgot-password', 'register', 'admin/login'] as $headRoute) {
    assertSecurityRegression(
        str_contains($routes, "Route::head('{$headRoute}'"),
        'Public GET route must support HEAD: ' . $headRoute
    );
}

echo "Security regression tests completed successfully.\n";
