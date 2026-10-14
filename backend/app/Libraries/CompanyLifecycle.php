<?php

declare(strict_types=1);

namespace App\Libraries;

use Aicountly\Lifecycle\Kit;
use App\Domain\Support\ApiException;
use CodeIgniter\HTTP\IncomingRequest;
use Config\Database;
use PDO;

/**
 * Manage's company lifecycle in Remote, through the vendored receiver kit (lib/company-lifecycle, kit
 * README; contract: manage-aicountly docs/company-lifecycle/README.md; docs/COMPANY_LIFECYCLE.md).
 *
 *   - receiver:   POST /api/internal/manage/company-lifecycle (Api\CompanyLifecycleController) — no
 *                 filter at all: the HMAC (LIFECYCLE_WEBHOOK_SECRET) is its only credential.
 *   - enforce():  the write gate. EffectivePolicyResolver::resolve() — where every company action is
 *                 checked against the person's membership (a stranger gets 403 COMPANY_ACCESS_DENIED
 *                 there first) — calls it for the company. While the company's Remote subscription has
 *                 lapsed, a write that is not in ALLOW answers 423 COMPANY_RESTRICTED (ApiException,
 *                 `details.restricted_until`). Reads pass, and so do the writes that only end, leave,
 *                 stop or reduce something, and device/agent presence.
 *   - syncFromCompanyInfo(): Manage's companyinfo is the source of truth; ManageClient::companyAccess()
 *                 writes it through whenever it asks.
 *   - purgeAccount(): the kit's account hook (see config/lifecycle-manifest.php).
 *   - kit():      the kit on a PDO built from Config\Database's resolved group (database name and user
 *                 from Console when CONSOLE_DB_DETAILS_KEY is set) and the CodeIgniter environment. The cron
 *                 worker builds the same kit through config/lifecycle-kit.php.
 */
final class CompanyLifecycle
{
    public const APP_KEY = 'remote';

    /**
     * Company writes that stay open while read-only: ending, leaving, stopping or reducing what is under
     * way, and a device's or participant's own presence. "METHOD /path" (the route path, no /api) with
     * `*` = one segment. tests/Lifecycle/CompanyLifecycleTest.php proves every entry is a real route.
     */
    public const ALLOW = [
        'POST /v1/remote/sessions/*/end',
        'POST /v1/remote/sessions/*/share-stopped',
        'POST /v1/remote/sessions/*/participants/*/leave',
        'POST /v1/remote/sessions/*/participants/*/presence',
        'POST /v1/remote/sessions/*/control/revoke',
        'DELETE /v1/remote/sessions/*/invitations/*',
        'POST /v1/remote/support/requests/*/cancel',
        'POST /v1/remote/devices/*/revoke',
        'POST /v1/remote/devices/*/unattended/disable',
        'POST /v1/remote/devices/me/unattended/disable',
        'POST /v1/remote/devices/me/presence',
    ];

    private static ?Kit $kit = null;

    public static function kit(): Kit
    {
        return self::$kit ??= self::build();
    }

    /** @param array<string, mixed> $options the kit's (hooks, on_finish, http, clock) — tests pass a fake http */
    public static function build(array $options = []): Kit
    {
        $autoload = ROOTPATH . 'lib/company-lifecycle/autoload.php';
        if (! is_file($autoload)) {
            throw new \RuntimeException('The company-lifecycle kit is not deployed (lib/company-lifecycle).');
        }
        require_once $autoload;

        $env = static function (string $name): ?string {
            $value = env($name);

            return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        };

        return Kit::fromEnv(self::APP_KEY, self::pdo(), self::manifestPath(), $env, [], $options + [
            'log'   => [self::class, 'log'],
            'hooks' => ['account' => [[self::class, 'purgeAccount']]],
        ]);
    }

    public static function manifestPath(): string
    {
        return ROOTPATH . 'config/lifecycle-manifest.php';
    }

    public static function resetForTesting(): void
    {
        self::$kit = null;
    }

    /** A PDO on the database Config\Database resolved (Console's name and user when configured). */
    public static function pdo(): PDO
    {
        $config = config(Database::class);
        $group  = $config->{$config->defaultGroup};
        $host   = (string) ($group['hostname'] ?? '127.0.0.1');
        $port   = (int) ($group['port'] ?? 5432);
        $name   = (string) ($group['database'] ?? '');
        $dsn    = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $name);
        $pdo    = new PDO($dsn, (string) ($group['username'] ?? ''), (string) ($group['password'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $schema = (string) ($group['schema'] ?? 'public');
        if ($schema !== '' && $schema !== 'public' && preg_match('/^[a-z_][a-z0-9_]*$/', $schema) === 1) {
            $pdo->exec('SET search_path TO "' . $schema . '", public');
        }

        return $pdo;
    }

    /**
     * The write gate for one company: returns when the request may go ahead, throws the 423 when the
     * company is read-only here. Only HTTP requests are gated (a CLI command acts for the operator), and
     * a lifecycle table that cannot be read lets the request through (the kit logs it).
     */
    public static function enforce(int $companyId): void
    {
        $request = service('request');
        if (! $request instanceof IncomingRequest) {
            return;
        }
        $refusal = self::refusal($companyId, $request->getMethod(), '/' . ltrim($request->getPath(), '/'));
        if ($refusal === null) {
            return;
        }
        $payload = $refusal[1];

        throw new ApiException('COMPANY_RESTRICTED', (string) $payload['message'], $refusal[0], [
            'restricted_until' => $payload['restricted_until'] ?? null,
            'lifecycle'        => $payload['lifecycle'] ?? 'restricted',
        ]);
    }

    /** @return array{0:int, 1:array<string,mixed>}|null */
    public static function refusal(int $companyId, string $method, string $path): ?array
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        try {
            return self::kit()->guard(self::ALLOW)->check($companyId, $method, $path);
        } catch (\Throwable $e) {
            self::log('warning', 'write gate skipped for company ' . $companyId . ': ' . $e->getMessage());

            return null;
        }
    }

    /** Write Manage's companyinfo answer through (only a 200 naming this company is believed). Never throws. */
    public static function syncFromCompanyInfo(int $companyId, int $status, string $body): void
    {
        if ($status !== 200 || $companyId <= 0) {
            return;
        }
        $decoded = json_decode($body, true);
        $data    = is_array($decoded) ? ($decoded['data'] ?? $decoded['company'] ?? $decoded) : null;
        if (! is_array($data) || ! isset($data['lifecycle']) || (int) ($data['comp_id'] ?? $data['cmp_id'] ?? 0) !== $companyId) {
            return;
        }

        try {
            self::kit()->guard()->syncFromCompanyInfo($companyId, $data);
        } catch (\Throwable $e) {
            self::log('warning', 'companyinfo lifecycle not recorded for company ' . $companyId . ': ' . $e->getMessage());
        }
    }

    /** @return array{lifecycle: string, restricted_until: ?string} the enforced state, for the SPA */
    public static function lifecycle(int $companyId): array
    {
        try {
            $row = self::kit()->store()->state($companyId);
        } catch (\Throwable $e) {
            self::log('warning', 'lifecycle state unreadable for company ' . $companyId . ': ' . $e->getMessage());
            $row = null;
        }

        return ['lifecycle' => (string) ($row['state'] ?? 'active'), 'restricted_until' => $row['restricted_until'] ?? null];
    }

    /**
     * account_purge_requested: the person's own data that is not company data, in one transaction.
     *
     * remote_identities (the person, by platform_uuid) is pointed at by the company's records with
     * ON DELETE NO ACTION, so the engine cannot delete it; this hook does what can be done without
     * touching any company's data: deletes the person's PERSONAL sessions (no company — their
     * participants, messages, events, transfers, invitations and feedback cascade), their personal
     * devices and desktop sign-in codes, and anonymises the identity projection (name,
     * e-mail, platform user id). Company sessions and devices stay with their companies.
     *
     * @param array<string, mixed> $result the engine's (empty) user-scope result
     */
    public static function purgeAccount(int|string $uuid, array $result): ?string
    {
        $pdo = self::kit()->pdo();
        $find = $pdo->prepare('SELECT id FROM remote_identities WHERE platform_uuid = :u');
        $find->execute(['u' => (string) $uuid]);
        $ids = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return null;
        }
        $in = implode(',', $ids);

        $pdo->beginTransaction();
        try {
            $counts = [];
            $counts['personal sessions'] = $pdo->exec("DELETE FROM remote_sessions WHERE company_id IS NULL AND scope_type = 'PERSONAL'
                AND (owner_user_id IN ({$in}) OR initiator_user_id IN ({$in}))
                AND NOT EXISTS (SELECT 1 FROM remote_support_requests r WHERE r.session_id = remote_sessions.id AND r.company_id IS NOT NULL)");
            $counts['personal devices'] = $pdo->exec("DELETE FROM remote_devices WHERE company_id IS NULL AND user_id IN ({$in})");
            $counts['desktop sign-in codes'] = $pdo->exec("DELETE FROM remote_desktop_signin_codes WHERE identity_id IN ({$in})");
            $pdo->exec("UPDATE remote_identities SET display_name = 'Former AICOUNTLY user', email = NULL, platform_user_id = NULL, updated_at = now() WHERE id IN ({$in})");
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e; // the kit retries the attempt, then acks failed
        }
        $parts = [];
        foreach ($counts as $what => $n) {
            if ((int) $n > 0) {
                $parts[] = $n . ' ' . $what;
            }
        }

        return 'Remote: ' . ($parts !== [] ? implode(', ', $parts) . ' deleted; ' : '') . 'the identity projection anonymised (company sessions stay with their companies).';
    }

    public static function log(string $level, string $message): void
    {
        $level = in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'], true) ? $level : 'notice';
        log_message($level, 'company-lifecycle: ' . $message);
    }
}
