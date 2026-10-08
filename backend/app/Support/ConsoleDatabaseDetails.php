<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Remote's database name and database username, from Console.
 *
 * Console's "SaaS Database Details" page is the single place those two values
 * (and the cPanel username) are recorded for every product. This asks it for
 * Remote's own row:
 *
 *   GET {CONSOLE_API_URL}/database-details/resolve
 *   Authorization: Bearer {CONSOLE_DB_DETAILS_KEY}
 *
 * CONSOLE_API_URL is the Console API base INCLUDING "/api"
 * (https://console.aicountly.org/api). The key belongs to ONE row (Remote + one
 * environment), so there is no product or domain parameter to send and it cannot read
 * another product's details. It is NOT CONSOLE_SERVICE_KEY: that is another credential,
 * and this endpoint rejects it.
 *
 * WHAT IS NOT HERE. A database password: Console never stores one, so
 * database.default.password / hostname / port / DBDriver / schema stay in
 * api/.env. The database name and username there (database.default.database /
 * .username / .DSN) are not used while both Console variables are set.
 *
 * WHO USES IT. Config\Database applies it to the default group, so models,
 * db_connect(), the health check and every CLI entry point that boots
 * CodeIgniter (spark migrate, the workers) get the resolved values.
 *
 * CACHING. The connection is opened on nearly every request, so asking Console
 * each time would put a network hop in front of every call and make Console a
 * single point of failure for Remote. The answer is held in process memory and
 * in a small file in the system temp directory for FRESH_TTL seconds. The file
 * holds two identifiers and a timestamp, no secret, no key - its name is a hash
 * of URL + key, the key itself is never written. If Console cannot be reached
 * (transport error, 5xx, 429) the last good answer is used for up to STALE_TTL
 * seconds and the outage is logged. A definite refusal (401 revoked key, 403
 * inactive row), an unreadable answer, an unsafe identifier or a key from the
 * wrong environment is NEVER papered over with a cached answer: revoking a key
 * in Console has to actually stop Remote from using it.
 *
 * Failures carry a category (categories()) and a fixed sentence saying what to do
 * (hint()), chosen from the category and never built from anything Console sent,
 * so the unauthenticated /api/health can show them. No message here ever contains
 * the key.
 */
final class ConsoleDatabaseDetails
{
    private const FRESH_TTL = 300;
    private const STALE_TTL = 604800;
    private const FAILURE_TTL = 15;
    private const TIMEOUT = 4;
    private const CONNECT_TIMEOUT = 2;
    private const MAX_RESPONSE_BYTES = 65536;
    private const IDENTIFIER = '/^[A-Za-z0-9_.$-]{1,128}$/D';

    /** @var array<string, string> category => what to do about it */
    private const HINTS = [
        'console_key_missing' => 'CONSOLE_API_URL is set but CONSOLE_DB_DETAILS_KEY is not, so Console is never asked for the database name and username. Generate a key for this deployment in Console > SaaS Database Details (it starts with sdb_) and set it as CONSOLE_DB_DETAILS_KEY in api/.env. CONSOLE_SERVICE_KEY is another key and is not used for this.',
        'console_url_missing' => 'CONSOLE_DB_DETAILS_KEY is set but CONSOLE_API_URL is not. Set CONSOLE_API_URL to the Console API base including /api (https://console.aicountly.org/api) in api/.env.',
        'console_config' => 'CONSOLE_API_URL or CONSOLE_DB_DETAILS_KEY in api/.env is malformed: the URL must start with https:// and the key must have no spaces or line breaks.',
        'console_key_rejected' => 'Console rejected CONSOLE_DB_DETAILS_KEY (revoked, rotated or wrong). Generate a key for this deployment in Console > SaaS Database Details and put it in api/.env.',
        'console_row_inactive' => 'Console reports this product\'s database row as inactive. Activate it in Console > SaaS Database Details.',
        'console_unreachable' => 'This server could not reach Console to ask for the database name and username. Check CONSOLE_API_URL and that the server may make outbound HTTPS calls.',
        'console_unexpected_answer' => 'Console answered, but not with a usable database name and username. Check this product\'s row in Console > SaaS Database Details.',
        'console_no_database_recorded' => 'Console has no database name and username recorded for this product. Record them on its row in Console > SaaS Database Details.',
        'console_environment_mismatch' => 'The Console key belongs to the other environment (production vs sandbox). Use the key generated on this deployment\'s own row, or set REMOTE_ENVIRONMENT=production|sandbox in api/.env if this server is the other environment.',
    ];

    /** @var array{id: string, name: string, user: string, expires: int}|null */
    private static ?array $memo = null;

    /** @var array{id: string, message: string, category: string, expires: int}|null */
    private static ?array $failure = null;

    /** @var (callable(string): void)|null */
    private static $logger = null;

    /** True when this deployment is set up to take its database name/user from Console. */
    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && self::key() !== '';
    }

    /** Where the database name and username come from: "console" or "env" (database.default.* in api/.env). */
    public static function source(): string
    {
        return self::isConfigured() ? 'console' : 'env';
    }

    /**
     * Why Console is NOT being asked although one of its two settings is there: 'console_key_missing'
     * (CONSOLE_API_URL without CONSOLE_DB_DETAILS_KEY) or 'console_url_missing' (the key without the URL), else null.
     *
     * CONSOLE_API_URL alone may be a leftover on an Remote host, so this is never logged on its own: it is what
     * a failed connection reports when the database name and username are not set locally either (see
     * diagnoseUnused()), which is the state a deployment is in when its key is under the wrong name and
     * database.default.database / .username have been commented out.
     */
    public static function notUsedReason(): ?string
    {
        $url = self::baseUrl() !== '';
        $key = self::key() !== '';

        return match (true) {
            $url && ! $key => 'console_key_missing',
            ! $url && $key => 'console_url_missing',
            default        => null,
        };
    }

    /**
     * The category and fixed sentence for a failure that came from Console: the exception itself, or the one
     * Config\Database wrapped in a DatabaseException. Null for any other failure.
     *
     * @return array{reason: string, hint: string}|null
     */
    public static function diagnose(Throwable $e): ?array
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof ConsoleDatabaseDetailsException) {
                return ['reason' => $x->category, 'hint' => self::hint($x->category)];
            }
        }

        return null;
    }

    /**
     * For a connection that failed while Console is NOT being asked: when one of its two settings is set and
     * the connection group has no database name or username either, nothing names a database at all, and that
     * (not a driver error) is the thing to report.
     *
     * @param array<string, mixed> $group the connection group the failed connection was built from
     *
     * @return array{reason: string, hint: string}|null
     */
    public static function diagnoseUnused(array $group): ?array
    {
        $reason = self::notUsedReason();
        if ($reason === null || trim((string) ($group['DSN'] ?? '')) !== '' || (trim((string) ($group['database'] ?? '')) !== '' && trim((string) ($group['username'] ?? '')) !== '')) {
            return null;
        }

        return ['reason' => $reason, 'hint' => self::hint($reason)];
    }

    /** @return list<string> every category a failure can have (the hint() keys) */
    public static function categories(): array
    {
        return array_keys(self::HINTS);
    }

    /** The fixed sentence for a category; a category this class does not know has none (''). */
    public static function hint(string $category): string
    {
        return self::HINTS[$category] ?? '';
    }

    /**
     * The database group with Console's name and username in place of whatever
     * api/.env said. DSN is cleared: a DSN carries its own dbname and user, and
     * would win over the two fields.
     *
     * @param array<string, mixed> $group a Config\Database connection group
     *
     * @return array<string, mixed>
     *
     * @throws ConsoleDatabaseDetailsException when Console cannot give usable details
     */
    public static function applyTo(array $group): array
    {
        $details = self::resolve();

        $group['database'] = $details['name'];
        $group['username'] = $details['user'];
        $group['DSN']      = '';

        return $group;
    }

    /**
     * Ask Console again, ignoring every cache (memory and the shared file), then use and cache what it says.
     * For `spark remote:db-check`: it shows what the next request after the cache expires will get.
     *
     * @return array{name: string, user: string}
     *
     * @throws ConsoleDatabaseDetailsException when Console cannot give usable details
     */
    public static function refresh(): array
    {
        self::$memo    = null;
        self::$failure = null;
        if (is_file(self::filePath())) {
            @unlink(self::filePath());
        }

        return self::resolve();
    }

    /**
     * @return array{name: string, user: string}
     *
     * @throws ConsoleDatabaseDetailsException when Console cannot give usable details
     */
    public static function resolve(): array
    {
        $id = self::id();

        if (self::$memo !== null && self::$memo['id'] === $id && self::$memo['expires'] > time()) {
            return ['name' => self::$memo['name'], 'user' => self::$memo['user']];
        }
        // A failed lookup is remembered briefly so one request that connects
        // more than once does not wait out the timeout each time.
        if (self::$failure !== null && self::$failure['id'] === $id && self::$failure['expires'] > time()) {
            throw new ConsoleDatabaseDetailsException(self::$failure['message'], self::$failure['category']);
        }

        $cached = self::readFile();
        if ($cached !== null && $cached['fetched_at'] + self::FRESH_TTL > time()) {
            return self::remember($id, $cached['name'], $cached['user'], $cached['fetched_at'] + self::FRESH_TTL);
        }

        $answer = self::fetch();

        if ($answer['ok']) {
            self::writeFile($answer['name'], $answer['user']);

            return self::remember($id, $answer['name'], $answer['user'], time() + self::FRESH_TTL);
        }

        // Console was unreachable or broken (not a refusal): carry on with the
        // last good answer rather than take Remote down with it.
        if ($answer['retryable'] && $cached !== null && $cached['fetched_at'] + self::STALE_TTL > time()) {
            self::log('Console database-details unavailable (' . $answer['message'] . ') - using the last details it gave, from '
                . gmdate('c', $cached['fetched_at']) . '.');

            return self::remember($id, $cached['name'], $cached['user'], time() + self::FAILURE_TTL);
        }

        self::$memo    = null;
        self::$failure = ['id' => $id, 'message' => $answer['message'], 'category' => $answer['category'], 'expires' => time() + self::FAILURE_TTL];

        throw new ConsoleDatabaseDetailsException($answer['message'], $answer['category']);
    }

    /** Test seam: forget everything held in memory (and the shared file when asked). */
    public static function resetForTesting(bool $includingFile = false): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$memo    = null;
        self::$failure = null;
        if ($includingFile) {
            @unlink(self::filePath());
        }
    }

    /** Test seam: receive log lines instead of CodeIgniter's logger. */
    public static function useLogger(?callable $logger): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$logger = $logger;
    }

    /** The cache file for the current URL + key (the name carries a hash, never the key). */
    public static function cacheFilePath(): string
    {
        return self::filePath();
    }

    // -----------------------------------------------------------------------

    /**
     * @return array{ok: true, name: string, user: string}|array{ok: false, retryable: bool, message: string, category: string}
     */
    private static function fetch(): array
    {
        $base = self::baseUrl();
        if (preg_match('#^https?://[^\s]+$#i', $base) !== 1) {
            return self::refused('CONSOLE_API_URL must be the Console API base, an http(s) URL including /api (for example https://console.aicountly.org/api).', 'console_config');
        }
        // The key goes into a header: anything but printable ASCII would be a malformed (or injected) header.
        if (preg_match('/^[\x21-\x7e]+$/D', self::key()) !== 1) {
            return self::refused('CONSOLE_DB_DETAILS_KEY contains characters that cannot be sent as a bearer token.', 'console_config');
        }

        $response = self::get($base . '/database-details/resolve', self::key());

        if ($response['status'] < 200 || $response['status'] > 299) {
            $status = $response['status'];
            // The URL is not logged (it is fixed, but anything near a
            // credential stays out of logs); the key never is.
            $detail = $status === 0 ? 'Console could not be reached' : 'Console answered HTTP ' . $status;

            return match (true) {
                $status === 401 => self::refused('Console rejected CONSOLE_DB_DETAILS_KEY (revoked, rotated or wrong) - generate a key for this deployment in Console > SaaS Database Details.', 'console_key_rejected'),
                $status === 403 => self::refused('Console reports the database details for this product as inactive - activate the row in Console > SaaS Database Details.', 'console_row_inactive'),
                $status === 0 || $status === 429 || $status >= 500 => ['ok' => false, 'retryable' => true, 'message' => 'Could not fetch database details from Console: ' . $detail . '.', 'category' => 'console_unreachable'],
                default => self::refused('Could not fetch database details from Console: ' . $detail . '.', 'console_unexpected_answer'),
            };
        }

        $body = json_decode($response['body'], true);
        $data = is_array($body) && ($body['success'] ?? true) !== false && is_array($body['data'] ?? null) ? $body['data'] : null;
        if ($data === null) {
            return self::refused('Console answered the database-details request with something that is not the expected JSON.', 'console_unexpected_answer');
        }

        $name = is_string($data['database_name'] ?? null) ? trim($data['database_name']) : '';
        $user = is_string($data['database_username'] ?? null) ? trim($data['database_username']) : '';
        if ($name === '' || $user === '') {
            return self::refused('Console has no database name / username recorded for Remote.', 'console_no_database_recorded');
        }
        // Both go into a connection string and a login. A ";" or "=" in a value
        // would add connection parameters rather than name a database, so
        // anything but a plain identifier is refused instead of passed on.
        if (preg_match(self::IDENTIFIER, $name) !== 1 || preg_match(self::IDENTIFIER, $user) !== 1) {
            return self::refused('Console returned a database name or username with characters Remote will not put in a connection string.', 'console_unexpected_answer');
        }

        // The key is per row, so a Production key in a sandbox .env (or the
        // reverse) would quietly point this deployment at the other
        // environment's database. The check is made only when BOTH sides name
        // an environment (production/prod or sandbox/staging) and they differ:
        // a row that names none, or a deployment this server cannot place
        // (local, development, testing), is never turned into a refusal.
        $appEnv = self::appEnvironment();
        $rowEnv = self::normalizeEnvironment(is_string($data['environment'] ?? null) ? $data['environment'] : '');
        if ($appEnv !== '' && $rowEnv !== '' && $rowEnv !== $appEnv) {
            return self::refused(sprintf(
                'CONSOLE_DB_DETAILS_KEY belongs to Remote\'s %s database but this deployment is %s - use the key generated on the matching row in Console, or set REMOTE_ENVIRONMENT=production|sandbox in api/.env if this server is the other environment.',
                $rowEnv,
                $appEnv,
            ), 'console_environment_mismatch');
        }

        return ['ok' => true, 'name' => $name, 'user' => $user];
    }

    /**
     * One GET. Plain curl: connect 2 s, total 4 s, body capped, no redirects,
     * http/https only. Status 0 means no HTTP answer at all.
     *
     * @return array{status: int, body: string}
     */
    private static function get(string $url, string $key): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }

        $body = '';
        $over = false;
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            // Never: the request carries a credential, and a 30x to another
            // host would hand it to whoever the redirect names.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL       => true,
            // Stop reading once the cap is passed (returning less than the
            // chunk aborts the transfer).
            CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$body, &$over): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $over = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $done   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($over) {
            // An answer did arrive; it is just not one we will read. The
            // status still decides refusal vs outage, the body is dropped.
            return ['status' => $status, 'body' => ''];
        }
        if ($done === false) {
            return ['status' => 0, 'body' => ''];
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * What this deployment is: 'production', 'sandbox', or '' when it is not one of those (unset, local,
     * development, testing - never checked).
     *
     * REMOTE_ENVIRONMENT when the operator set it; otherwise the host of app.baseURL, which every deploy pins from
     * the site's own host (cpanel-post-deploy-api.sh), so it names the deployment without anyone having to set a
     * second variable. CodeIgniter's CI_ENVIRONMENT is no help: a sandbox server runs it as "production" too.
     */
    private static function appEnvironment(): string
    {
        $explicit = self::normalizeEnvironment(self::env('REMOTE_ENVIRONMENT'));
        if ($explicit !== '') {
            return $explicit;
        }

        $host = strtolower((string) (parse_url(self::env('app.baseURL'), PHP_URL_HOST) ?: ''));
        if ($host === '' || $host === 'localhost' || str_starts_with($host, '127.')) {
            return '';
        }
        if (preg_match('/^[a-z0-9-]+\.gh\.aicountly\.(com|org)$/', $host) === 1 || preg_match('/^gh-[a-z0-9-]+\.aicountly\.(com|org)$/', $host) === 1) {
            return 'sandbox';
        }
        if (preg_match('/^[a-z0-9-]+\.aicountly\.(com|org)$/', $host) === 1) {
            return 'production';
        }

        return '';
    }

    private static function normalizeEnvironment(string $name): string
    {
        return match (strtolower(trim($name))) {
            'production', 'prod' => 'production',
            'sandbox', 'staging' => 'sandbox',
            default              => '',
        };
    }

    /** @return array{ok: false, retryable: bool, message: string, category: string} */
    private static function refused(string $message, string $category): array
    {
        return ['ok' => false, 'retryable' => false, 'message' => $message, 'category' => $category];
    }

    /** @return array{name: string, user: string} */
    private static function remember(string $id, string $name, string $user, int $expires): array
    {
        self::$memo    = ['id' => $id, 'name' => $name, 'user' => $user, 'expires' => $expires];
        self::$failure = null;

        return ['name' => $name, 'user' => $user];
    }

    private static function log(string $message): void
    {
        $line = '[remote-db] ' . $message;
        if (self::$logger !== null) {
            (self::$logger)($line);

            return;
        }
        if (function_exists('log_message')) {
            log_message('error', $line);

            return;
        }
        error_log($line);
    }

    /**
     * An environment value as a trimmed string, '' when unset. The same three places CodeIgniter's env() reads, so it
     * also works in the pure unit tests.
     */
    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? trim($value, " \t\n\r\0\x0B\"'") : '';
    }

    private static function baseUrl(): string
    {
        return rtrim(self::env('CONSOLE_API_URL'), '/');
    }

    private static function key(): string
    {
        return self::env('CONSOLE_DB_DETAILS_KEY');
    }

    /** A hash of URL + key: a rotated key or a different Console never reads an old answer. */
    private static function id(): string
    {
        return substr(hash('sha256', self::baseUrl() . '|' . self::key()), 0, 32);
    }

    /** The key itself is not in the name. */
    private static function filePath(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'remote-console-db-' . self::id() . '.json';
    }

    /** @return array{name: string, user: string, fetched_at: int}|null */
    private static function readFile(): ?array
    {
        $path = self::filePath();
        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        // Another account sharing /tmp could have planted a file under this name; only ours is believed.
        if (function_exists('posix_geteuid') && @fileowner($path) !== posix_geteuid()) {
            return null;
        }
        $raw  = @file_get_contents($path, false, null, 0, 4096);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (
            ! is_array($data)
            || ! is_string($data['name'] ?? null) || ! is_string($data['user'] ?? null)
            || ! is_int($data['fetched_at'] ?? null)
            // Same rule as a fresh answer: a doctored file cannot smuggle in
            // anything a connection string should not carry.
            || preg_match(self::IDENTIFIER, $data['name']) !== 1 || preg_match(self::IDENTIFIER, $data['user']) !== 1
            || $data['fetched_at'] > time() + 60
        ) {
            return null;
        }

        return ['name' => $data['name'], 'user' => $data['user'], 'fetched_at' => $data['fetched_at']];
    }

    private static function writeFile(string $name, string $user): void
    {
        $path    = self::filePath();
        $tmp     = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $payload = json_encode(['name' => $name, 'user' => $user, 'fetched_at' => time()]);
        if ($payload === false) {
            return;
        }

        // Best effort: an unwritable temp dir only costs a Console call per
        // request, it must never fail the request. Owner-only from the first
        // byte (umask), created exclusively (no symlink followed), and moved
        // into place atomically so a reader never sees half a file.
        $previous = umask(0077);

        try {
            $fh = @fopen($tmp, 'xb');
            if ($fh === false) {
                return;
            }
            $written = fwrite($fh, $payload);
            fclose($fh);
            @chmod($tmp, 0600);
            if ($written !== strlen($payload) || ! @rename($tmp, $path)) {
                @unlink($tmp);
            }
        } catch (Throwable) {
            // A failing disk costs a Console call next time; it never fails the request.
        } finally {
            umask($previous);
        }
    }
}
