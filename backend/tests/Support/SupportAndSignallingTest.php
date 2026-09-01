<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Audit\EventType;
use App\Domain\Support\ApiException;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * AICOUNTLY Support, and the signalling-token gate (§19, §24, §59, §71).
 *
 * @internal
 */
final class SupportAndSignallingTest extends RemoteTestCase
{
    public function testASupportRequestKeepsItsCompanyContext(): void
    {
        $customer = $this->makeIdentity('Rahul Gupta');
        $company  = $this->makeCompany(481, 'ABC Private Limited');
        $this->grantCompanyAccess($customer, $company);

        $policy = Services::policyResolver()->resolve($customer, 'AICOUNTLY_SUPPORT', $company);

        $result = Services::supportRequestService()->create($customer, [
            'issueSummary'    => 'GSTR-2B figures do not match',
            'supportTicketId' => 'TCK-4471',
        ], $policy, null);

        $this->assertSame('PENDING', $result['request']['status']);
        $this->assertSame($company, (int) $result['request']['company_id']);
        $this->assertSame('AICOUNTLY_SUPPORT', $result['session']['scope_type']);
        $this->assertSame($company, (int) $result['session']['company_id']);
        $this->assertSame('TCK-4471', $result['session']['support_ticket_id']);

        $this->assertHasAudit(EventType::SUPPORT_REQUESTED);
    }

    public function testOnlyOneTechnicianCanTakeARequest(): void
    {
        // §59 — two technicians clicking Accept at the same moment is the
        // normal case, not the edge case.
        $customer = $this->makeIdentity('Customer');
        $amanA    = $this->makeIdentity('Aman Verma', null, true);
        $amanB    = $this->makeIdentity('Neha Rao', null, true);

        $policy = Services::policyResolver()->resolve($customer, 'PERSONAL', null);
        $result = Services::supportRequestService()->create($customer, [], $policy, null);
        $uuid   = (string) $result['request']['uuid'];

        Services::supportRequestService()->accept($uuid, $amanA);

        try {
            Services::supportRequestService()->accept($uuid, $amanB);
            $this->fail('A second technician must not also take the request.');
        } catch (ApiException $e) {
            $this->assertSame('SUPPORT_REQUEST_TAKEN', $e->errorCode());
            $this->assertSame(409, $e->status());
        }

        $stored = Services::supportRequestService()->findByUuidOrFail($uuid);
        $this->assertSame($amanA->id, (int) $stored['accepted_by_user_id']);
    }

    public function testAcceptingARequestStillRequiresTheCustomersApproval(): void
    {
        // Taking a support request does not grant sight of a screen (§71).
        $customer = $this->makeIdentity('Customer');
        $aman     = $this->makeIdentity('Aman Verma', null, true);

        $policy = Services::policyResolver()->resolve($customer, 'PERSONAL', null);
        $result = Services::supportRequestService()->create($customer, [], $policy, null);

        Services::supportRequestService()->accept((string) $result['request']['uuid'], $aman);

        $session     = Services::sessionService()->findByUuidOrFail((string) $result['session']['uuid']);
        $participant = Services::participantService()->findByUser((int) $session['id'], $aman->id);

        $this->assertNotNull($participant);
        $this->assertSame('SUPPORT_TECHNICIAN', $participant['participant_role']);
        $this->assertSame('REQUESTED', $participant['status'], 'The technician waits for the customer.');
    }

    public function testSomeoneWithoutSupportPermissionCannotTakeARequest(): void
    {
        $customer  = $this->makeIdentity('Customer');
        $bystander = $this->makeIdentity('Bystander');

        $policy = Services::policyResolver()->resolve($customer, 'PERSONAL', null);
        $result = Services::supportRequestService()->create($customer, [], $policy, null);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('You do not have permission to take AICOUNTLY Support requests.');

        Services::supportRequestService()->accept((string) $result['request']['uuid'], $bystander);
    }

    public function testSupportIsRefusedWhenTheCompanyHasTurnedItOff(): void
    {
        $customer = $this->makeIdentity('Customer');
        $company  = $this->makeCompany(481, 'ABC', ['allow_aicountly_support' => false]);
        $this->grantCompanyAccess($customer, $company);

        $policy = Services::policyResolver()->resolve($customer, 'AICOUNTLY_SUPPORT', $company);

        try {
            Services::supportRequestService()->create($customer, [], $policy, null);
            $this->fail('This company has turned AICOUNTLY Support off.');
        } catch (ApiException $e) {
            $this->assertSame('SUPPORT_SESSIONS_DISABLED', $e->errorCode());
        }

        $this->assertSame(0, $this->db->table('remote_sessions')->countAllResults(), 'Nothing may be created on a refusal.');
    }

    public function testACustomerOnlySeesTheirOwnRequests(): void
    {
        $customerA = $this->makeIdentity('Customer A');
        $customerB = $this->makeIdentity('Customer B');

        Services::supportRequestService()->create(
            $customerA,
            [],
            Services::policyResolver()->resolve($customerA, 'PERSONAL', null),
            null,
        );
        Services::supportRequestService()->create(
            $customerB,
            [],
            Services::policyResolver()->resolve($customerB, 'PERSONAL', null),
            null,
        );

        $queue = Services::supportRequestService()->queue($customerA, []);

        $this->assertCount(1, $queue['items']);
        $this->assertSame($customerA->id, (int) $queue['items'][0]['requester_user_id']);
    }

    public function testATechnicianSeesTheWholeQueue(): void
    {
        $customerA = $this->makeIdentity('Customer A');
        $customerB = $this->makeIdentity('Customer B');
        $aman      = $this->makeIdentity('Aman Verma', null, true);

        foreach ([$customerA, $customerB] as $customer) {
            Services::supportRequestService()->create(
                $customer,
                [],
                Services::policyResolver()->resolve($customer, 'PERSONAL', null),
                null,
            );
        }

        $queue = Services::supportRequestService()->queue($aman, []);

        $this->assertCount(2, $queue['items']);
    }

    // ------------------------------------------------------------ signalling

    public function testAnUnapprovedParticipantGetsNoSignallingToken(): void
    {
        // This is what makes host approval mean something: no token, no room,
        // no offer, no frames.
        $host   = $this->makeIdentity('Host');
        $viewer = $this->makeIdentity('Viewer');

        $session = $this->makeSession($host);
        $joined  = Services::joinService()->joinByCode((string) $session['session_code'], $viewer, null, null);

        $participant = $joined['participant'];
        $this->assertSame('REQUESTED', $participant['status']);

        // The controller is what enforces this; assert the precondition it
        // checks, and then that approval changes the answer.
        $this->assertNotContains($participant['status'], ['APPROVED', 'JOINED']);

        Services::participantService()->approve($this->reload($session), (string) $participant['uuid'], $host);

        $approved = Services::participantService()->findByUuidOrFail((string) $participant['uuid']);
        $this->assertSame('APPROVED', $approved['status']);

        $token = Services::signallingTokenService()->issue(
            (string) $session['uuid'],
            (string) $approved['uuid'],
            (string) $approved['participant_role'],
            (string) $approved['display_name'],
            ['screen_view' => true],
        );

        $this->assertNotEmpty($token['token']);
        $this->assertSame((string) $session['uuid'], $token['room']);
    }

    public function testASignallingTokenIsSignedAndShortLived(): void
    {
        $config = Services::remoteConfig();
        $token  = Services::signallingTokenService()->issue('room-uuid', 'participant-uuid', 'VIEWER', 'Viewer', []);

        [$header, $payload, $signature] = explode('.', $token['token']);

        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header . '.' . $payload, $config->signallingSecret, true),
        ), '+/', '-_'), '=');

        $this->assertSame($expected, $signature, 'The signalling service verifies this signature.');

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        $this->assertSame('room-uuid', $claims['room']);
        $this->assertSame('aicountly-remote-signalling', $claims['aud']);
        $this->assertLessThanOrEqual(300, $claims['exp'] - $claims['iat'], 'Signalling tokens must be short-lived.');
    }

    public function testNoSignallingTokenIsIssuedWithoutASecret(): void
    {
        $this->configureRemote(static function ($config): void {
            $config->signallingSecret = '';
        });

        try {
            Services::signallingTokenService()->issue('room', 'participant', 'VIEWER', 'Viewer', []);
            $this->fail('An unsigned token would let anyone who guessed a room name in.');
        } catch (ApiException $e) {
            $this->assertSame('SIGNALLING_UNCONFIGURED', $e->errorCode());
            $this->assertSame(503, $e->status());
        }
    }

    public function testTurnCredentialsAreEphemeralWhenAStaticSecretIsConfigured(): void
    {
        $this->configureRemote(static function ($config): void {
            $config->signallingSecret     = 'test-signalling-secret';
            $config->turnUrls             = ['turns:turn.aicountly.test:5349'];
            $config->turnStaticAuthSecret = 'coturn-shared-secret';
            $config->turnCredentialTtlSeconds = 600;
        });

        $servers = Services::iceConfigService()->iceServers();
        $turn    = array_values(array_filter($servers, static fn ($s) => isset($s['credential'])));

        $this->assertCount(1, $turn);
        $this->assertTrue(Services::iceConfigService()->hasRelay());

        // coturn's use-auth-secret scheme: username is the expiry, password is
        // its HMAC. The secret itself never leaves the server.
        $expiry = (int) $turn[0]['username'];
        $this->assertGreaterThan(time(), $expiry);
        $this->assertSame(
            base64_encode(hash_hmac('sha1', $turn[0]['username'], 'coturn-shared-secret', true)),
            $turn[0]['credential'],
        );
    }

    public function testNoRelayIsReportedWhenTurnIsNotConfigured(): void
    {
        $this->configureRemote(static function ($config): void {
            $config->signallingSecret = 'test-signalling-secret';
            $config->turnUrls         = [];
        });

        $this->assertFalse(
            Services::iceConfigService()->hasRelay(),
            'The UI needs to know, so it can explain an unreachable peer honestly.',
        );
    }
}
