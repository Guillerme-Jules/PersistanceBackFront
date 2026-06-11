<?php

namespace App\SolarWind\Search;

use App\SolarWind\ClickHouseClient;

final class ResultRowCache
{
    private const TABLE = 'search_rows';

    private bool $ensured = false;

    public function __construct(private readonly ClickHouseClient $clickhouse)
    {
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $this->clickhouse->execute(\sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                search_id String,
                idx UInt64,
                payload String,
                created DateTime DEFAULT now()
            ) ENGINE = MergeTree
            ORDER BY (search_id, idx)
            TTL created + INTERVAL 7 DAY',
            self::TABLE,
        ));

        $this->ensured = true;
    }

    public function has(string $searchId): bool
    {
        $this->ensureTable();

        return (int) $this->clickhouse->fetchScalar(\sprintf(
            'SELECT count() FROM %s WHERE search_id = %s',
            self::TABLE,
            ClickHouseClient::quote($searchId),
        )) > 0;
    }

    public function store(string $searchId, string $payloadSql): void
    {
        $this->ensureTable();

        $this->clickhouse->execute(\sprintf(
            'INSERT INTO %s (search_id, idx, payload) '
            . 'SELECT %s, rowNumberInAllBlocks(), payload FROM (%s)',
            self::TABLE,
            ClickHouseClient::quote($searchId),
            $payloadSql,
        ));
    }

    public function fetch(string $searchId, array $columns, int $limit, int $offset): array
    {
        $this->ensureTable();

        $rows = $this->clickhouse->fetchAll(\sprintf(
            'SELECT payload FROM %s WHERE search_id = %s AND idx >= %d ORDER BY idx LIMIT %d',
            self::TABLE,
            ClickHouseClient::quote($searchId),
            $offset,
            $limit,
        ));

        $out = [];
        foreach ($rows as $r) {
            $values = json_decode((string) $r['payload'], true);
            if (!\is_array($values)) {
                continue;
            }
            $out[] = \count($values) === \count($columns)
                ? array_combine($columns, $values)
                : $values;
        }

        return $out;
    }
}
