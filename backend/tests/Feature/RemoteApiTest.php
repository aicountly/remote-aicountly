<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\PermissionCatalog;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FakeIdentityResolver;
use Tests\Support\RemoteTestCase;

/**
 * The API over HTTP.
 *
 * These go through the real router, the real filters and the real controllers —
 * the whole stack except the portal round trip, which {@see FakeIdentityResolver}
 * stands in for. That makes them the tests that would catch a route wired to
 * the wrong filter, or a controller that forgot to check a permission.
 *
 * @internal
 */
final class RemoteApiTest extends RemoteTestCase
{
    use FeatureTestTrait;

    private FakeIdentityResolver $identities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identities = new FakeIdentityResolver(Services::portalClient(), $this->db);
        Services::injectMock('identityResolver', $this->identities);
    }

    /** @return array<string, string> */
    private function asUser(RemoteIdentity $identity, string $sesKey = 'test-ses-key'): array
    {
        $this->identities->register($sesKey, $identity);

        return ['Authorization' => 'Bearer ' . $sesKey];
    }

    // ------------------------------------------------------------ health

    public function testHealthIsPublicAndNamesTheEnvironment(): void
    {
        $result = $this->get('health');

        $result->assertStatus(200);
        $result->assertJSONFragment(['app' => 'AICOUNTLY Remote', 'database' => 'ok']);
    }

    public function testHealthNeverLeaksASecret(): void
    {
        $body = $this->get('health')->getJSON();

        // It reports *that* the deployment is configured, never *what with*.
        $this->assertStringNotContainsString('test-signalling-secret', (string) $body);
        $this->assertStringNotContainsString('test-context-secret', (string) $body);
    }

    // ---------------------------------------------------- authentication

    public function testTheApiRefusesAnUnauthenticatedCall(): void
    {
        $result = $this->get('v1/remote/bootstrap');

        $result->assertStatus(401);
        $result->assertJSONFragment(['error' => ['code' => 'UNAUTHENTICATED']]);
    }

    public function testAnUnknownSesKeyIsRefused(): void
    {
        $result = $this->withHeaders(['Authorization' => 'Bearer nonsense'])->get('v1/remote/bootstrap');

        $result->assertStatus(401);
    }

    public function testEveryResponseCarriesSecurityHeaders(): void
    {
        $result = $this->get('health');

        $result->assertHeader('X-Content-Type-Options', 'nosniff');
        $result->assertHeader('X-Frame-Options', 'DENY');
        $result->assertHeader('Cache-Control', 'no-store, private');
    }

    public function testARefusedResponseCarriesSecurityHeadersToo(): void
    {
        // A response returned from a `before` filter skips the `after` chain,
        // so without an explicit stamp the two most common error responses in
        // the API would be the only ones without security headers.
        $result = $this->get('v1/remote/bootstrap');

        $result->assertStatus(401);
        $result->assertHeader('X-Content-Type-Options', 'nosniff');
        $result->assertHeader('X-Frame-Options', 'DENY');
        $result->assertHeader('Cache-Control', 'no-store, private');
    }

    // ---------------------------------------------------------- bootstrap

    public function testBootstrapReturnsPolicyAndCompanies(): void
    {
        $identity = $this->makeIdentity('Rahul Gupta');
        $company  = $this->makeCompany(481, 'ABC Private Limited');
        $this->grantCompanyAccess($identity, $company, 'COMPANY_ADMIN', true);

        $result = $this->withHeaders($this->asUser($identity))->get('v1/remote/bootstrap');

        $result->assertStatus(200);

        $body = json_decode($result->getJSON(), true)['data'];

        $this->assertSame('Rahul Gupta', $body['user']['displayName']);
        $this->assertSame('PERSONAL', $body['activeScope']['scopeType']);
        $this->assertCount(1, $body['companies']);
        $this->assertSame('ABC Private Limited', $body['companies'][0]['name']);
        $this->assertTrue($body['policy']['permissions'][PermissionCatalog::SESSION_CREATE]);
        $this->assertArrayHasKey('signallingConfigured', $body['realtime']);
    }

    public function testEffectivePolicyIsRefusedForACompanyTheUserIsNotIn(): void
    {
        $identity = $this->makeIdentity();
        $this->makeCompany(902, 'XYZ Enterprises');

        $result = $this->withHeaders($this->asUser($identity))
            ->get('v1/remote/policy/effective?scopeType=COMPANY&companyId=902');

        $result->assertStatus(403);
        $this->assertSame('COMPANY_ACCESS_DENIED', json_decode($result->getJSON(), true)['error']['code']);
    }

    // ----------------------------------------------------------- sessions

    public function testCreatingAndReadingASession(): void
    {
        $identity = $this->makeIdentity('Rahul Gupta');
        $headers  = $this->asUser($identity);

        $created = $this->withHeaders($headers)->withBodyFormat('json')->post('v1/remote/sessions', [
            'scopeType'          => 'PERSONAL',
            'requestedShareMode' => 'SAFE_SHARE',
        ]);

        $created->assertStatus(201);

        $session = json_decode($created->getJSON(), true)['data'];

        $this->assertMatchesRegularExpression('/^AR-\d+$/', $session['displayId']);
        // The code is presented grouped for reading aloud (§6E).
        $this->assertMatchesRegularExpression('/^\d{3} \d{3} \d{3}$/', $session['sessionCode']);
        $this->assertTrue($session['isHost']);
        $this->assertCount(1, $session['participants']);

        // Never a serial id in the API surface (§26).
        $this->assertArrayNotHasKey('id', $session);

        $read = $this->withHeaders($headers)->get('v1/remote/sessions/' . $session['uuid']);
        $read->assertStatus(200);
        $this->assertSame($session['uuid'], json_decode($read->getJSON(), true)['data']['uuid']);
    }

    public function testAnotherUserCannotReadTheSession(): void
    {
        $owner    = $this->makeIdentity('Owner');
        $stranger = $this->makeIdentity('Stranger');

        $session = $this->makeSession($owner);

        $result = $this->withHeaders($this->asUser($stranger, 'stranger-key'))
            ->get('v1/remote/sessions/' . $session['uuid']);

        // 404 rather than 403, so session ids cannot be probed (§29).
        $result->assertStatus(404);
        $this->assertSame('NOT_FOUND', json_decode($result->getJSON(), true)['error']['code']);
    }

    public function testShareIntentIsRefusedForAForbiddenMode(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', ['allow_entire_monitor' => false]);
        $this->grantCompanyAccess($identity, $company, 'COMPANY_ADMIN', true);

        $session = $this->makeSession($identity, 'COMPANY', $company);

        $result = $this->withHeaders($this->asUser($identity))
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session['uuid']}/share-intent", ['shareMode' => 'ENTIRE_MONITOR']);

        $result->assertStatus(403);

        $error = json_decode($result->getJSON(), true)['error'];
        $this->assertSame('SHARE_MODE_NOT_ALLOWED', $error['code']);
        // The details tell the UI what to offer instead (§39).
        $this->assertContains('SAFE_SHARE', $error['details']['allowed']);
    }

    public function testTheFullShareFlow(): void
    {
        $identity = $this->makeIdentity();
        $headers  = $this->asUser($identity);
        $session  = $this->makeSession($identity);

        $intent = $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session['uuid']}/share-intent", ['shareMode' => 'SAFE_SHARE']);
        $intent->assertStatus(200);

        $started = $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session['uuid']}/share-started", ['displaySurface' => 'browser']);

        $started->assertStatus(200);
        $body = json_decode($started->getJSON(), true)['data'];

        $this->assertSame('ACTIVE', $body['status']);
        $this->assertSame('browser', $body['actualDisplaySurface']);

        $stopped = $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session['uuid']}/share-stopped", ['reason' => 'USER_STOPPED']);

        $stopped->assertStatus(200);
        // Stopping the share must not end the session (§86).
        $this->assertSame('ACTIVE', json_decode($stopped->getJSON(), true)['data']['status']);
    }

    public function testAForbiddenSurfaceIsRefusedByTheServerEvenAfterIntent(): void
    {
        // The client-side check can be skipped; this one cannot.
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', ['allow_entire_monitor' => false]);
        $this->grantCompanyAccess($identity, $company);

        $session = $this->makeSession($identity, 'COMPANY', $company);

        $result = $this->withHeaders($this->asUser($identity))
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session['uuid']}/share-started", ['displaySurface' => 'monitor']);

        $result->assertStatus(403);
        $this->assertSame('SURFACE_NOT_ALLOWED', json_decode($result->getJSON(), true)['error']['code']);
    }

    // ------------------------------------------------------- join and admit

    public function testJoinApproveAndSignallingToken(): void
    {
        $host   = $this->makeIdentity('Host');
        $viewer = $this->makeIdentity('Viewer');

        $hostHeaders   = $this->asUser($host, 'host-key');
        $viewerHeaders = $this->asUser($viewer, 'viewer-key');

        $session = $this->makeSession($host);
        $code    = str_replace(' ', '', (string) $session['session_code']);

        $joined = $this->withHeaders($viewerHeaders)
            ->withBodyFormat('json')
            ->post('v1/remote/join/code', ['code' => $code]);

        $joined->assertStatus(201);
        $participant = json_decode($joined->getJSON(), true)['data']['participant'];
        $this->assertSame('REQUESTED', $participant['status']);

        // Before approval there is no token, so there is no room and no offer.
        $refused = $this->withHeaders($viewerHeaders)
            ->post("v1/remote/sessions/{$session['uuid']}/signalling-token");
        $refused->assertStatus(403);
        $this->assertSame('AWAITING_APPROVAL', json_decode($refused->getJSON(), true)['error']['code']);

        // A viewer cannot approve themselves.
        $selfApprove = $this->withHeaders($viewerHeaders)
            ->post("v1/remote/sessions/{$session['uuid']}/participants/{$participant['uuid']}/approve");
        $selfApprove->assertStatus(403);

        $approved = $this->withHeaders($hostHeaders)
            ->post("v1/remote/sessions/{$session['uuid']}/participants/{$participant['uuid']}/approve");
        $approved->assertStatus(200);
        $this->assertSame('APPROVED', json_decode($approved->getJSON(), true)['data']['status']);

        $token = $this->withHeaders($viewerHeaders)
            ->post("v1/remote/sessions/{$session['uuid']}/signalling-token");

        $token->assertStatus(200);
        $credentials = json_decode($token->getJSON(), true)['data'];

        $this->assertSame($session['uuid'], $credentials['room']);
        $this->assertNotEmpty($credentials['token']);
        $this->assertArrayHasKey('iceServers', $credentials);
    }

    public function testInvitationCreationReturnsTheSecretExactlyOnce(): void
    {
        $host    = $this->makeIdentity('Host');
        $headers = $this->asUser($host);
        $session = $this->makeSession($host);

        $created = $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session['uuid']}/invitations", ['invitationType' => 'INTERNAL']);

        $created->assertStatus(201);
        $body = json_decode($created->getJSON(), true)['data'];

        $this->assertNotEmpty($body['url']);
        $secret = substr($body['url'], strrpos($body['url'], '/') + 1);

        // Reading the invitations back must never return it again.
        $listed = $this->withHeaders($headers)->get("v1/remote/sessions/{$session['uuid']}/invitations");
        $listed->assertStatus(200);

        $this->assertStringNotContainsString($secret, $listed->getJSON());
    }

    // ------------------------------------------------------------- chat

    public function testChatRequiresBeingAParticipant(): void
    {
        $host      = $this->makeIdentity('Host');
        $bystander = $this->makeIdentity('Bystander');
        $company   = $this->makeCompany(481, 'ABC');

        // The bystander can see the session through company-wide history…
        $this->grantCompanyAccess($host, $company);
        $this->grantCompanyAccess($bystander, $company, 'COMPANY_ADMIN', true);

        $session = $this->makeSession($host, 'COMPANY', $company);

        // …but reading the record is not the same as being in the room.
        $result = $this->withHeaders($this->asUser($bystander, 'bystander-key'))
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session['uuid']}/messages", ['body' => 'hello']);

        $result->assertStatus(403);
        $this->assertSame('NOT_A_PARTICIPANT', json_decode($result->getJSON(), true)['error']['code']);
    }

    public function testAParticipantCanPostAndReadChat(): void
    {
        $host    = $this->makeIdentity('Host');
        $headers = $this->asUser($host);
        $session = $this->makeSession($host);

        $posted = $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session['uuid']}/messages", ['body' => 'Please open the GST page.']);

        $posted->assertStatus(201);

        $listed = $this->withHeaders($headers)->get("v1/remote/sessions/{$session['uuid']}/messages");
        $listed->assertStatus(200);

        $messages = json_decode($listed->getJSON(), true)['data'];
        $this->assertCount(1, $messages);
        $this->assertSame('Please open the GST page.', $messages[0]['body']);
    }

    // --------------------------------------------------- file transfer

    /**
     * A company session with a host and an admitted viewer, both able to make
     * HTTP calls — the shape every transfer test below needs.
     *
     * @param  array<string, bool> $policy overrides on the company policy
     * @return array{session: array<string, mixed>, host: array<string,string>, viewer: array<string,string>, viewerUuid: string, hostIdentity: RemoteIdentity}
     */
    private function sessionWithAdmittedViewer(array $policy = []): array
    {
        $host   = $this->makeIdentity('Host');
        $viewer = $this->makeIdentity('Viewer');

        $company = $this->makeCompany(481, 'ABC', array_merge(['allow_file_transfer' => true], $policy));
        $this->grantCompanyAccess($host, $company);
        $this->grantCompanyAccess($viewer, $company);
        $this->setEntitlement($company, ['file_transfer' => true]);

        $hostHeaders   = $this->asUser($host, 'host-key');
        $viewerHeaders = $this->asUser($viewer, 'viewer-key');

        $session = $this->makeSession($host, 'COMPANY', $company);
        $code    = str_replace(' ', '', (string) $session['session_code']);

        $joined = $this->withHeaders($viewerHeaders)
            ->withBodyFormat('json')
            ->post('v1/remote/join/code', ['code' => $code]);
        $joined->assertStatus(201);

        $viewerUuid = json_decode($joined->getJSON(), true)['data']['participant']['uuid'];

        $this->withHeaders($hostHeaders)
            ->post("v1/remote/sessions/{$session['uuid']}/participants/{$viewerUuid}/approve")
            ->assertStatus(200);

        return [
            'session'      => $session,
            'host'         => $hostHeaders,
            'viewer'       => $viewerHeaders,
            'viewerUuid'   => $viewerUuid,
            'hostIdentity' => $host,
        ];
    }

    public function testTheFileTransferFlowOverHttp(): void
    {
        $ctx  = $this->sessionWithAdmittedViewer();
        $uuid = $ctx['session']['uuid'];

        $offered = $this->withHeaders($ctx['host'])
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$uuid}/transfers", [
                'toParticipantUuid' => $ctx['viewerUuid'],
                'fileName'          => 'trial-balance.pdf',
                'fileSize'          => 4096,
                'mimeType'          => 'application/pdf',
            ]);

        $offered->assertStatus(201);
        $transfer = json_decode($offered->getJSON(), true)['data'];

        $this->assertSame('OFFERED', $transfer['status']);
        $this->assertSame(0, $transfer['bytesTransferred']);

        // The recipient's decision is the gate: nothing moves before it.
        $this->withHeaders($ctx['viewer'])
            ->post("v1/remote/sessions/{$uuid}/transfers/{$transfer['uuid']}/accept")
            ->assertStatus(200);

        $progressed = $this->withHeaders($ctx['host'])
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$uuid}/transfers/{$transfer['uuid']}/progress", [
                'bytesTransferred' => 2048,
            ]);

        $progressed->assertStatus(200);
        $running = json_decode($progressed->getJSON(), true)['data'];

        $this->assertSame('IN_PROGRESS', $running['status']);
        $this->assertSame(50, $running['progress']);

        // Completion is the recipient's word — the only side that knows.
        $completed = $this->withHeaders($ctx['viewer'])
            ->post("v1/remote/sessions/{$uuid}/transfers/{$transfer['uuid']}/complete");

        $completed->assertStatus(200);
        $this->assertSame('COMPLETED', json_decode($completed->getJSON(), true)['data']['status']);

        $listed = $this->withHeaders($ctx['viewer'])->get("v1/remote/sessions/{$uuid}/transfers");
        $listed->assertStatus(200);

        $rows = json_decode($listed->getJSON(), true)['data'];
        $this->assertCount(1, $rows);
        $this->assertSame('trial-balance.pdf', $rows[0]['fileName']);

        // The ledger records the file, never its contents. Nothing in the
        // resource is a byte of the document.
        $this->assertArrayNotHasKey('content', $rows[0]);
        $this->assertArrayNotHasKey('url', $rows[0]);

        $this->assertHasAudit(EventType::FILE_TRANSFER_OFFERED);
        $this->assertHasAudit(EventType::FILE_TRANSFER_COMPLETED);
    }

    public function testAFileOfferIsRefusedWhenTheCompanyForbidsIt(): void
    {
        // The company switch is not advisory, and the frontend hiding the
        // button is not what enforces it.
        $ctx = $this->sessionWithAdmittedViewer(['allow_file_transfer' => false]);

        $result = $this->withHeaders($ctx['host'])
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$ctx['session']['uuid']}/transfers", [
                'toParticipantUuid' => $ctx['viewerUuid'],
                'fileName'          => 'payroll.csv',
                'fileSize'          => 128,
            ]);

        $result->assertStatus(403);
        $this->assertSame('FILE_TRANSFER_DISABLED', json_decode($result->getJSON(), true)['error']['code']);
    }

    public function testAUserDenyOverridesTheCompanyPermissionToSend(): void
    {
        $ctx = $this->sessionWithAdmittedViewer();

        // The organisation permits file transfer; this one person does not get
        // to send. A user rule is applied on top of the company's, and a DENY
        // is the narrower of the two.
        $this->setUserPermission($ctx['hostIdentity'], 481, PermissionCatalog::FILE_SEND, 'DENY');

        $result = $this->withHeaders($ctx['host'])
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$ctx['session']['uuid']}/transfers", [
                'toParticipantUuid' => $ctx['viewerUuid'],
                'fileName'          => 'notes.txt',
                'fileSize'          => 64,
            ]);

        $result->assertStatus(403);
        $this->assertSame('FILE_SEND_DENIED', json_decode($result->getJSON(), true)['error']['code']);
    }

    public function testOnlyTheRecipientCanAnswerForAFile(): void
    {
        $ctx  = $this->sessionWithAdmittedViewer();
        $uuid = $ctx['session']['uuid'];

        $offered = $this->withHeaders($ctx['host'])
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$uuid}/transfers", [
                'toParticipantUuid' => $ctx['viewerUuid'],
                'fileName'          => 'ledger.xlsx',
                'fileSize'          => 512,
            ]);

        $transferUuid = json_decode($offered->getJSON(), true)['data']['uuid'];

        // The sender accepting their own offer would make consent meaningless.
        $result = $this->withHeaders($ctx['host'])
            ->post("v1/remote/sessions/{$uuid}/transfers/{$transferUuid}/accept");

        $result->assertStatus(403);
        $this->assertSame('NOT_FILE_RECIPIENT', json_decode($result->getJSON(), true)['error']['code']);
    }

    public function testNothingMovesBeforeTheRecipientAccepts(): void
    {
        $ctx  = $this->sessionWithAdmittedViewer();
        $uuid = $ctx['session']['uuid'];

        $offered = $this->withHeaders($ctx['host'])
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$uuid}/transfers", [
                'toParticipantUuid' => $ctx['viewerUuid'],
                'fileName'          => 'ledger.xlsx',
                'fileSize'          => 512,
            ]);

        $transferUuid = json_decode($offered->getJSON(), true)['data']['uuid'];

        $result = $this->withHeaders($ctx['host'])
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$uuid}/transfers/{$transferUuid}/progress", ['bytesTransferred' => 512]);

        $result->assertStatus(409);
        $this->assertSame('FILE_TRANSFER_NOT_ACTIVE', json_decode($result->getJSON(), true)['error']['code']);
    }

    public function testSomeoneOutsideTheSessionCannotSeeItsTransfers(): void
    {
        $ctx       = $this->sessionWithAdmittedViewer();
        $bystander = $this->makeIdentity('Bystander');

        // Company-wide history makes the session readable; it does not make
        // this person a party to what is being passed inside it.
        $this->grantCompanyAccess($bystander, 481, 'COMPANY_ADMIN', true);

        $result = $this->withHeaders($this->asUser($bystander, 'bystander-key'))
            ->get("v1/remote/sessions/{$ctx['session']['uuid']}/transfers");

        $result->assertStatus(403);
        $this->assertSame('NOT_A_PARTICIPANT', json_decode($result->getJSON(), true)['error']['code']);
    }

    // ------------------------------------------------------ administration

    public function testAnOrdinaryMemberCannotChangeCompanyPolicy(): void
    {
        $member  = $this->makeIdentity('Member');
        $company = $this->makeCompany(481, 'ABC');
        $this->grantCompanyAccess($member, $company);

        $result = $this->withHeaders($this->asUser($member))
            ->withBodyFormat('json')
            ->put("v1/remote/company/{$company}/policy", ['allowEntireMonitor' => true]);

        $result->assertStatus(403);
        $this->assertSame('ADMIN_PERMISSION_DENIED', json_decode($result->getJSON(), true)['error']['code']);

        $stored = Services::policyResolver()->companyPolicy($company);
        $this->assertFalse($stored['allow_entire_monitor'], 'The policy must be unchanged.');
    }

    public function testAnAdministratorCanChangePolicyAndThePresetFollows(): void
    {
        $admin   = $this->makeIdentity('Admin');
        $company = $this->makeCompany(481, 'ABC');
        $this->grantCompanyAccess($admin, $company, 'COMPANY_ADMIN', true);

        $headers = $this->asUser($admin);

        $before = $this->withHeaders($headers)->get("v1/remote/company/{$company}/policy");
        $before->assertStatus(200);
        $this->assertSame('STANDARD', json_decode($before->getJSON(), true)['data']['policy']['policyPreset']);

        $result = $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->put("v1/remote/company/{$company}/policy", ['allowEntireMonitor' => true]);

        $result->assertStatus(200);
        $policy = json_decode($result->getJSON(), true)['data']['policy'];

        $this->assertTrue($policy['allowEntireMonitor']);
        // One switch away from STANDARD is CUSTOM, and the label says so (§40).
        $this->assertSame('CUSTOM', $policy['policyPreset']);

        $this->assertHasAudit('POLICY_UPDATED');
    }

    public function testApplyingTheRestrictedPresetDisablesEverything(): void
    {
        $admin   = $this->makeIdentity('Admin');
        $company = $this->makeCompany(481, 'ABC');
        $this->grantCompanyAccess($admin, $company, 'COMPANY_ADMIN', true);

        $result = $this->withHeaders($this->asUser($admin))
            ->withBodyFormat('json')
            ->put("v1/remote/company/{$company}/policy", ['preset' => 'RESTRICTED']);

        $result->assertStatus(200);
        $policy = json_decode($result->getJSON(), true)['data']['policy'];

        $this->assertFalse($policy['remoteEnabled']);
        $this->assertFalse($policy['allowSafeShare']);
        $this->assertSame('RESTRICTED', $policy['policyPreset']);
    }

    public function testAuditIsRefusedWithoutThePermission(): void
    {
        $member  = $this->makeIdentity('Member');
        $company = $this->makeCompany(481, 'ABC');
        $this->grantCompanyAccess($member, $company);

        $result = $this->withHeaders($this->asUser($member))->get("v1/remote/company/{$company}/audit");

        $result->assertStatus(403);
    }

    public function testAnAdministratorSeesTheAuditTrail(): void
    {
        $admin   = $this->makeIdentity('Admin');
        $company = $this->makeCompany(481, 'ABC');
        $this->grantCompanyAccess($admin, $company, 'COMPANY_ADMIN', true);

        $this->makeSession($admin, 'COMPANY', $company);

        $result = $this->withHeaders($this->asUser($admin))->get("v1/remote/company/{$company}/audit");

        $result->assertStatus(200);
        $entries = json_decode($result->getJSON(), true)['data'];

        $this->assertNotEmpty($entries);
        $this->assertSame('SESSION_CREATED', $entries[0]['event']);
    }

    public function testAUserPermissionGrantCannotExceedCompanyPolicy(): void
    {
        // The same rule as the unit test, asserted through the API the
        // administration screen actually calls.
        $admin   = $this->makeIdentity('Admin');
        $member  = $this->makeIdentity('Member');
        $company = $this->makeCompany(481, 'ABC', ['allow_entire_monitor' => false]);

        $this->grantCompanyAccess($admin, $company, 'COMPANY_ADMIN', true);
        $this->grantCompanyAccess($member, $company);

        $result = $this->withHeaders($this->asUser($admin))
            ->withBodyFormat('json')
            ->put("v1/remote/company/{$company}/permissions/{$member->uuid}", [
                'permissions' => [PermissionCatalog::MONITOR_SHARE => 'ALLOW'],
            ]);

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true)['data'];

        $this->assertSame('ALLOW', $body['overrides'][PermissionCatalog::MONITOR_SHARE]);
        $this->assertFalse(
            $body['permissions'][PermissionCatalog::MONITOR_SHARE],
            'The override is stored, but the company mask still wins.',
        );
    }

    public function testAnUnknownPermissionNameIsRejected(): void
    {
        $admin   = $this->makeIdentity('Admin');
        $member  = $this->makeIdentity('Member');
        $company = $this->makeCompany(481, 'ABC');

        $this->grantCompanyAccess($admin, $company, 'COMPANY_ADMIN', true);
        $this->grantCompanyAccess($member, $company);

        $result = $this->withHeaders($this->asUser($admin))
            ->withBodyFormat('json')
            ->put("v1/remote/company/{$company}/permissions/{$member->uuid}", [
                'permissions' => ['remote.everything' => 'ALLOW'],
            ]);

        $result->assertStatus(400);
        $this->assertSame('PERMISSION_UNKNOWN', json_decode($result->getJSON(), true)['error']['code']);
    }

    // ----------------------------------------------------- portal relay

    public function testTheRelayRefusesAPathThatIsNotAllowlisted(): void
    {
        // Forwarding anything would make this host an open proxy for the
        // portal's whole auth surface.
        $result = $this->post('global/login');

        $result->assertStatus(404);
        $this->assertStringContainsString('not relayed', $result->getJSON());
    }

    // ------------------------------------------------------- rate limiting

    public function testTheJoinCodeEndpointIsRateLimited(): void
    {
        // Nine digits is a small space to defend by luck. This is also a
        // regression test for the limiter itself: a cache key containing a
        // reserved character made every limited endpoint answer 500 instead of
        // 429, which is a limiter that protects nothing.
        $identity = $this->makeIdentity();
        $headers  = $this->asUser($identity);

        $statuses = [];

        for ($attempt = 0; $attempt < 14; $attempt++) {
            $statuses[] = $this->withHeaders($headers)
                ->withBodyFormat('json')
                ->post('v1/remote/join/code', ['code' => '000000000'])
                ->response()
                ->getStatusCode();
        }

        $this->assertNotContains(500, $statuses, 'The limiter must never fail open with a server error.');
        $this->assertContains(429, $statuses, 'Repeated join-code guesses must be rate limited.');
    }
}
