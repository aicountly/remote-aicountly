<?php

/**
 * Contract stand-in for the two AICOUNTLY services Remote asks about a person:
 * the portal (`validatesession`, `userprofile`) and Aicountly Manage
 * (`companyinfo`, `companies`, `companies/{id}/share`).
 *
 * Run by ContractTestCase with `php -S`, so Remote's real clients talk real
 * HTTP to it. The shapes are the producers' (my.aicountly.com and
 * manage-aicountly), reduced to what Remote reads. Each request is appended to
 * the file named by STANDIN_LOG so a test can check *which key* asked what.
 *
 * The bearer picks the behaviour:
 *
 *   live:<uuid>[:noprofile]  a live portal session for <uuid>
 *   dead                     the portal's 401 {"status":0}
 *   boom                     a 503 from either service
 *   html                     a 200 that is not JSON (a maintenance page)
 *   mgr-ok-<cmp>             a Manage session that owns company <cmp>
 *   mgr-eval-<cmp>           companyinfo that does not say how the session relates
 *   mgr-none                 a Manage session with no companies
 */

$path   = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query  = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$header = getallheaders();
$bearer = trim(preg_replace('/^Bearer\s+/i', '', (string) ($header['Authorization'] ?? $header['authorization'] ?? '')));

if (($log = getenv('STANDIN_LOG')) !== false && $log !== '') {
    file_put_contents($log, $_SERVER['REQUEST_METHOD'] . ' ' . $path . ' ' . ($query['comp_id'] ?? '') . ' ' . $bearer . "\n", FILE_APPEND);
}

function reply(int $status, $body, ?string $contentType = null): void
{
    http_response_code($status);
    header('Content-Type: ' . ($contentType ?? 'application/json'));
    echo is_string($body) ? $body : json_encode($body);
    exit;
}

if ($bearer === 'boom') {
    reply(503, ['message' => 'Service Unavailable']);
}
if ($bearer === 'html') {
    reply(200, '<html><body>Down for maintenance</body></html>', 'text/html');
}

// ------------------------------------------------------------------- portal

if ($path === '/api/validatesession') {
    if (str_starts_with($bearer, 'live:')) {
        $uuid = explode(':', $bearer)[1];
        reply(200, ['status' => 1, 'uuid_aictly' => $uuid, 'ses_key' => $bearer]);
    }
    reply(401, ['status' => 0]);
}

if ($path === '/api/userprofile') {
    $parts = explode(':', $bearer);
    if (($parts[0] ?? '') === 'live' && ($parts[2] ?? '') !== 'noprofile') {
        reply(200, ['status' => 1, 'data' => ['display_name' => 'Priya Nair', 'email' => 'priya.nair@example.test']]);
    }
    reply(($parts[0] ?? '') === 'live' ? 404 : 401, ['status' => 0]);
}

// ------------------------------------------------------------------- manage

$ownedCompany = preg_match('/^mgr-(?:ok|eval)-(\d+)$/', $bearer, $m) ? (int) $m[1] : 0;
$evaluated    = str_starts_with($bearer, 'mgr-ok-');

if ($path === '/api/companyinfo') {
    $asked = (int) ($query['comp_id'] ?? 0);
    if ($ownedCompany === 0 || $asked !== $ownedCompany) {
        reply(404, ['message' => 'not found or access denied']);
    }
    $company = ['comp_id' => $asked, 'comp_name' => 'ABC Private Limited'];
    if ($evaluated) {
        $company['ownership'] = 'owner';
        $company['access_type'] = 1;
    }
    reply(200, ['success' => '1', 'data' => $company]);
}

if ($path === '/api/companies') {
    $rows = $ownedCompany > 0 ? [['comp_id' => $ownedCompany, 'company_name' => 'ABC Private Limited', 'ownership' => 'owner']] : [];
    reply(200, ['success' => '1', 'data' => $rows, 'total' => count($rows)]);
}

if (preg_match('#^/api/companies/(\d+)/share$#', $path, $m)) {
    if ($ownedCompany === 0 || (int) $m[1] !== $ownedCompany) {
        reply(403, ['message' => 'Forbidden']);
    }
    reply(200, ['success' => '1', 'data' => [
        ['uuid' => 'uuid-asha', 'display_name' => 'Asha Verma', 'email' => 'asha.verma@example.test', 'is_owner' => true],
        ['uuid' => 'uuid-ravi', 'display_name' => 'Ravi Menon', 'email' => 'ravi.menon@example.test', 'is_owner' => false],
    ]]);
}

reply(404, ['message' => 'unknown path ' . $path]);
