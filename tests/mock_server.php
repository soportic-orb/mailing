<?php
/*
 * Local stand-in for Mailjet, Anthropic, OpenAI and GitHub, used only by
 * tests/smoke.sh (php -S 127.0.0.1:8099 tests/mock_server.php).
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
file_put_contents(__DIR__ . '/../storage/logs/mock.log', $_SERVER['REQUEST_METHOD'] . ' ' . $path . ' ' . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');
switch (true) {
    case $path === '/v3.1/send':
        $out = [];
        foreach ($body['Messages'] ?? [] as $i => $m) {
            $email = $m['To'][0]['Email'] ?? '';
            $out[] = str_contains($email, 'reject')
                ? ['Status' => 'error', 'Errors' => [['ErrorMessage' => 'Recipient rejected', 'ErrorRelatedTo' => ['To']]]]
                : ['Status' => 'success', 'To' => [['Email' => $email, 'MessageID' => 900000 + random_int(1, 99999)]]];
        }
        echo json_encode(['Messages' => $out]);
        break;
    case $path === '/v3/REST/sender' && $_SERVER['REQUEST_METHOD'] === 'GET':
        echo json_encode(['Data' => [['ID' => 1, 'Email' => 'info@emergencing.com', 'Name' => 'Emergencing', 'Status' => 'Active'], ['ID' => 2, 'Email' => '*@sysrevai.com', 'Name' => '', 'Status' => 'Active']]]);
        break;
    case $path === '/v3/REST/sender':
        echo json_encode(['Data' => [['ID' => 3]]]);
        break;
    case str_starts_with($path, '/v3/REST/sender/'):
        echo '{}';
        break;
    case $path === '/v3/REST/dns':
        echo json_encode(['Data' => [['Domain' => 'emergencing.com', 'SPFStatus' => 'OK', 'DKIMStatus' => 'OK']]]);
        break;
    case $path === '/v3/REST/apikey':
        echo json_encode(['Data' => [['Name' => 'test']]]);
        break;
    case str_starts_with($path, '/v3/REST/eventcallbackurl'):
        echo json_encode(['Data' => []]);
        break;
    case $path === '/anthropic':
        $prompt = $body['messages'][0]['content'] ?? '';
        $decoded = json_decode($prompt, true);
        if (is_array($decoded)) {
            // "Translation": prefix every value, keep tags and merge fields.
            $text = json_encode(array_map(static fn ($v) => '[ES] ' . $v, $decoded), JSON_UNESCAPED_UNICODE);
        } else {
            $text = str_contains($body['system'] ?? '', 'un sol títol') ? 'Correcte' : '<p>Text generat per la IA de prova.</p>';
        }
        echo json_encode(['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn']);
        break;
    case $path === '/openai/images/generations':
        $im = imagecreatetruecolor(64, 40);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 90));
        ob_start();
        imagejpeg($im);
        echo json_encode(['data' => [['b64_json' => base64_encode((string) ob_get_clean())]]]);
        break;
    case str_ends_with($path, '/version.txt'):
        header('Content-Type: text/plain');
        echo trim((string) @file_get_contents(__DIR__ . '/../storage/cache/mock-version.txt')) ?: '0.0.1';
        break;
    case str_contains($path, '/zipball/'):
        header('Content-Type: application/zip');
        readfile(__DIR__ . '/../storage/cache/mock-update.zip');
        break;
    default:
        http_response_code(404);
        echo '{"ErrorMessage":"not found"}';
}
