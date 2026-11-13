<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark remote:sessions-sweep` — close the sessions whose time ran out while nobody was looking.
 *
 * A session ends when somebody ends it, and otherwise when it is next read after its time is up: the read
 * notices, marks it expired and closes out everything in it. That is enough for a session somebody comes
 * back to. One that nobody opens again — a tab closed, a browser that crashed, a laptop shut — stayed
 * "waiting" or "active" on the record indefinitely: the dashboard counted it as active, its participants
 * stayed "joined", the relay capacity booked for it stayed booked, and a computer linked to it as a control
 * companion was refused as "already in use in another Remote session" by the next one.
 *
 * This is the read that nobody made. Run every five minutes (bin/ops/jobs.txt). It closes nothing a read
 * would not have closed, and takes the same path to do it.
 */
class RemoteSessionsSweep extends BaseCommand
{
    protected $group       = 'Remote';
    protected $name        = 'remote:sessions-sweep';
    protected $description = 'Close sessions whose time ran out and that nobody has opened since.';
    protected $usage       = 'remote:sessions-sweep [--limit 500]';
    protected $options     = ['--limit' => 'The most to close in one run (default 500); the next run takes the rest.'];

    public function run(array $params)
    {
        $limit = (int) ($params['limit'] ?? CLI::getOption('limit') ?? 500);
        $limit = max(1, min(5000, $limit));

        $closed = Services::sessionService()->sweepExpired($limit);

        CLI::write(sprintf('%d overdue session%s closed.', $closed, $closed === 1 ? '' : 's'));

        return EXIT_SUCCESS;
    }
}
