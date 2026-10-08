<?php

namespace App\Commands;

use App\Support\ConsoleDatabaseDetails;
use App\Support\ConsoleDatabaseDetailsException;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark remote:db-check
 *
 * Where does Remote's database connection come from, and does it connect? Run it on the server after a deploy, or
 * whenever /api/health says the database is unavailable. It does what the API does (Config\Database), one step at a
 * time, and says which step failed and why. The split is Connect's:
 *
 *   - the database NAME and USERNAME come from Console (GET /database-details/resolve with CONSOLE_DB_DETAILS_KEY),
 *     asked right now with the cache bypassed;
 *   - the HOST, PORT, PASSWORD and schema come from this server's api/.env (database.default.*).
 *
 * Nothing secret is printed: not CONSOLE_DB_DETAILS_KEY, and not the database password (only where it comes from).
 * Every Console or configuration failure prints a REASON and what to do about it; /api/health reports the same reason.
 * Read-only: unlike `spark migrate:status` it creates nothing, not even the migrations table.
 * Exit code 0 when the database is reachable and fully migrated, 1 when it is not.
 */
class RemoteDbCheck extends BaseCommand
{
    protected $group       = 'Remote';
    protected $name        = 'remote:db-check';
    protected $description = 'Show where the database name/username come from (Console or .env) and whether the connection works.';
    protected $usage       = 'remote:db-check';

    public function run(array $params)
    {
        $say = static function (string $label, string $value, string $color = 'white'): void {
            CLI::write(sprintf('  %-26s ', $label) . CLI::color($value, $color));
        };

        CLI::write('Remote database check');

        if (ConsoleDatabaseDetails::isConfigured()) {
            $say('Console', 'configured (CONSOLE_API_URL and CONSOLE_DB_DETAILS_KEY are set)', 'green');

            try {
                // Fresh: forget the cache, so this is what the next request after the cache expires will see.
                $identity = ConsoleDatabaseDetails::refresh();
            } catch (ConsoleDatabaseDetailsException $e) {
                $say('Console answered', 'NO', 'red');
                CLI::newLine();
                CLI::error('FAILED at Console: ' . $e->getMessage());
                CLI::error('Reason: ' . $e->category . '. ' . ConsoleDatabaseDetails::hint($e->category));
                CLI::error('Every request that needs the database fails until this is fixed.');

                return EXIT_ERROR;
            }

            $say('Console answered', 'yes (cache bypassed)', 'green');
            $say('  database name / user', $identity['name'] . ' / ' . $identity['user']);
            $say('connection settings', 'host, port, password and schema from api/.env (database.default.*); name and user from Console');
            $group = (array) (new \Config\Database())->default; // Config\Database applied Console's answer (just fetched, so cached)
        } else {
            // Console is a pair of settings: say which half is missing, because with only one of them Console is silently
            // never asked and database.default.database / .username decide.
            $reason = ConsoleDatabaseDetails::notUsedReason();
            $say('Console', 'NOT used' . ($reason === null ? ': neither CONSOLE_API_URL nor CONSOLE_DB_DETAILS_KEY is set' : ': ' . ($reason === 'console_key_missing' ? 'CONSOLE_DB_DETAILS_KEY' : 'CONSOLE_API_URL') . ' is not set'), 'yellow');
            if ($reason !== null) {
                $say('  what to set', ConsoleDatabaseDetails::hint($reason), 'yellow');
            }
            $group = $this->localGroup();
            $say('  database name / user', (trim((string) ($group['database'] ?? '')) !== '' ? $group['database'] : '(unset)') . ' / ' . (trim((string) ($group['username'] ?? '')) !== '' ? $group['username'] : '(unset)'));
            $say('connection settings', 'all from api/.env (database.default.*)');
        }

        $say('  host / port / schema', ($group['hostname'] ?? '') . ' / ' . ($group['port'] ?? '') . ' / ' . ($group['schema'] ?? 'public'));
        $say('  password', trim((string) ($group['password'] ?? '')) !== '' ? 'set in api/.env' : 'NOT SET (database.default.password in api/.env)', trim((string) ($group['password'] ?? '')) !== '' ? 'white' : 'red');

        try {
            // DBDebug on for this one connection: a refused connection must throw here, with the driver's reason, rather
            // than be left to fail on the first query as the API's own (DBDebug off) group does.
            $db  = \Config\Database::connect(array_merge($group, ['DBDebug' => true]), false);
            $row = $db->query('SELECT current_database() AS db, current_user AS usr')->getRowArray();
            $say('connected', 'yes - database ' . ($row['db'] ?? '?') . ', as ' . ($row['usr'] ?? '?'), 'green');

            $table   = (string) config('Migrations')->table;
            $tracked = $db->query('SELECT to_regclass(?) IS NOT NULL AS t', ['public.' . $table])->getRowArray();
            $shipped = array_values(array_unique(array_map(static fn (object $m): string => (string) $m->version, array_values(service('migrations')->findMigrations()))));
            sort($shipped);
            if (! in_array($tracked['t'] ?? false, [true, 't', 1, '1'], true)) {
                $say('migrations applied', 'none: ' . $table . ' does not exist', 'red');
                CLI::newLine();
                CLI::error('NOT READY: connected, but the database has not been migrated.');
                CLI::error('Reason: not_migrated. On the server, from the api/ folder, run: php spark migrate');

                return EXIT_ERROR;
            }
            // As `spark migrate` itself reads it: a migration recorded under any connection group counts as run.
            $applied = array_map(static fn (array $r): string => (string) $r['version'], $db->query('SELECT version FROM "' . $table . '"')->getResultArray());
            $pending = array_values(array_diff($shipped, $applied));
            $say('migrations applied', (count($shipped) - count($pending)) . ' of ' . count($shipped) . ' shipped with this code');
            if ($pending !== []) {
                $say('migrations pending', implode(', ', $pending), 'red');
                CLI::newLine();
                CLI::error('NOT READY: connected, but ' . count($pending) . ' migration(s) are pending.');
                CLI::error('Reason: migrations_pending. On the server, from the api/ folder, run: php spark migrate');

                return EXIT_ERROR;
            }
        } catch (\Throwable $e) {
            $say('connected', 'NO', 'red');
            CLI::newLine();
            CLI::error('FAILED connecting: ' . $e->getMessage());
            $console = ConsoleDatabaseDetails::diagnose($e);
            if ($console !== null) {
                CLI::error('Reason: ' . $console['reason'] . '. ' . $console['hint']);
            } elseif (! ConsoleDatabaseDetails::isConfigured() && ($unused = ConsoleDatabaseDetails::diagnoseUnused($group)) !== null) {
                CLI::error('Reason: ' . $unused['reason'] . '. ' . $unused['hint']);
            } elseif (trim((string) ($group['password'] ?? '')) === '') {
                CLI::error('database.default.password is not set in api/.env. Console does not hold the password: set the hostname, port and password there (the password must belong to the database user Console names for this row).');
            }

            return EXIT_ERROR;
        }

        CLI::newLine();
        CLI::write('OK: Remote can reach its database, and it is fully migrated.', 'green');

        return EXIT_SUCCESS;
    }

    /**
     * The default group as .env gives it. When Console is not used and nothing names a database, Config\Database's
     * constructor throws (so the commands that connect say why), and this one has to report on exactly that state.
     *
     * @return array<string, mixed>
     */
    private function localGroup(): array
    {
        try {
            return (array) (new \Config\Database())->default;
        } catch (\Throwable) {
            return [
                'database' => '',
                'username' => '',
                'hostname' => (string) env('database.default.hostname', ''),
                'port'     => (string) env('database.default.port', ''),
                'password' => (string) env('database.default.password', ''),
                'schema'   => (string) env('database.default.schema', 'public'),
            ];
        }
    }
}
