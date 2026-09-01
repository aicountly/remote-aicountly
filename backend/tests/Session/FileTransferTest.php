<?php

declare(strict_types=1);

namespace Tests\Session;

use App\Domain\Audit\EventType;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\FileTransferService;
use App\Domain\Support\ApiException;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * The file transfer ledger (§36).
 *
 * The bytes never reach this server, so what is tested here is everything the
 * two peers must *not* be trusted with: the policy gate, the size ceiling, the
 * recipient's consent, and the fact that a transfer cannot progress before that
 * consent exists.
 *
 * @internal
 */
final class FileTransferTest extends RemoteTestCase
{
    /**
     * A session with two admitted participants — the shape every transfer test
     * needs.
     *
     * @return array{session: array<string, mixed>, host: array<string, mixed>, viewer: array<string, mixed>}
     */
    private function sessionWithTwo(?int $companyId = null, array $policyOverrides = []): array
    {
        $host   = $this->makeIdentity('Host');
        $viewer = $this->makeIdentity('Viewer');

        if ($companyId !== null) {
            $this->makeCompany($companyId, 'ABC', array_merge(['allow_file_transfer' => true], $policyOverrides));
            $this->grantCompanyAccess($host, $companyId);
            $this->grantCompanyAccess($viewer, $companyId);
            $this->setEntitlement($companyId, ['file_transfer' => true]);
        }

        $session = $companyId === null
            ? $this->makeSession($host, 'PERSONAL', null)
            : $this->makeSession($host, 'COMPANY', $companyId);

        $joined = Services::joinService()->joinByCode((string) $session['session_code'], $viewer, null, null);
        Services::participantService()->approve(
            $this->reload($session),
            (string) $joined['participant']['uuid'],
            $host,
        );

        $session = $this->reload($session);

        return [
            'session'  => $session,
            'host'     => Services::participantService()->findByUser((int) $session['id'], $host->id),
            'viewer'   => Services::participantService()->findByUuidOrFail((string) $joined['participant']['uuid']),
            'hostUser' => $host,
            'viewerUser' => $viewer,
        ];
    }

    /** @param array<string, mixed> $context */
    private function policyFor(array $context, string $who = 'hostUser')
    {
        $session = $context['session'];

        return Services::policyResolver()->resolve(
            $context[$who],
            (string) $session['scope_type'],
            $session['company_id'] !== null ? (int) $session['company_id'] : null,
        );
    }

    public function testTheHappyPathIsOfferAcceptProgressComplete(): void
    {
        $ctx     = $this->sessionWithTwo(481);
        $service = Services::fileTransferService();

        $offered = $service->offer(
            $ctx['session'],
            $ctx['host'],
            (string) $ctx['viewer']['uuid'],
            'gstr2b-mismatch.csv',
            4096,
            'text/csv',
            $this->policyFor($ctx),
        );

        $this->assertSame(FileTransferService::OFFERED, $offered['status']);
        $this->assertSame(0, (int) $offered['bytes_transferred']);
        $this->assertHasEvent($ctx['session'], EventType::FILE_TRANSFER_OFFERED);

        $accepted = $service->accept(
            $ctx['session'],
            (string) $offered['uuid'],
            $ctx['viewer'],
            $this->policyFor($ctx, 'viewerUser'),
        );
        $this->assertSame(FileTransferService::ACCEPTED, $accepted['status']);

        $moving = $service->progress($ctx['session'], (string) $offered['uuid'], $ctx['host'], 2048);
        $this->assertSame(FileTransferService::IN_PROGRESS, $moving['status']);
        $this->assertSame(2048, (int) $moving['bytes_transferred']);
        $this->assertNotNull($moving['started_at']);
        $this->assertHasEvent($ctx['session'], EventType::FILE_TRANSFER_STARTED);

        $done = $service->complete($ctx['session'], (string) $offered['uuid'], $ctx['viewer']);
        $this->assertSame(FileTransferService::COMPLETED, $done['status']);
        // Completion trusts the declared size, not the last progress report.
        $this->assertSame(4096, (int) $done['bytes_transferred']);
        $this->assertNotNull($done['completed_at']);
        $this->assertHasEvent($ctx['session'], EventType::FILE_TRANSFER_COMPLETED);
    }

    public function testATransferCannotProgressBeforeItIsAccepted(): void
    {
        // The guarantee: consent comes first, and the ledger will not record a
        // byte as moved until it has.
        $ctx     = $this->sessionWithTwo(481);
        $service = Services::fileTransferService();

        $offered = $service->offer(
            $ctx['session'],
            $ctx['host'],
            (string) $ctx['viewer']['uuid'],
            'notes.txt',
            1024,
            'text/plain',
            $this->policyFor($ctx),
        );

        try {
            $service->progress($ctx['session'], (string) $offered['uuid'], $ctx['host'], 512);
            $this->fail('Progress before acceptance must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('FILE_TRANSFER_NOT_ACTIVE', $e->errorCode());
        }

        try {
            $service->complete($ctx['session'], (string) $offered['uuid'], $ctx['viewer']);
            $this->fail('Completion before acceptance must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('FILE_TRANSFER_NOT_ACTIVE', $e->errorCode());
        }
    }

    public function testOnlyTheRecipientMayAcceptOrDecline(): void
    {
        $ctx     = $this->sessionWithTwo(481);
        $service = Services::fileTransferService();

        $offered = $service->offer(
            $ctx['session'],
            $ctx['host'],
            (string) $ctx['viewer']['uuid'],
            'notes.txt',
            1024,
            null,
            $this->policyFor($ctx),
        );

        // The sender accepting their own offer would make consent meaningless.
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Only the person this file was sent to can answer for it.');

        $service->accept($ctx['session'], (string) $offered['uuid'], $ctx['host'], $this->policyFor($ctx));
    }

    public function testAcceptingTwiceIsIdempotentAndDecliningAfterwardsIsRefused(): void
    {
        // Two clicks on Accept, or an accept racing a decline: exactly one
        // outcome, decided by the database.
        $ctx     = $this->sessionWithTwo(481);
        $service = Services::fileTransferService();
        $policy  = $this->policyFor($ctx, 'viewerUser');

        $offered = $service->offer(
            $ctx['session'],
            $ctx['host'],
            (string) $ctx['viewer']['uuid'],
            'notes.txt',
            1024,
            null,
            $this->policyFor($ctx),
        );

        $service->accept($ctx['session'], (string) $offered['uuid'], $ctx['viewer'], $policy);
        $again = $service->accept($ctx['session'], (string) $offered['uuid'], $ctx['viewer'], $policy);
        $this->assertSame(FileTransferService::ACCEPTED, $again['status']);

        // Declining an accepted transfer is still allowed — the recipient may
        // change their mind mid-transfer — but declining a completed one is not.
        $service->complete($ctx['session'], (string) $offered['uuid'], $ctx['viewer']);

        $this->expectException(ApiException::class);
        $service->decline($ctx['session'], (string) $offered['uuid'], $ctx['viewer']);
    }

    public function testDecliningRecordsItAsDeclinedRatherThanFailed(): void
    {
        // A declined transfer is not a failure, and an audit trail that says
        // otherwise misrepresents what the recipient did.
        $ctx     = $this->sessionWithTwo(481);
        $service = Services::fileTransferService();

        $offered = $service->offer(
            $ctx['session'],
            $ctx['host'],
            (string) $ctx['viewer']['uuid'],
            'payroll.xlsx',
            2048,
            null,
            $this->policyFor($ctx),
        );

        $declined = $service->decline($ctx['session'], (string) $offered['uuid'], $ctx['viewer']);

        $this->assertSame(FileTransferService::DECLINED, $declined['status']);
        $this->assertHasEvent($ctx['session'], EventType::FILE_TRANSFER_DECLINED);
        $this->assertSame(
            0,
            $this->db->table('remote_session_events')
                ->where('session_id', $ctx['session']['id'])
                ->where('event_type', EventType::FILE_TRANSFER_FAILED)
                ->countAllResults(),
        );
    }

    public function testAFileLargerThanTheCeilingIsRefused(): void
    {
        $ctx = $this->sessionWithTwo(481);

        $this->configureRemote(static function ($config): void {
            $config->contextSecret       = 'test-context-secret';
            $config->signallingSecret    = 'test-signalling-secret';
            $config->fileTransferMaxBytes = 1024;
        });

        try {
            Services::fileTransferService()->offer(
                $ctx['session'],
                $ctx['host'],
                (string) $ctx['viewer']['uuid'],
                'huge.zip',
                2048,
                null,
                $this->policyFor($ctx),
            );
            $this->fail('A file over the ceiling must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('FILE_TOO_LARGE', $e->errorCode());
            $this->assertSame(1024, $e->details()['maxBytes']);
        }

        $this->assertSame(0, $this->db->table('remote_file_transfers')->countAllResults());
    }

    public function testTransferIsRefusedWhenTheCompanyHasItSwitchedOff(): void
    {
        $ctx = $this->sessionWithTwo(481, ['allow_file_transfer' => false]);

        try {
            Services::fileTransferService()->offer(
                $ctx['session'],
                $ctx['host'],
                (string) $ctx['viewer']['uuid'],
                'notes.txt',
                512,
                null,
                $this->policyFor($ctx),
            );
            $this->fail('The organisation has file transfer switched off.');
        } catch (ApiException $e) {
            $this->assertSame('FILE_TRANSFER_DISABLED', $e->errorCode());
        }
    }

    public function testTransferIsRefusedWhenThePlanDoesNotIncludeIt(): void
    {
        // The entitlement sits above company policy (§9), so a company that
        // permits transfers still cannot have them on a plan without them.
        $host   = $this->makeIdentity('Host');
        $viewer = $this->makeIdentity('Viewer');
        $this->makeCompany(481, 'ABC', ['allow_file_transfer' => true]);
        $this->grantCompanyAccess($host, 481);
        $this->grantCompanyAccess($viewer, 481);
        $this->setEntitlement(481, ['file_transfer' => false]);

        $session = $this->makeSession($host, 'COMPANY', 481);

        $this->assertFalse(
            $session['allow_file_transfer'],
            'The session snapshot must already have transfers off.',
        );

        $joined = Services::joinService()->joinByCode((string) $session['session_code'], $viewer, null, null);
        Services::participantService()->approve($this->reload($session), (string) $joined['participant']['uuid'], $host);

        try {
            Services::fileTransferService()->offer(
                $this->reload($session),
                Services::participantService()->findByUser((int) $session['id'], $host->id),
                (string) $joined['participant']['uuid'],
                'notes.txt',
                512,
                null,
                Services::policyResolver()->resolve($host, 'COMPANY', 481),
            );
            $this->fail('The plan does not include file transfer.');
        } catch (ApiException $e) {
            $this->assertSame('FILE_TRANSFER_DISABLED', $e->errorCode());
        }
    }

    public function testAUserDenyRemovesTheAbilityToSend(): void
    {
        $ctx = $this->sessionWithTwo(481);

        $this->setUserPermission($ctx['hostUser'], 481, PermissionCatalog::FILE_SEND, 'DENY');

        try {
            Services::fileTransferService()->offer(
                $ctx['session'],
                $ctx['host'],
                (string) $ctx['viewer']['uuid'],
                'notes.txt',
                512,
                null,
                $this->policyFor($ctx),
            );
            $this->fail('This user may not send files.');
        } catch (ApiException $e) {
            $this->assertSame('FILE_SEND_DENIED', $e->errorCode());
        }
    }

    public function testARecipientOutsideTheSessionIsRefused(): void
    {
        // The recipient is resolved against the session's real participants,
        // not taken on the sender's word.
        $ctx      = $this->sessionWithTwo(481);
        $outsider = $this->makeIdentity('Outsider');
        $this->grantCompanyAccess($outsider, 481);

        $otherSession = $this->makeSession($outsider, 'COMPANY', 481);
        $otherHost    = Services::participantService()->findByUser((int) $otherSession['id'], $outsider->id);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('That participant could not be found in this session.');

        Services::fileTransferService()->offer(
            $ctx['session'],
            $ctx['host'],
            (string) $otherHost['uuid'],
            'notes.txt',
            512,
            null,
            $this->policyFor($ctx),
        );
    }

    public function testAFileNameCannotCarryAPath(): void
    {
        // The name is attacker-controlled input at every hop. It is reduced
        // here, and again in the browser before it becomes a download filename.
        $ctx = $this->sessionWithTwo(481);

        $offered = Services::fileTransferService()->offer(
            $ctx['session'],
            $ctx['host'],
            (string) $ctx['viewer']['uuid'],
            "../../etc/passwd\u{0000}.txt",
            512,
            null,
            $this->policyFor($ctx),
        );

        $this->assertStringNotContainsString('/', $offered['file_name']);
        $this->assertStringNotContainsString('\\', $offered['file_name']);
        $this->assertStringNotContainsString("\0", $offered['file_name']);
        $this->assertStringStartsNotWith('.', $offered['file_name']);
    }

    public function testASenderCannotFloodTheLedgerWithOffers(): void
    {
        $ctx     = $this->sessionWithTwo(481);
        $service = Services::fileTransferService();

        for ($i = 0; $i < 2; $i++) {
            $service->offer(
                $ctx['session'],
                $ctx['host'],
                (string) $ctx['viewer']['uuid'],
                "file-{$i}.txt",
                512,
                null,
                $this->policyFor($ctx),
            );
        }

        try {
            $service->offer(
                $ctx['session'],
                $ctx['host'],
                (string) $ctx['viewer']['uuid'],
                'file-3.txt',
                512,
                null,
                $this->policyFor($ctx),
            );
            $this->fail('A third outstanding offer must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('FILE_TRANSFER_BUSY', $e->errorCode());
        }
    }

    public function testAbortingIsIdempotentSoALateReportIsNotAnError(): void
    {
        // A dropped connection reports the failure whenever it notices. Doing
        // that twice must not be an error the user sees.
        $ctx     = $this->sessionWithTwo(481);
        $service = Services::fileTransferService();

        $offered = $service->offer(
            $ctx['session'],
            $ctx['host'],
            (string) $ctx['viewer']['uuid'],
            'notes.txt',
            512,
            null,
            $this->policyFor($ctx),
        );

        $first = $service->abort($ctx['session'], (string) $offered['uuid'], $ctx['host'], 'FAILED', 'CHANNEL_CLOSED');
        $this->assertSame(FileTransferService::FAILED, $first['status']);
        $this->assertSame('CHANNEL_CLOSED', $first['error_code']);
        $this->assertHasEvent($ctx['session'], EventType::FILE_TRANSFER_FAILED);

        $second = $service->abort($ctx['session'], (string) $offered['uuid'], $ctx['host'], 'FAILED', 'CHANNEL_CLOSED');
        $this->assertSame(FileTransferService::FAILED, $second['status']);
    }

    public function testTransfersAreRefusedOnceTheSessionHasEnded(): void
    {
        $ctx = $this->sessionWithTwo(481);

        Services::sessionService()->end($ctx['session'], $ctx['hostUser']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This Remote session has already finished.');

        Services::fileTransferService()->offer(
            $this->reload($ctx['session']),
            $ctx['host'],
            (string) $ctx['viewer']['uuid'],
            'notes.txt',
            512,
            null,
            $this->policyFor($ctx),
        );
    }
}
