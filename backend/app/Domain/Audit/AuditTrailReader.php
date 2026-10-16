<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Support\ApiException;
use App\Domain\Support\Presenter;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Reads the company's audit trail (`remote_audit_logs`) for the three screens
 * that show it: the list, one record's history, and the CSV export.
 *
 * Every query is scoped to one company here, in one place, so no caller can
 * forget the `company_id` predicate. Permission is the controller's job.
 */
final class AuditTrailReader
{
    /** Rows in one CSV download. A trail longer than this is cut and says so. */
    public const EXPORT_CAP = 10000;

    private const CSV_HEADER = [
        'When (UTC)', 'Severity', 'Event', 'Summary', 'Actor', 'Actor ID', 'Entity type', 'Entity ID',
        'Entity', 'Session', 'Product', 'IP', 'Request ID', 'Changes',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param  array<string, mixed> $filters from, to, event|action, severity, entity_type, entity_id,
     *                                       entity_label, actor_uuid, session_uuid, q
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function search(int $companyId, array $filters, int $limit, int $offset): array
    {
        $builder = $this->base($companyId, $filters);
        $total   = (clone $builder)->countAllResults(false);
        $rows    = $builder->orderBy('a.created_at', 'DESC')->orderBy('a.id', 'DESC')
            ->limit($limit, $offset)->get()->getResultArray();

        return [array_map(static fn (array $row) => Presenter::auditEntry($row), $rows), $total];
    }

    /**
     * Everything one record has been through, oldest last.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function history(int $companyId, string $entityType, string $entityId, int $limit, int $offset): array
    {
        return $this->search($companyId, ['entity_type' => $entityType, 'entity_id' => $entityId], $limit, $offset);
    }

    /**
     * The filtered trail as CSV text.
     *
     * @param  array<string, mixed> $filters
     * @return array{csv: string, rows: int, partial: bool}
     */
    public function exportCsv(int $companyId, array $filters): array
    {
        $builder = $this->base($companyId, $filters);
        $rows    = $builder->orderBy('a.created_at', 'DESC')->orderBy('a.id', 'DESC')
            ->limit(self::EXPORT_CAP + 1)->get()->getResultArray();

        $partial = count($rows) > self::EXPORT_CAP;
        $rows    = array_slice($rows, 0, self::EXPORT_CAP);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::CSV_HEADER, ',', '"', '');

        foreach ($rows as $row) {
            $entry = Presenter::auditEntry($row);

            fputcsv($handle, array_map([self::class, 'cell'], [
                $entry['createdAt'], $entry['severity'], $entry['event'], $entry['summary'],
                $entry['actorName'] ?? '', $entry['actorUuid'] ?? '', $entry['entityType'] ?? '',
                $entry['entityId'] ?? '', $entry['entityLabel'] ?? '', $entry['sessionUuid'] ?? '',
                $entry['sourceProduct'] ?? '', $entry['ip'] ?? '', $entry['requestId'] ?? '',
                self::describeChanges($entry['metadata']),
            ]), ',', '"', '');
        }

        if ($partial) {
            fputcsv($handle, [self::cell('PARTIAL: the trail has more rows than the ' . self::EXPORT_CAP . ' exported; narrow the filters.')], ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return ['csv' => $csv, 'rows' => count($rows), 'partial' => $partial];
    }

    /**
     * A cell a spreadsheet will read as text, never as a formula.
     */
    public static function cell(mixed $value): string
    {
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '');

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'" . $text : $text;
    }

    /** @param array<string, mixed> $metadata */
    private static function describeChanges(array $metadata): string
    {
        $parts = [];

        foreach ((array) ($metadata['changes'] ?? []) as $change) {
            if (is_array($change) && isset($change['field'])) {
                $parts[] = sprintf(
                    '%s: %s -> %s',
                    $change['label'] ?? $change['field'],
                    self::show($change['old'] ?? null),
                    self::show($change['new'] ?? null),
                );
            }
        }

        return implode('; ', $parts);
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null  => '(none)',
            is_bool($value)  => $value ? 'yes' : 'no',
            default          => (string) $value,
        };
    }

    /** @param array<string, mixed> $filters */
    private function base(int $companyId, array $filters): BaseBuilder
    {
        $builder = $this->db->table('remote_audit_logs a')
            ->select('a.*, COALESCE(NULLIF(i.display_name, \'\'), a.actor_label) AS actor_name')
            ->join('remote_identities i', 'i.id = a.actor_user_id', 'left')
            ->where('a.company_id', $companyId);

        $exact = [
            'event'        => 'a.event',
            'severity'     => 'a.severity',
            'entity_type'  => 'a.entity_type',
            'entity_id'    => 'a.entity_id',
            'actor_uuid'   => 'a.actor_uuid',
            'session_uuid' => 'a.session_uuid',
        ];
        foreach ($exact as $key => $column) {
            $value = $this->text($filters[$key] ?? null);
            if ($value === null) {
                continue;
            }
            $builder->where($column, in_array($key, ['event', 'severity'], true) ? $this->fold($key, $value) : $value);
        }

        if (($label = $this->text($filters['entity_label'] ?? null)) !== null) {
            $builder->like('a.entity_label', $label, 'both', null, true);
        }

        if (($q = $this->text($filters['q'] ?? null)) !== null) {
            $builder->groupStart()
                ->like('a.summary', $q, 'both', null, true)
                ->orLike('a.entity_label', $q, 'both', null, true)
                ->orLike('a.event', $q, 'both', null, true)
                ->orLike('a.actor_label', $q, 'both', null, true)
                ->groupEnd();
        }

        if (($from = $this->text($filters['from'] ?? null)) !== null) {
            $builder->where('a.created_at >=', $this->moment($from, 'from', false));
        }
        if (($to = $this->text($filters['to'] ?? null)) !== null) {
            // A bare date means "through the end of that day".
            $builder->where('a.created_at <', $this->moment($to, 'to', true));
        }

        return $builder;
    }

    private function fold(string $key, string $value): string
    {
        return $key === 'event' ? strtoupper($value) : strtolower($value);
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 200);
    }

    private function moment(string $value, string $field, bool $endExclusive): string
    {
        $timestamp = strtotime($value . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? ' 00:00:00 UTC' : ''));
        if ($timestamp === false) {
            throw ApiException::badRequest('VALIDATION_FAILED', 'Some of the details sent were not valid.', [
                'fields' => [$field => 'Use a date such as 2026-10-16.'],
            ]);
        }

        if ($endExclusive && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $timestamp += 86400;
        }

        return gmdate('Y-m-d H:i:sP', $timestamp);
    }
}
