<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Auth\RemoteIdentity;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FakeIdentityResolver;
use Tests\Support\RemoteTestCase;

/**
 * X-14 / G28 over HTTP, through the real router, filters and controllers.
 *
 *   * a launch token is bound to the person and the room it was minted for
 *     (G28#6);
 *   * someone still waiting, declined or removed cannot read the transcript,
 *     the people or the company through the participant row they hold (G28#2).
 *
 * @internal
 */
final class AccessHardeningApiTest extends RemoteTestCase
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
    private function asUser(RemoteIdentity $identity, string $sesKey): array
    {
        $this->identities->register($sesKey, $identity);

        return ['Authorization' => 'Bearer ' . $sesKey];
    }

    /** @return array<string, string> */
    private function asGuest(string $guestToken): array
    {
        return ['Authorization' => 'Bearer guest.' . $guestToken];
    }

    /** @return array<string, mixed> */
    private function json(\CodeIgniter\Test\TestResponse $result): array
    {
        return json_decode((string) $result->getJSON(), true) ?? [];
    }

    // ---------------------------------------------- launch token: G28#6

    private const SECRET = 'test-context-secret';

    /** @param array<string, mixed> $claims */
    private function launchToken(string $subjectUuid, array $claims = []): string
    {
        $encode = static fn (array $d): string => rtrim(strtr(base64_encode((string) json_encode($d, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        $header  = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode(array_merge([
            'iss'        => 'https://my.aicountly.com',
            'aud'        => 'aicountly-remote',
            'sub'        => $subjectUuid,
            'product'    => 'BOOKS',
            'iat'        => time(),
            'exp'        => time() + 120,
            'jti'        => bin2hex(random_bytes(12)),
        ], $claims));

        return $header . '.' . $payload . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $payload, self::SECRET, true)), '+/', '-_'), '=');
    }

    public function testALaunchTokenMintedForSomeoneElseIsRefusedAndNotSpent(): void
    {
        $asha  = $this->makeIdentity('Asha');
        $mallory = $this->makeIdentity('Mallory');
        $token = $this->launchToken($asha->uuid, ['company_id' => 481]);

        // Mallory holds Asha's token (it leaked from a URL, a log, a screen share).
        $stolen = $this->withHeaders($this->asUser($mallory, 'key-mallory') + ['X-Remote-Context' => $token])->get('v1/remote/bootstrap');

        $stolen->assertStatus(400);
        // (The reason, CONTEXT_SUBJECT_MISMATCH, goes to the log and is pinned in
        // SourceContextVerifierTest; the caller is only told the link is not valid.)
        $this->assertSame('CONTEXT_INVALID', $this->json($stolen)['error']['code']);
        $this->assertNull(
            $this->db->table('remote_user_company_access')->where('user_id', $mallory->id)->get()->getRowArray(),
            'a stranger is not recorded as working in Asha\'s company',
        );

        // And presenting it did not burn it: Asha can still use it.
        $own = $this->withHeaders($this->asUser($asha, 'key-asha') + ['X-Remote-Context' => $token])->get('v1/remote/bootstrap');
        $own->assertStatus(200);
    }

    public function testALaunchTokenForARoomIsNotAGeneralLaunchToken(): void
    {
        $asha  = $this->makeIdentity('Asha');
        $token = $this->launchToken($asha->uuid, ['room' => '6f1b6b0c-0000-4000-8000-000000000001']);

        $result = $this->withHeaders($this->asUser($asha, 'key-asha') + ['X-Remote-Context' => $token])->get('v1/remote/bootstrap');

        $result->assertStatus(400);
        $this->assertSame('CONTEXT_INVALID', $this->json($result)['error']['code']);
    }

    public function testALaunchTokenForARoomIsAcceptedForThatRoomOnly(): void
    {
        $host   = $this->makeIdentity('Host');
        $asha   = $this->makeIdentity('Asha');
        $mine   = $this->makeSession($host, 'PERSONAL', null);
        $other  = $this->makeSession($host, 'PERSONAL', null);

        $forOther = $this->launchToken($asha->uuid, ['room' => (string) $other['uuid']]);
        $this->withHeaders($this->asUser($asha, 'key-asha') + ['X-Remote-Context' => $forOther])
            ->post('v1/remote/sessions/' . $mine['uuid'] . '/join-request')
            ->assertStatus(400);

        $forMine = $this->launchToken($asha->uuid, ['room' => (string) $mine['uuid']]);
        $this->withHeaders($this->asUser($asha, 'key-asha') + ['X-Remote-Context' => $forMine])
            ->post('v1/remote/sessions/' . $mine['uuid'] . '/join-request')
            ->assertStatus(201);
    }

    // ---------------------------------------------- read gate: G28#2

    /**
     * A company session with an external guest who has asked to join, and a
     * member who has asked to join, both waiting.
     *
     * @return array{host: RemoteIdentity, member: RemoteIdentity, session: array<string, mixed>, guest: array<string, mixed>, memberParticipant: array<string, mixed>}
     */
    private function roomWithTwoWaiting(): array
    {
        $host    = $this->makeIdentity('Host');
        $member  = $this->makeIdentity('Member');
        $company = $this->makeCompany(481, 'ABC Private Limited', ['allow_external_guest' => true]);
        $this->setEntitlement($company, ['external_guests' => true]);
        $this->grantCompanyAccess($host, $company, 'COMPANY_ADMIN', true);
        $this->grantCompanyAccess($member, $company);

        $session = $this->makeSession($host, 'COMPANY', $company, ['issueSummary' => 'GSTR-2B mismatch for March']);
        $policy  = Services::policyResolver()->resolve($host, 'COMPANY', $company);

        $invitation = Services::invitationService()->create($session, $host, $policy, 'EXTERNAL_GUEST', 'amit@example.com', null);
        $guest      = Services::joinService()->redeemInvitation($invitation['secret'], null, 'Amit Shah', 'amit@example.com', null, null);

        $joined = Services::joinService()->joinAuthenticated($session, $member, null, null);

        // A message and an event exist, so there is something to leak.
        Services::chatService()->post(
            $session,
            Services::participantService()->findByUser((int) $session['id'], $host->id),
            'The March figures are in the attached sheet.',
            'RELAY',
        );

        return ['host' => $host, 'member' => $member, 'session' => $session, 'guest' => $guest, 'memberParticipant' => $joined['participant']];
    }

    /**
     * @param array<string, mixed> $room
     * @param list<string>         $paths the transcript, the timeline and the files — the timeline is a signed-in-only route
     */
    private function assertShutOut(array $headers, array $room, string $code, array $paths = ['messages', 'events', 'transfers']): void
    {
        $uuid = (string) $room['session']['uuid'];

        foreach ($paths as $path) {
            $result = $this->withHeaders($headers)->get('v1/remote/sessions/' . $uuid . '/' . $path);
            $result->assertStatus(403);
            $this->assertSame($code, $this->json($result)['error']['code'], $path);
        }
    }

    public function testAGuestStillInTheWaitingRoomSeesThatTheyAreWaitingAndNothingElse(): void
    {
        $room    = $this->roomWithTwoWaiting();
        $headers = $this->asGuest($room['guest']['guestToken']);
        $uuid    = (string) $room['session']['uuid'];

        $this->assertShutOut($headers, $room, 'AWAITING_APPROVAL', ['messages', 'transfers']);

        $show = $this->withHeaders($headers)->get('v1/remote/sessions/' . $uuid);
        $show->assertStatus(200);
        $data = $this->json($show)['data'];

        $this->assertTrue($data['restricted']);
        $this->assertSame('REQUESTED', $data['me']['status']);
        $this->assertNull($data['companyId']);
        $this->assertNull($data['companyName'], 'the company is not shown to someone not yet admitted');
        $this->assertNull($data['issueSummary']);
        $this->assertSame([], $data['participants']);
        $this->assertFalse($data['capabilities']['chat']);
        $this->assertFalse($data['isHost']);
    }

    public function testADeclinedGuestIsToldSoAndShownNothing(): void
    {
        $room = $this->roomWithTwoWaiting();
        Services::participantService()->deny($room['session'], (string) $room['guest']['participant']['uuid'], $room['host']);

        $headers = $this->asGuest($room['guest']['guestToken']);
        $uuid    = (string) $room['session']['uuid'];

        $this->assertShutOut($headers, $room, 'JOIN_DENIED', ['messages', 'transfers']);

        $show = $this->withHeaders($headers)->get('v1/remote/sessions/' . $uuid);
        $show->assertStatus(403);
        $this->assertSame('JOIN_DENIED', $this->json($show)['error']['code']);
        $this->assertStringNotContainsString('ABC Private Limited', (string) $show->getJSON());
    }

    public function testARemovedGuestIsShutOut(): void
    {
        $room = $this->roomWithTwoWaiting();
        Services::participantService()->approve($room['session'], (string) $room['guest']['participant']['uuid'], $room['host']);
        $this->db->table('remote_participants')->where('uuid', $room['guest']['participant']['uuid'])->update(['status' => 'REMOVED']);

        $this->assertShutOut($this->asGuest($room['guest']['guestToken']), $room, 'NOT_ADMITTED', ['messages', 'transfers']);
    }

    public function testAnAdmittedGuestReadsTheRoom(): void
    {
        $room = $this->roomWithTwoWaiting();
        Services::participantService()->approve($room['session'], (string) $room['guest']['participant']['uuid'], $room['host']);

        $headers = $this->asGuest($room['guest']['guestToken']);
        $uuid    = (string) $room['session']['uuid'];

        $messages = $this->withHeaders($headers)->get('v1/remote/sessions/' . $uuid . '/messages');
        $messages->assertStatus(200);
        $this->assertCount(1, $this->json($messages)['data']);

        $show = $this->withHeaders($headers)->get('v1/remote/sessions/' . $uuid);
        $show->assertStatus(200);
        $this->assertArrayNotHasKey('restricted', $this->json($show)['data']);
        $this->assertSame('ABC Private Limited', $this->json($show)['data']['companyName']);
    }

    public function testASignedInMemberWaitingForTheHostGetsTheSamePreview(): void
    {
        $room    = $this->roomWithTwoWaiting();
        $headers = $this->asUser($room['member'], 'key-member');
        $uuid    = (string) $room['session']['uuid'];

        $this->assertShutOut($headers, $room, 'AWAITING_APPROVAL');

        $show = $this->withHeaders($headers)->get('v1/remote/sessions/' . $uuid);
        $show->assertStatus(200);
        $data = $this->json($show)['data'];
        $this->assertTrue($data['restricted']);
        $this->assertNull($data['companyName']);
        $this->assertSame([], $data['participants']);
        $this->assertSame('REQUESTED', $data['me']['status']);

        // The waiting room's own poll for a token still answers "not yet".
        $token = $this->withHeaders($headers)->post('v1/remote/sessions/' . $uuid . '/signalling-token');
        $token->assertStatus(403);
        $this->assertSame('AWAITING_APPROVAL', $this->json($token)['error']['code']);
    }

    public function testADeclinedMemberCannotReadAndTheSessionLeavesTheirHistory(): void
    {
        $room    = $this->roomWithTwoWaiting();
        $headers = $this->asUser($room['member'], 'key-member');

        $before = $this->json($this->withHeaders($headers)->get('v1/remote/sessions/history'))['data'];
        $this->assertCount(0, $before, 'a request that was never admitted is not part of their history');

        Services::participantService()->deny($room['session'], (string) $room['memberParticipant']['uuid'], $room['host']);

        $this->assertShutOut($headers, $room, 'JOIN_DENIED');
        $show = $this->withHeaders($headers)->get('v1/remote/sessions/' . $room['session']['uuid']);
        $show->assertStatus(403);

        $after = $this->json($this->withHeaders($headers)->get('v1/remote/sessions/history'))['data'];
        $this->assertCount(0, $after);
    }

    public function testTheHostAndAnAdmittedMemberAreUnaffected(): void
    {
        $room = $this->roomWithTwoWaiting();
        Services::participantService()->approve($room['session'], (string) $room['memberParticipant']['uuid'], $room['host']);
        $uuid = (string) $room['session']['uuid'];

        foreach ([[$room['host'], 'key-host'], [$room['member'], 'key-member']] as [$person, $key]) {
            $headers = $this->asUser($person, $key);
            $this->withHeaders($headers)->get('v1/remote/sessions/' . $uuid . '/messages')->assertStatus(200);
            $this->withHeaders($headers)->get('v1/remote/sessions/' . $uuid . '/events')->assertStatus(200);
            $this->withHeaders($headers)->get('v1/remote/sessions/' . $uuid)->assertStatus(200);
        }

        $history = $this->json($this->withHeaders($this->asUser($room['member'], 'key-member'))->get('v1/remote/sessions/history'))['data'];
        $this->assertCount(1, $history, 'once admitted, the session is theirs to find in history');
    }
}
