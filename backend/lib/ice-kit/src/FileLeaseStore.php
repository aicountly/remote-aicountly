<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * The books in one JSON file, serialised across processes by an exclusive `flock` on a sibling lock file.
 *
 * Right for one API host (the shared-hosting shape both products run in): a few thousand live leases is
 * tens of kilobytes, rewritten atomically (write, then rename) so a reader never sees half a file, and
 * the lock is held for the milliseconds a decision takes. If the file cannot be locked or read the
 * caller gets an exception — IceIssuer turns that into "no admission control, serve as before" and logs
 * it, because failing closed would take every relay user down with a full disk.
 */
final class FileLeaseStore implements LeaseStore
{
    /** @var list<string> things the last read had to repair, for the caller to report */
    private array $repairs = [];

    public function __construct(private readonly string $path)
    {
    }

    /**
     * What the last read had to repair (`corrupt_file`, `bad_rows:N`) — empty when the books were sound. A
     * corrupt file is set aside as `<path>.corrupt` and the books start empty: counting nothing is wrong,
     * but refusing to count anything ever again is worse.
     *
     * @return list<string>
     */
    public function repairs(): array
    {
        return $this->repairs;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function transact(\Closure $fn, int $now): mixed
    {
        $lock = $this->lock(LOCK_EX);

        try {
            $book   = new LeaseBook($this->read(), $now);
            $result = $fn($book);

            if ($book->dirty() || $this->repairs !== []) {
                $this->write($book->all());
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function snapshot(int $now): LeaseBook
    {
        // A read-only caller (a status page, a CLI run as another user) may be unable to create or open the
        // lock file. The books are replaced atomically, so reading without the lock is safe — merely not
        // serialised with a writer, which a status view does not need.
        try {
            $lock = $this->lock(LOCK_SH, false);
        } catch (\RuntimeException) {
            return new LeaseBook($this->read(false), $now);
        }

        try {
            return new LeaseBook($this->read(false), $now);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    private function lock(int $mode, bool $create = true)
    {
        $dir = dirname($this->path);
        if ($create && ! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create the TURN lease directory {$dir}.");
        }

        // A reader never creates the lock file: one made by a CLI user could lock the web user out of it.
        if (! $create && ! is_file($this->path . '.lock')) {
            throw new \RuntimeException('No lock file to read under.');
        }

        $handle = @fopen($this->path . '.lock', $create ? 'c' : 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open the TURN lease lock at {$this->path}.");
        }
        if (! flock($handle, $mode)) {
            fclose($handle);
            throw new \RuntimeException("Cannot lock the TURN lease books at {$this->path}.");
        }

        return $handle;
    }

    /** @return array<string, Lease> */
    private function read(bool $repair = true): array
    {
        $this->repairs = [];
        if (! is_file($this->path)) {
            return [];
        }

        $raw  = @file_get_contents($this->path);
        $data = $raw === false || $raw === '' ? null : json_decode($raw, true);
        if (! is_array($data) || ! is_array($data['leases'] ?? null)) {
            if ($raw !== false && $raw !== '') {
                $this->repairs[] = 'corrupt_file';
                if ($repair) {
                    @rename($this->path, $this->path . '.corrupt');
                }
            }

            return [];
        }

        $leases = [];
        $bad    = 0;
        foreach ($data['leases'] as $key => $row) {
            if (is_array($row) && ($lease = Lease::fromArray((string) $key, $row)) !== null) {
                $leases[(string) $key] = $lease;
            } else {
                $bad++;
            }
        }
        if ($bad > 0) {
            $this->repairs[] = "bad_rows:{$bad}";
        }

        return $leases;
    }

    /** @param array<string, Lease> $leases */
    private function write(array $leases): void
    {
        $rows = [];
        foreach ($leases as $key => $lease) {
            $rows[$key] = $lease->toArray();
        }

        $tmp  = $this->path . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
        $json = json_encode(['version' => 1, 'leases' => $rows], JSON_UNESCAPED_SLASHES);

        if ($json === false || @file_put_contents($tmp, $json) === false || ! @rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write the TURN lease books at {$this->path}.");
        }
    }
}
