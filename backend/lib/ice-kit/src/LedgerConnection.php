<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * A lazily opened PostgreSQL connection to the shared capacity ledger.
 *
 * Remote and Connect run in separate accounts, but the TURN nodes they share are one physical resource, so
 * the books that guarantee the paid reserve must be one set of books. This is the connection both open:
 * a dedicated database (`aicountly_turn`), a role that can touch only the ledger's tables, and short
 * timeouts so a slow database costs a request milliseconds instead of hanging it.
 *
 *   WEBRTC_TURN_LEDGER_DSN       = pgsql:host=127.0.0.1;port=5432;dbname=aicountly_turn
 *   WEBRTC_TURN_LEDGER_USER      = turn_ledger
 *   WEBRTC_TURN_LEDGER_PASSWORD  = <secret — injected at deploy, never in a repository>
 */
final class LedgerConnection
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        private readonly int $timeoutMs = 2000,
    ) {
    }

    /** @param callable(string): (string|null) $read */
    public static function fromEnvironment(callable $read): ?self
    {
        $dsn = trim((string) ($read('WEBRTC_TURN_LEDGER_DSN') ?? ''));
        if ($dsn === '' || ! str_starts_with($dsn, 'pgsql:')) {
            return null;
        }

        return new self(
            $dsn,
            trim((string) ($read('WEBRTC_TURN_LEDGER_USER') ?? '')),
            (string) ($read('WEBRTC_TURN_LEDGER_PASSWORD') ?? ''),
            max(200, min(10_000, (int) ($read('WEBRTC_TURN_LEDGER_TIMEOUT_MS') ?: 2000))),
        );
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            if (! extension_loaded('pdo_pgsql')) {
                throw new \RuntimeException('The shared capacity ledger needs the pdo_pgsql extension.');
            }

            $dsn = str_contains($this->dsn, 'connect_timeout') ? $this->dsn : $this->dsn . ';connect_timeout=' . max(1, intdiv($this->timeoutMs, 1000));
            $pdo = new \PDO($dsn, $this->user, $this->password, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Neither a stuck statement nor a long wait for the admission lock may hold a credential request.
            $pdo->exec("SET statement_timeout = {$this->timeoutMs}");
            $pdo->exec("SET lock_timeout = {$this->timeoutMs}");
            $this->pdo = $pdo;
        }

        return $this->pdo;
    }
}
