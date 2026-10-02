<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Directory\ManageClient;

/**
 * Stands in for Aicountly Manage during feature tests.
 *
 * Answers per `ses_key`: which companies that session may open, and (for the
 * member directory) who belongs to a company. `down()` makes every question go
 * unanswered, which is how a test asserts that an outage neither grants nor
 * removes anything. The contract itself — what a 404, a 403 or a 200 without
 * `ownership` means — is exercised against a real HTTP stand-in in
 * ManageClientContractTest; this class stands in one layer above it.
 */
final class FakeManageClient extends ManageClient
{
    /** @var array<string, array<int, string>> ses_key => [company id => name] */
    private array $access = [];

    /** @var array<int, list<array{uuid: string, name: string, email: ?string}>> */
    private array $directory = [];

    private bool $down = false;

    /** @var list<string> */
    public array $asked = [];

    public function __construct()
    {
    }

    public function allow(string $sesKey, int $companyId, string $name = ''): void
    {
        $this->access[$sesKey][$companyId] = $name;
    }

    public function revoke(string $sesKey, int $companyId): void
    {
        unset($this->access[$sesKey][$companyId]);
    }

    public function down(bool $down = true): void
    {
        $this->down = $down;
    }

    /** @param list<array{uuid: string, name: string, email: ?string}> $members */
    public function setMembers(int $companyId, array $members): void
    {
        $this->directory[$companyId] = $members;
    }

    public function companyAccess(string $sesKey, int $companyId): array
    {
        $this->asked[] = 'companyinfo:' . $companyId;
        if ($this->down) {
            return ['state' => self::UNAVAILABLE, 'name' => null];
        }

        return isset($this->access[$sesKey][$companyId])
            ? ['state' => self::GRANTED, 'name' => $this->access[$sesKey][$companyId] ?: null]
            : ['state' => self::DENIED, 'name' => null];
    }

    public function accessibleCompanies(string $sesKey): ?array
    {
        $this->asked[] = 'companies';

        return $this->down ? null : ($this->access[$sesKey] ?? []);
    }

    public function companyMembers(string $sesKey, int $companyId): array
    {
        $this->asked[] = 'share:' . $companyId;
        if ($this->down) {
            return ['state' => self::UNAVAILABLE, 'members' => []];
        }
        if (! isset($this->access[$sesKey][$companyId])) {
            return ['state' => self::DENIED, 'members' => []];
        }

        return ['state' => self::GRANTED, 'members' => $this->directory[$companyId] ?? []];
    }
}
