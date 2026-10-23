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
 *   mgr-noapp-<cmp>          a member of <cmp> whose access excludes Remote: asked
 *                            for app=remote, companyinfo answers 403
 *                            COMPANY_ACCESS_REQUIRED and the list marks the row
 *                            can_open_in_current_app=false; without app, as before
 *   mgr-evalnoapp-<cmp>      companyinfo that says nothing, and a list that marks
 *                            <cmp> not openable in Remote
 */

$path   = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query  = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$header = getallheaders();
$bearer = trim(preg_replace('/^Bearer\s+/i', '', (string) ($header['Authorization'] ?? $header['authorization'] ?? '')));

if (($log = getenv('STANDIN_LOG')) !== false && $log !== '') {
    file_put_contents($log, $_SERVER['REQUEST_METHOD'] . ' ' . $path . ' ' . ($query['comp_id'] ?? '') . ' ' . $bearer . (isset($query['app']) ? ' app=' . $query['app'] : '') . "\n", FILE_APPEND);
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

// What the caller actually sent. A request with no Accept and no User-Agent is
// what a WAF in front of the real portal answers with its own HTML page instead
// of the portal's JSON, so PortalClient has to send both.
if ($path === '/api/echo-request') {
    $seen = [];
    foreach ($header as $name => $value) {
        $seen[strtolower((string) $name)] = (string) $value;
    }
    reply(200, ['headers' => $seen]);
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

$ownedCompany = preg_match('/^mgr-(?:ok|eval|noapp|evalnoapp)-(\d+)$/', $bearer, $m) ? (int) $m[1] : 0;
$evaluated    = str_starts_with($bearer, 'mgr-ok-') || str_starts_with($bearer, 'mgr-noapp-');
// Company registry: asked for Remote, a member whose access excludes it is refused.
$askedRemote  = ($query['app'] ?? '') === 'remote';
$notForRemote = $askedRemote && (str_starts_with($bearer, 'mgr-noapp-') || str_starts_with($bearer, 'mgr-evalnoapp-'));

if ($path === '/api/companyinfo') {
    $asked = (int) ($query['comp_id'] ?? 0);
    if ($ownedCompany === 0 || $asked !== $ownedCompany) {
        reply(404, ['message' => 'not found or access denied']);
    }
    if ($notForRemote && str_starts_with($bearer, 'mgr-noapp-')) {
        reply(403, ['success' => false, 'status' => 0, 'code' => 'COMPANY_ACCESS_REQUIRED', 'message' => 'You can see this company, but you do not have access to it in Remote.',
            'error' => ['code' => 'COMPANY_ACCESS_REQUIRED', 'message' => 'Ask the company owner for access.', 'details' => ['cmp_id' => $asked, 'app' => 'remote']]]);
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
    if ($askedRemote && $rows !== []) {
        $rows[0]['app_access_status'] = $notForRemote ? 'ACCESS_RESTRICTED' : 'OWNER';
        $rows[0]['can_open_in_current_app'] = ! $notForRemote;
    }
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
