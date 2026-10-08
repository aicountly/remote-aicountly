<?php

declare(strict_types=1);

/**
 * A stand-in for Console's GET /api/database-details/resolve, run by
 * `php -S` from the Console database-details tests (never in production).
 *
 * The test controls it through a JSON file named by REMOTE_CONSOLE_STUB_STATE:
 *   expect_key   the one bearer key that is accepted (anything else -> 401)
 *   status       force this HTTP status (with an error body)
 *   data         fields of the row, merged over a valid default
 *   raw          answer with exactly this body (200) instead of JSON
 *   pad          answer with this many bytes of padding in the body (200)
 *   redirect     answer 302 to this URL
 *   delay        sleep this many seconds before answering
 * and reads what arrived from the JSON-lines file REMOTE_CONSOLE_STUB_LOG.
 */

$statePath = (string) getenv('REMOTE_CONSOLE_STUB_STATE');
$logPath   = (string) getenv('REMOTE_CONSOLE_STUB_LOG');
$control   = is_file($statePath) ? (json_decode((string) file_get_contents($statePath), true) ?: []) : [];

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($logPath !== '') {
    file_put_contents($logPath, json_encode([
        'method'  => $_SERVER['REQUEST_METHOD'],
        'path'    => $path,
        'query'   => (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY),
        'headers' => $headers,
    ]) . "\n", FILE_APPEND | LOCK_EX);
}

function stub_out(int $status, string $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo $body;

    exit;
}

if (isset($control['delay'])) {
    sleep((int) $control['delay']);
}

if ($path !== '/api/database-details/resolve') {
    stub_out(404, '{"success":false,"message":"not found"}');
}
if (isset($control['redirect'])) {
    header('Location: ' . $control['redirect']);
    stub_out(302, '');
}
if (($headers['authorization'] ?? '') !== 'Bearer ' . ($control['expect_key'] ?? 'unset')) {
    stub_out(401, '{"success":false,"message":"Invalid or revoked database-details key."}');
}
if (isset($control['status'])) {
    stub_out((int) $control['status'], '{"success":false,"message":"forced"}');
}
if (isset($control['raw'])) {
    stub_out(200, (string) $control['raw']);
}
if (isset($control['pad'])) {
    stub_out(200, str_repeat(' ', (int) $control['pad']) . '{"success":true,"data":{}}');
}

stub_out(200, (string) json_encode([
    'success' => true,
    'data'    => ((array) ($control['data'] ?? [])) + [
        'product_slug'      => 'remote',
        'product_name'      => 'Remote',
        'environment'       => 'production',
        'database_name'     => 'cp_remote_db',
        'database_username' => 'cp_remote_user',
        'cpanel_username'   => 'cp',
    ],
]));
