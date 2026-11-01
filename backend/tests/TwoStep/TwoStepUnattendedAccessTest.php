<?php

declare(strict_types=1);

namespace Tests\TwoStep;

use App\Domain\Auth\RemoteIdentity;
use App\Domain\Support\ApiException;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\DeviceApiRequests;
use Tests\Support\RemoteTestCase;

/**
 * Unattended access needs Remote's own two-step verification.
 *
 * Reaching a computer with nobody at it is what turns a stolen portal session
 * into somebody else's desktop, and the portal sign-in is the one thing Remote
 * cannot see into. So:
 *
 *   * without two-step verification, switching unattended access on and
 *     connecting are both refused with TWO_STEP_SETUP_REQUIRED, saying where to
 *     turn it on;
 *   * switching it on needs a code entered in the last ten minutes (step-up);
 *   * connecting needs a verification still within its lifetime;
 *   * switching it off never needs either.
 *
 * Checked at the service — where every caller passes — and over HTTP.
 *
 * @internal
 */
final class TwoStepUnattendedAccessTest extends RemoteTestCase
{
    use FeatureTestTrait;
    use DeviceApiRequests;

    /** @return array{owner: RemoteIdentity, uuid: string, enrolled: array<string, mixed>} */
    private function personalComputer(): array
    {
        $owner    = $this->makeIdentity('Meera');
        $enrolled = $this->enrolDevice($owner, null, ['deviceName' => 'Meera home PC']);
        $uuid     = (string) $enrolled['device']['uuid'];
        $this->markDeviceOnline($uuid);

        return ['owner' => $owner, 'uuid' => $uuid, 'enrolled' => $enrolled];
    }

    /** @return array<string, mixed> */
    private function error(TestResponse $response): array
    {
        return json_decode((string) $response->getJSON(), true, 512, JSON_THROW_ON_ERROR)['error'];
    }

    private function codeOf(callable $attempt): string
    {
        try {
            $attempt();
        } catch (ApiException $exception) {
            return $exception->errorCode();
        }

        $this->fail('Expected a refusal.');
    }

    /** As if the code had been entered this many seconds ago. */
    private function verifiedSecondsAgo(int $seconds): void
    {
        $this->db->table('remote_two_step_verifications')->update(['verified_at' => gmdate('Y-m-d H:i:s', time() - $seconds) . '+00']);
    }

    public function testWithoutTwoStepVerificationNeitherSwitchingOnNorConnectingIsAllowed(): void
    {
        ['owner' => $owner, 'uuid' => $uuid] = $this->personalComputer();

        $enable = $this->postJson($this->asPerson($owner), "devices/{$uuid}/unattended/enable", ['confirm' => true]);
        $this->assertSame('TWO_STEP_SETUP_REQUIRED', $this->errorOf($enable, 403));
        $this->assertStringContainsString('Turn it on in Settings, under Two-step verification', (string) $this->error($enable)['message']);
        $this->assertSame('/settings#two-step', $this->error($enable)['details']['setupPath']);
        $this->assertFalse(Services::deviceService()->findByUuidOrFail($uuid)['unattended_access_enabled']);

        // A computer whose unattended access was switched on before two-step verification existed.
        $this->db->table('remote_devices')->where('uuid', $uuid)->update(['unattended_access_enabled' => true, 'unattended_enabled_at' => gmdate('Y-m-d H:i:s') . '+00']);

        $connect = $this->postJson($this->asPerson($owner), "devices/{$uuid}/connect");
        $this->assertSame('TWO_STEP_SETUP_REQUIRED', $this->errorOf($connect, 403));
        $this->assertStringContainsString('then connect again', (string) $this->error($connect)['message']);
        $this->assertSame(0, $this->db->table('remote_sessions')->countAllResults(), 'no session was started');

        // The service refuses on its own, whoever calls it.
        $this->assertSame('TWO_STEP_SETUP_REQUIRED', $this->codeOf(fn () => Services::deviceSessionService()->startUnattended($owner, $uuid)));
    }

    public function testSwitchingOnNeedsACodeFromTheLastTenMinutes(): void
    {
        ['owner' => $owner, 'uuid' => $uuid] = $this->personalComputer();
        $this->turnOnTwoStep($owner);

        // Eleven minutes after the code: verified for the day, but not fresh enough for this.
        $this->verifiedSecondsAgo(660);
        $stale = $this->postJson($this->asPerson($owner), "devices/{$uuid}/unattended/enable", ['confirm' => true]);
        $this->assertSame('TWO_STEP_REQUIRED', $this->errorOf($stale, 403));
        $this->assertSame('STEP_UP', $this->error($stale)['details']['reason']);
        $this->assertSame(600, $this->error($stale)['details']['withinSeconds']);
        $this->assertStringContainsString('in the last 10 minutes', (string) $this->error($stale)['message']);
        $this->assertFalse(Services::deviceService()->findByUuidOrFail($uuid)['unattended_access_enabled']);

        // Nine minutes after: allowed.
        $this->verifiedSecondsAgo(540);
        $enabled = $this->dataOf($this->postJson($this->asPerson($owner), "devices/{$uuid}/unattended/enable", ['confirm' => true]));
        $this->assertTrue($enabled['device']['unattendedAccessEnabled']);
    }

    public function testAFreshCodeIsHowTheStepUpIsGiven(): void
    {
        ['owner' => $owner, 'uuid' => $uuid] = $this->personalComputer();
        $secret = $this->turnOnTwoStep($owner)['secret'];
        $this->verifiedSecondsAgo(3600);

        $this->assertSame('TWO_STEP_REQUIRED', $this->errorOf($this->postJson($this->asPerson($owner), "devices/{$uuid}/unattended/enable", ['confirm' => true]), 403));

        // The web asks for a code, verifies, and sends the same request again with the new token.
        $passed = $this->dataOf($this->postJson($this->asPerson($owner), 'me/two-step/verify', ['code' => $this->totpCode($secret, 1)]));
        $this->twoStepTokens[$owner->id] = $passed['verificationToken'];

        $this->assertTrue($this->dataOf($this->postJson($this->asPerson($owner), "devices/{$uuid}/unattended/enable", ['confirm' => true]))['device']['unattendedAccessEnabled']);
    }

    public function testConnectingNeedsAVerificationStillWithinItsLifetime(): void
    {
        ['owner' => $owner, 'uuid' => $uuid] = $this->personalComputer();
        $this->turnOnTwoStep($owner);
        Services::deviceService()->enableUnattended($owner, $uuid, true);

        // Hours after the code — far outside the step-up window — connecting is still fine.
        $this->verifiedSecondsAgo(4 * 3600);
        $connected = $this->dataOf($this->postJson($this->asPerson($owner), "devices/{$uuid}/connect"), 201);
        $this->assertSame('UNATTENDED', Services::sessionService()->findByUuidOrFail((string) $connected['session']['uuid'])['access_mode']);

        // Once the verification has expired, it is not: over HTTP the gate answers first…
        $this->db->table('remote_two_step_verifications')->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 1) . '+00']);
        $refused = $this->postJson($this->asPerson($owner), "devices/{$uuid}/connect");
        $this->assertSame('TWO_STEP_REQUIRED', $this->errorOf($refused, 403));
        $this->assertSame('SESSION', $this->error($refused)['details']['reason']);

        // …and the service itself refuses too, for any other caller.
        $this->actAsTwoStepVerified($owner);
        try {
            Services::deviceSessionService()->startUnattended($owner, $uuid);
            $this->fail('An expired verification must not connect.');
        } catch (ApiException $exception) {
            $this->assertSame('TWO_STEP_REQUIRED', $exception->errorCode());
            $this->assertStringContainsString('Connecting to a computer with nobody at it needs a code', $exception->getMessage());
        }
    }

    public function testSwitchingItOffNeverNeedsTwoStepVerification(): void
    {
        ['owner' => $owner, 'uuid' => $uuid] = $this->personalComputer();
        $this->turnOnTwoStep($owner);
        Services::deviceService()->enableUnattended($owner, $uuid, true);

        // A browser that has not passed two-step verification at all (no token): still allowed.
        $unverified = ['Authorization' => $this->asPerson($owner)['Authorization']];
        $this->assertSame('TWO_STEP_REQUIRED', $this->errorOf($this->getJson($unverified, "devices/{$uuid}"), 403), 'other device routes are behind the gate');
        $off = $this->dataOf($this->postJson($unverified, "devices/{$uuid}/unattended/disable"));
        $this->assertFalse($off['device']['unattendedAccessEnabled']);

        // And for somebody who has never turned two-step verification on.
        ['owner' => $other, 'uuid' => $otherUuid] = $this->personalComputer();
        $this->db->table('remote_devices')->where('uuid', $otherUuid)->update(['unattended_access_enabled' => true]);
        $this->assertFalse($this->dataOf($this->postJson($this->asPerson($other), "devices/{$otherUuid}/unattended/disable"))['device']['unattendedAccessEnabled']);

        // Still nobody else's computer, though.
        $this->errorOf($this->postJson($this->asPerson($other), "devices/{$uuid}/unattended/disable"), 404);
    }

    public function testAStrangerIsStillToldTheComputerDoesNotExistRatherThanAskedForACode(): void
    {
        ['uuid' => $uuid] = $this->personalComputer();
        $stranger = $this->makeIdentity('Stranger');

        $this->errorOf($this->postJson($this->asPerson($stranger), "devices/{$uuid}/unattended/enable", ['confirm' => true]), 404);
        $this->errorOf($this->postJson($this->asPerson($stranger), "devices/{$uuid}/connect"), 404);
    }

    public function testTheDesktopAppsOwnSwitchStillTurnsItOff(): void
    {
        ['owner' => $owner, 'uuid' => $uuid, 'enrolled' => $enrolled] = $this->personalComputer();
        $this->turnOnTwoStep($owner);
        Services::deviceService()->enableUnattended($owner, $uuid, true);

        $this->dataOf($this->postJson($this->asDevice($enrolled), 'devices/me/unattended/disable'));

        $this->assertFalse(Services::deviceService()->findByUuidOrFail($uuid)['unattended_access_enabled']);
    }
}
