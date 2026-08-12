<?php

declare(strict_types=1);

use app\service\StructuredOutputParser;

require dirname(__DIR__) . '/vendor/autoload.php';

$parser = new StructuredOutputParser();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$aliases = [
    'name' => ['product_name', 'productName'],
    'selling_points' => ['sellingPoints'],
];

$cases = [
    'pure_json' => ['{"name":"水杯","selling_points":["便携"]}', '水杯', 'direct'],
    'fenced_json' => ["```json\n{\"productName\":\"台灯\",\"sellingPoints\":\"护眼\"}\n```", '台灯', 'fenced_1'],
    'prose_wrapped' => ['分析如下：{"data":{"product_name":"背包","sellingPoints":["轻量"]}}，请核对。', '背包', 'balanced_1'],
    'reasoning_removed' => ['<think>{"name":"错误思考"}</think>最终：{"result":{"name":"咖啡杯","selling_points":["耐热"]}}', '咖啡杯', 'balanced_1'],
    'content_array' => [[['type' => 'text', 'text' => '{"product":{"name":"键盘",'], ['type' => 'text', 'text' => '"selling_points":["静音"]}}']], '键盘', 'direct'],
    'first_balanced_not_greedy' => ['说明 {bad} 中间 {"name":"鼠标","selling_points":["轻巧"]} 尾部 {"name":"不应命中"}', '鼠标', 'balanced_2'],
];

foreach ($cases as $label => [$input, $expectedName, $expectedStep]) {
    $result = $parser->parse($input, $aliases);
    $assert(!empty($result['ok']), $label . ' must parse successfully.');
    $assert((string) ($result['data']['name'] ?? '') === $expectedName, $label . ' returned the wrong object.');
    $assert((string) ($result['diagnostic']['parser_step'] ?? '') === $expectedStep, $label . ' did not record its parser step.');
    $assert(strlen((string) ($result['diagnostic']['response_hash'] ?? '')) === 64, $label . ' must retain a response hash without raw content.');
}

$invalid = $parser->parse('这里没有结构化对象');
$assert(empty($invalid['ok']) && ($invalid['diagnostic']['parse_attempts'] ?? -1) === 1, 'Invalid output must fail locally without invented data.');

$source = (string) file_get_contents(dirname(__DIR__) . '/app/service/NewApiClient.php');
$queue = (string) file_get_contents(dirname(__DIR__) . '/app/service/VisionTaskQueue.php');
$console = (string) file_get_contents(dirname(__DIR__) . '/public/static/console.js');
$assert(str_contains($source, "'response_format' => ['type' => 'json_object']"), 'Structured business calls must prefer JSON response format.');
$assert(str_contains($source, 'repairStructuredOutput') && str_contains($source, '不得补充、推断或编造任何字段'), 'Exactly bounded text-only JSON repair must be present.');
$assert(str_contains($source, 'product_vision_missing_core_facts') && str_contains($source, 'content_planner_missing_plan'), 'Business validation must use stable internal error codes.');
$assert(str_contains($queue, "'diagnostic_json'") && !str_contains($queue, 'terminalState'), 'Transport diagnostics must persist without a business approval state machine.');
$assert(str_contains($console, "state === 'failed'") && str_contains($console, 'Math.pow(2'), 'Polling must stop on failure and retry network errors with backoff.');

echo "Structured output extraction, recovery and terminal-state contracts completed successfully.\n";
