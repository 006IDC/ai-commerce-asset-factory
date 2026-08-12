<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
$idempotencyKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
if ($authorization !== 'Bearer sub2api-test-service-key') {
    http_response_code(401);
    echo json_encode(['error' => ['message' => 'invalid service key']]);
    return;
}
if ($path === '/v1/models' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo json_encode(['data' => [
        ['id' => 'deepseek-v4-flash'],
        ['id' => 'glm-4.6v'],
        ['id' => 'gpt-image-2'],
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}
if ($requestId === '' || $idempotencyKey !== $requestId) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'missing deterministic request identity']]);
    return;
}
if (!in_array($path, ['/v1/images/generations', '/v1/images/edits', '/v1/chat/completions'], true)) {
    http_response_code(404);
    echo json_encode(['error' => ['message' => 'mock route not found']]);
    return;
}

$payload = str_ends_with($path, '/edits')
    ? $_POST
    : json_decode((string) file_get_contents('php://input'), true);
$payload = is_array($payload) ? $payload : [];
if ($path === '/v1/chat/completions') {
    if (!in_array((string) ($payload['model'] ?? ''), ['deepseek-v4-flash', 'glm-4.6v'], true)) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'unexpected chat model']]);
        return;
    }
    header('X-Request-ID: sub2api-upstream-' . substr(hash('sha256', $requestId), 0, 12));
    echo json_encode([
        'id' => 'sub2api-chat-' . substr(hash('sha256', $requestId), 0, 12),
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => '{"safe":true,"reason":"测试通过"}'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}
if (($payload['model'] ?? '') !== 'gpt-image-2') {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'unexpected model']]);
    return;
}
if (str_ends_with($path, '/edits')
    && (!isset($_FILES['image']) || (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'missing edit image']]);
    return;
}
$receivedImageContent = isset($_FILES['image']) && is_uploaded_file((string) ($_FILES['image']['tmp_name'] ?? ''))
    ? (string) file_get_contents((string) $_FILES['image']['tmp_name'])
    : '';

header('X-Request-ID: sub2api-upstream-' . substr(hash('sha256', $requestId), 0, 12));
echo json_encode([
    'id' => 'sub2api-result-' . substr(hash('sha256', $requestId), 0, 12),
    'data' => [[
        'b64_json' => base64_encode((string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAFElEQVR4nGP4z8DAwMDAxAADCBYAOvoCBYw9H9kAAAAASUVORK5CYII=',
            true
        )),
    ]],
    'mock' => [
        'path' => $path,
        'request_id' => $requestId,
        'idempotency_key' => $idempotencyKey,
        'received_image' => isset($_FILES['image']),
        'image_sha256' => $receivedImageContent !== '' ? hash('sha256', $receivedImageContent) : '',
        'prompt' => (string) ($payload['prompt'] ?? ''),
        'payload_keys' => array_values(array_keys($payload)),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
