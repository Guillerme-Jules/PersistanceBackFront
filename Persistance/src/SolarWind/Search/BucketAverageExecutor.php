<?php

namespace App\SolarWind\Search;

use App\Enum\SearchType;

final class BucketAverageExecutor extends AbstractSearchExecutor implements PaginableExecutorInterface
{
    private const COLUMNS = ['bucket', 'average', 'samples'];
    private const ORDER = 'bucket';

    public function type(): SearchType
    {
        return SearchType::BucketAverage;
    }

    public function execute(SearchCriteria $criteria): SearchResult
    {
        $base = $this->baseSql($criteria);

        [$result, $durationMs] = $this->timed(function () use ($base) {
            $count = (int) $this->clickhouse->fetchScalar(\sprintf('SELECT count() FROM (%s)', $base));
            $rows = $this->clickhouse->fetchAll(\sprintf(
                '%s ORDER BY %s LIMIT %d',
                $base,
                self::ORDER,
                SearchResult::PREVIEW_SIZE,
            ));

            return [$count, $rows];
        });

        [$count, $rows] = $result;

        return new SearchResult(
            summary: [
                'metric' => $criteria->metric?->value,
                'bucketHours' => $criteria->bucketHours ?? 12,
                'bucketCount' => $count,
            ],
            columns: self::COLUMNS,
            rows: array_map($this->mapRow(...), $rows),
            rowCount: $count,
            durationMs: $durationMs,
        );
    }

    public function paginate(SearchCriteria $criteria, int $limit, int $offset, bool $withTotal = true): array
    {
        $base = $this->baseSql($criteria);

        // Le total relance toute la requête de base : on ne le calcule que si demandé.
        $total = $withTotal
            ? (int) $this->clickhouse->fetchScalar(\sprintf('SELECT count() FROM (%s)', $base))
            : null;
        $rows = $this->clickhouse->fetchAll(\sprintf(
            '%s ORDER BY %s LIMIT %d OFFSET %d',
            $base,
            self::ORDER,
            $limit,
            $offset,
        ));

        return [
            'columns' => self::COLUMNS,
            'rows' => array_map($this->mapRow(...), $rows),
            'total' => $total,
        ];
    }

    private function baseSql(SearchCriteria $criteria): string
    {
        $metric = $this->require($criteria->metric, 'metric');
        $bucketHours = $criteria->bucketHours ?? 12;
        if ($bucketHours < 1) {
            throw new \InvalidArgumentException('bucketHours doit être >= 1.');
        }

        $column = $metric->column();
        $range = $this->rangeClause($criteria->from, $criteria->to);

        return \sprintf(
            'SELECT toStartOfInterval(ts, INTERVAL %1$d HOUR) AS bucket,
                    round(avg(%2$s), 3) AS average, count(%2$s) AS samples
             FROM %3$s
             WHERE %4$s
             GROUP BY bucket',
            $bucketHours,
            $column,
            self::TABLE,
            $range,
        );
    }

    private function mapRow(array $r): array
    {
        return [
            'bucket' => $r['bucket'],
            'average' => $r['average'] !== null ? (float) $r['average'] : null,
            'samples' => (int) $r['samples'],
        ];
    }
}
