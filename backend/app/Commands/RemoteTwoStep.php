<?php

namespace App\Commands;

use App\Domain\TwoStep\SecretCipher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * php spark remote:two-step status|reset|rekey
 *
 * The operator's side of Remote's two-step verification (docs/SECURITY.md):
 *
 *   status                       whether this server can seal secrets, which key it would use, and how many people
 *                                have two-step verification on — and whether any of their secrets is sealed under a
 *                                key this server no longer holds. Prints a key's public id, never a key.
 *   reset <uuid|email> --reason "…"
 *                                AICOUNTLY support's last resort for somebody who has lost their phone AND their
 *                                recovery codes: switches it off for them, audited as the system with the reason, so
 *                                they can set it up again. Confirm who is asking before running it — this is the one
 *                                way round the second step, and the person on the phone may not be the account holder.
 *   rekey                        re-seals every stored secret under the current key, so the old secret can be taken
 *                                out of remote.twoStepPreviousKeys after a rotation.
 *
 * Exit code 0 on success, 1 when there was nothing to do or something is wrong.
 */
class RemoteTwoStep extends BaseCommand
{
    protected $group       = 'Remote';
    protected $name        = 'remote:two-step';
    protected $description = 'Two-step verification: key status, a support reset, or re-sealing secrets after a key rotation.';
    protected $usage       = 'remote:two-step status | reset <platform uuid or e-mail> --reason "<why>" | rekey';

    public function run(array $params)
    {
        $action = $params[0] ?? 'status';

        return match ($action) {
            'status' => $this->status(),
            // `--reason` arrives in $params when run through command(), and as a CLI option from a shell.
            'reset'  => $this->reset((string) ($params[1] ?? ''), (string) ($params['reason'] ?? CLI::getOption('reason') ?? '')),
            'rekey'  => $this->rekey(),
            default  => $this->usageError(),
        };
    }

    private function status(): int
    {
        $config = Services::remoteConfig();
        $cipher = new SecretCipher($config);
        $db     = db_connect();

        CLI::write('Remote two-step verification');
        CLI::write(sprintf('  %-26s %s', 'key source', $config->twoStepKey !== '' ? 'remote.twoStepKey' : 'derived from remote.signallingSecret'));
        CLI::write(sprintf('  %-26s %s', 'can seal new secrets', $cipher->available() ? CLI::color('yes', 'green') : CLI::color('NO — set remote.twoStepKey (32+ characters)', 'red')));
        CLI::write(sprintf('  %-26s %s', 'current key id', $cipher->currentKeyId() ?? '—'));

        $rows = $db->query('SELECT key_id, COUNT(*) AS n, COUNT(confirmed_at) AS on_count FROM remote_user_two_step GROUP BY key_id')->getResultArray();
        $unreadable = 0;
        $on         = 0;
        foreach ($rows as $row) {
            $on += (int) $row['on_count'];
            $readable = $cipher->canOpen((string) $row['key_id']);
            if (! $readable) {
                $unreadable += (int) $row['n'];
            }
            CLI::write(sprintf('  %-26s %d (%s)', 'sealed under ' . $row['key_id'], (int) $row['n'], $readable
                ? ($row['key_id'] === $cipher->currentKeyId() ? 'current key' : 'a previous key — run rekey')
                : CLI::color('a key this server does not hold', 'red')));
        }
        CLI::write(sprintf('  %-26s %d', 'people with it on', $on));

        if ($unreadable > 0) {
            CLI::error("{$unreadable} secret(s) cannot be opened: add the secret they were sealed under to remote.twoStepPreviousKeys. Until then those people can still pass with a recovery code.");

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }

    private function reset(string $who, string $reason): int
    {
        if ($who === '' || trim($reason) === '') {
            return $this->usageError();
        }

        $row = db_connect()->table('remote_identities')
            ->select('id, platform_uuid, display_name, email')
            ->groupStart()->where('platform_uuid', $who)->orWhere('LOWER(email)', strtolower($who))->groupEnd()
            ->get()
            ->getResultArray();

        if (count($row) !== 1) {
            CLI::error(count($row) === 0 ? 'Nobody with that uuid or e-mail.' : 'More than one person has that e-mail: give the platform uuid instead.');

            return EXIT_ERROR;
        }

        $person = $row[0];
        if (! Services::twoStep()->reset((int) $person['id'], $reason)) {
            CLI::write(sprintf('%s (%s) does not have two-step verification on. Nothing changed.', $person['display_name'], $person['platform_uuid']));

            return EXIT_ERROR;
        }

        CLI::write(CLI::color(sprintf('Two-step verification is off for %s (%s). It is recorded in the audit trail.', $person['display_name'], $person['platform_uuid']), 'green'));

        return EXIT_SUCCESS;
    }

    private function rekey(): int
    {
        try {
            $counts = Services::twoStep()->rekeyAll();
        } catch (\RuntimeException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write(sprintf('Re-sealed %d, already current %d, unreadable %d.', $counts['resealed'], $counts['current'], $counts['unreadable']));

        return $counts['unreadable'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }

    private function usageError(): int
    {
        CLI::error('Usage: php spark ' . $this->usage);

        return EXIT_ERROR;
    }
}
