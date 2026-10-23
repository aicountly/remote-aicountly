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
 *   mgr-ws-<cmp>             a workspace member of <cmp> (not the owner) with access to every
 *                            company of the workspace: asked for app=remote, companyinfo carries
 *                            app_access_source 'workspace', app_role_key 'company_admin' and the
 *                            company's plan (CONTRACTS §1, §3.5; entitlements as a list). Manage's
 *                            company list (shares only) does not carry <cmp>; its company directory
 *                            (v1/company-directory) does, with access_via 'workspace'
 *   mgr-old-<cmp>            an older Manage without v1/company-directory (404); its list carries <cmp>
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

$ownedCompany = preg_match('/^mgr-(?:ok|eval|noapp|evalnoapp|ws|old)-(\d+)$/', $bearer, $m) ? (int) $m[1] : 0;
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
    if (str_starts_with($bearer, 'mgr-ws-')) {
        $company += ['ownership' => 'shared', 'access_type' => 0];
        if ($askedRemote) {
            $company += [
                'app_access_source' => 'workspace',
                'app_role_key'      => 'company_admin',
                'plan'              => ['code' => 'remote-basic', 'label' => 'Basic', 'is_default' => true, 'status' => 'active', 'ends_at' => null,
                    'entitlements' => [['code' => 'remote.max_sessions_per_month', 'value' => 3], ['code' => 'remote.max_devices', 'value' => 1]]],
            ];
        }
    }
    reply(200, ['success' => '1', 'data' => $company]);
}

// Manage's company directory (company registry, finding F-2): shares AND a workspace owner's "every company"
// access, each row saying whether it opens in the named app. mgr-old-<cmp> stands for a Manage that predates it.
if ($path === '/api/v1/company-directory') {
    if (str_starts_with($bearer, 'mgr-old-')) {
        reply(404, ['success' => false, 'message' => 'No such route.']);
    }
    $rows = [];
    if ($ownedCompany > 0) {
        $viaWorkspace = str_starts_with($bearer, 'mgr-ws-');
        $openable = ! $notForRemote;
        $row = ['cmp_id' => $ownedCompany, 'legal_name' => 'ABC Private Limited', 'display_name' => 'ABC Private Limited',
            'relationship' => $viaWorkspace ? 'workspace' : 'owner', 'app' => $query['app'] ?? null,
            'access_status' => $openable ? ($viaWorkspace ? 'ACCESS_GRANTED' : 'OWNER') : 'ACCESS_RESTRICTED',
            'can_open_in_current_app' => $openable, 'access_via' => $viaWorkspace ? 'workspace' : null];
        if (($query['access'] ?? 'all') !== 'granted' || $openable) {
            $rows[] = $row;
        }
    }
    reply(200, ['success' => true, 'status' => 1, 'data' => $rows, 'meta' => ['page' => 1, 'per_page' => 100, 'total' => count($rows), 'total_pages' => 1]]);
}

if ($path === '/api/companies') {
    // Manage's company list is built from share rows: workspace-wide access is not materialised there.
    $rows = $ownedCompany > 0 && ! str_starts_with($bearer, 'mgr-ws-') ? [['comp_id' => $ownedCompany, 'company_name' => 'ABC Private Limited', 'ownership' => 'owner']] : [];
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
