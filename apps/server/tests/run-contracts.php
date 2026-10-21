<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$requestedTests = array_slice($argv, 1);
$tests = $requestedTests !== [] ? $requestedTests : [
    'dependency_compatibility.php',
    'security_regressions.php',
    'remote_media_url_guard.php',
    'visible_copy_contracts.php',
    'public_landing_contracts.php',
    'admin_shell_contracts.php',
    'product_launch_code_boundaries.php',
    'product_creative_readiness.php',
    'product_vision_normalization.php',
    'product_launch_billing_conservation.php',
    'quality_appeal_refund.php',
    'content_safety_subprocess_regression.php',
    'direct_generation_architecture.php',
    'generation_language_consistency.php',
    'job_result_consistency.php',
    'workflow_template_lifecycle.php',
    'product_launch_one_flow.php',
];

$failed = [];
foreach ($tests as $test) {
    $test = basename((string) $test);
    $path = __DIR__ . DIRECTORY_SEPARATOR . $test;
    if (!is_file($path) || $test === basename(__FILE__)) {
        $failed[] = $test . ': test file does not exist';
        continue;
    }

    $stdoutFile = tempnam(sys_get_temp_dir(), 'asset-test-stdout-');
    $stderrFile = tempnam(sys_get_temp_dir(), 'asset-test-stderr-');
    if ($stdoutFile === false || $stderrFile === false) {
        throw new RuntimeException('Unable to allocate contract-test output files.');
    }

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $path],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        @unlink($stdoutFile);
        @unlink($stderrFile);
        $failed[] = $test . ': unable to start PHP process';
        continue;
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $exitCode = proc_close($process);
    $stdout = (string) file_get_contents($stdoutFile);
    $stderr = (string) file_get_contents($stderrFile);
    @unlink($stdoutFile);
    @unlink($stderrFile);
    $output = trim($stdout . ($stderr !== '' ? PHP_EOL . $stderr : ''));

    echo PHP_EOL . '=== ' . $test . ' ===' . PHP_EOL;
    if ($output !== '') {
        echo $output . PHP_EOL;
    }

    $renderedException = preg_match(
        '/(?:^|\n)\s*(?:(?:PHP )?Fatal error:|Uncaught\s+(?:Throwable|Exception|Error)|Exception trace:|\[(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*Exception)\])/',
        $output
    ) === 1;
    if ($exitCode !== 0 || $renderedException) {
        $failed[] = sprintf('%s: exit=%d rendered_exception=%s', $test, $exitCode, $renderedException ? 'yes' : 'no');
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . "Contract test suite failed:\n- " . implode("\n- ", $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . "Contract test suite completed successfully.\n";
