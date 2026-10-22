<?php

declare(strict_types=1);

namespace Tests\Feature;

use Aicountly\Ice\Topology;
use App\Domain\Policy\PermissionCatalog;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Remote as RemoteConfig;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * The desktop agent's TURN credential (docs/ICE_CONTRACT.md).
 *
 * The agent builds its ICE configuration once, when it joins, and cannot renew it. So its credential
 * must outlast anything it may have to gather again during the session — and must still be a
 * short-lived, HMAC-derived credential, not a long-lived or static one.
 *
 * @internal
 */
final class DeviceIceTest extends RemoteTestCase
{
    use FeatureTestTrait;

    private const SECRET = 'noida-and-mumbai-are-not-the-same-secret';
    private const COMPANY = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        $env = [
            'WEBRTC_TURN_NODES'           => 'mum',
            'WEBRTC_TURN_NODE_MUM_HOST'   => 'turn-mum.aicountly.com',
            'WEBRTC_TURN_NODE_MUM_STATUS' => 'active',
            'WEBRTC_TURN_NODE_MUM_SECRET' => self::SECRET,
            'WEBRTC_TURN_TTL'             => '3600',
        ];
        $this->configureRemote(static function (RemoteConfig $config) use ($env): void {
            $config->signallingSecret = 'test-signalling-secret';
            $config->turnTopology     = Topology::fromEnvironment(static fn (string $k): ?string => $env[$k] ?? null, ['stunUrls' => []]);
            $config->turnHealthFile   = WRITEPATH . 'ice/test-' . bin2hex(random_bytes(4)) . '.json';
        });
    }

    /**
     * An unattended session on an enrolled, online device, and the device's own bearer token.
     *
     * @return array{session: array<string, mixed>, headers: array<string, string>}
     */
    private function deviceInSession(int $sessionMinutes): array
    {
        $owner = $this->makeIdentity('Machine Owner');
        $this->makeDesktopCompany(self::COMPANY, 'Unattended Co', ['max_session_duration_minutes' => $sessionMinutes], true);
        $this->grantCompanyAccess($owner, self::COMPANY, 'MEMBER', true);
        $this->setUserPermission($owner, self::COMPANY, PermissionCatalog::UNATTENDED_ACCESS, 'ALLOW');
        $this->setUserPermission($owner, self::COMPANY, PermissionCatalog::CONTROL_REQUEST, 'ALLOW');

        ['device' => $device, 'secretKey' => $secretKey] = $this->enrolDevice($owner, self::COMPANY);
        $uuid = (string) $device['uuid'];

        Services::deviceService()->enableUnattended($owner, $uuid, true);
        $this->markDeviceOnline($uuid);
        $session = Services::deviceSessionService()->startUnattended($owner, $uuid, 'Long running job')['session'];

        $auth      = Services::deviceAuthenticationService();
        $challenge = $auth->challenge($uuid, '203.0.113.9');
        $token     = $auth->verify(
            $uuid,
            $challenge['nonce'],
            $challenge['issuedAt'],
            $this->signChallenge($secretKey, $uuid, $challenge['nonce'], $challenge['issuedAt']),
        )['token'];

        return ['session' => $session, 'headers' => ['Authorization' => 'Bearer device.' . $token]];
    }

    /** @return array<string, mixed> */
    private function join(array $fixture): array
    {
        $response = $this->withHeaders($fixture['headers'])->post("v1/remote/devices/me/sessions/{$fixture['session']['uuid']}/join");
        $this->assertSame(200, $response->response()->getStatusCode(), (string) $response->getJSON());

        return json_decode($response->getJSON(), true)['data'];
    }

    public function testTheAgentCredentialCoversTheRestOfALongSession(): void
    {
        $fixture = $this->deviceInSession(360);   // a six-hour session: under the eight-hour cap

        $data = $this->join($fixture);
        $turn = $data['iceServers'][1];

        $remaining = strtotime((string) $fixture['session']['expires_at']) - time();
        $expiry    = (int) explode(':', $turn['username'], 2)[0];

        $this->assertEqualsWithDelta(time() + $remaining + 300, $expiry, 5, 'The session and five minutes of slack.');
        $this->assertGreaterThan(time() + 3600, $expiry, 'Longer than the browser\'s ordinary hour — the agent cannot renew.');
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], self::SECRET, true)), $turn['credential'], 'Still the HMAC credential, derived per node.');
        $this->assertEqualsWithDelta($remaining + 300, $data['ice']['expiresIn'], 5, 'The contract says the same as the credential.');
    }

    public function testAShortSessionKeepsTheOrdinaryLifetimeRatherThanAShorterOne(): void
    {
        $fixture = $this->deviceInSession(30);

        $expiry = (int) explode(':', $this->join($fixture)['iceServers'][1]['username'], 2)[0];

        $this->assertEqualsWithDelta(time() + 3600, $expiry, 5);
    }

    public function testTheLongestAgentCredentialIsCapped(): void
    {
        $fixture = $this->deviceInSession(1440);   // the policy ceiling: a whole day

        $expiry = (int) explode(':', $this->join($fixture)['iceServers'][1]['username'], 2)[0];

        $this->assertEqualsWithDelta(time() + 28800, $expiry, 5, 'WEBRTC_TURN_SESSION_TTL_MAX, eight hours by default.');
    }

    public function testTheAgentCredentialIsBoundToTheParticipantAndNeverCarriesTheSecret(): void
    {
        $fixture = $this->deviceInSession(360);
        $response = $this->withHeaders($fixture['headers'])->post("v1/remote/devices/me/sessions/{$fixture['session']['uuid']}/join");
        $raw      = (string) $response->getJSON();
        $data     = json_decode($raw, true)['data'];

        $this->assertStringEndsWith('.' . $data['participant']['uuid'], $data['iceServers'][1]['username'], 'The username carries the tier (f./p.) and then the participant, never an email address.');
        $this->assertStringNotContainsString(self::SECRET, $raw);
    }

    public function testADeviceWithoutItsTokenGetsNoCredential(): void
    {
        $fixture = $this->deviceInSession(60);

        $response = $this->withHeaders([])->post("v1/remote/devices/me/sessions/{$fixture['session']['uuid']}/join");

        $response->assertStatus(401);
        $this->assertStringNotContainsString('credential', (string) $response->getJSON());
    }
}
