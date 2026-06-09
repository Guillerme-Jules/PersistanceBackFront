<?php

namespace App\SolarWind\Search;

use App\Enum\SearchType;
use App\SolarWind\ClickHouseClient;

final class BucketAverageExecutor extends AbstractSearchExecutor
{
    public function type(): SearchType
    {
        return SearchType::BucketAverage;
    }

    public function execute(SearchCriteria $criteria): SearchResult
    {
        $metric = $this->require($criteria->metric, 'metric');
        $bucketHours = $criteria->bucketHours ?? 12;
        if ($bucketHours < 1) {
            throw new \InvalidArgumentException('bucketHours doit être >= 1.');
        }

        $column = $metric->column();
        $range = $this->rangeClause($criteria->from, $criteria->to);

        $base = \sprintf(
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

        [$result, $durationMs] = $this->timed(function () use ($base) {
            $count = (int) $this->clickhouse->fetchScalar(\sprintf('SELECT count() FROM (%s)', $base));
            $rows = $this->clickhouse->fetchAll(\sprintf(
                '%s ORDER BY bucket LIMIT %d',
                $base,
                SearchResult::PREVIEW_SIZE,
            ));

            return [$count, $rows];
        });

        [$count, $rows] = $result;

        return new SearchResult(
            summary: [
                'metric' => $metric->value,
                'bucketHours' => $bucketHours,
                'bucketCount' => $count,
            ],
            columns: ['bucket', 'average', 'samples'],
            rows: array_map(static fn (array $r) => [
                'bucket' => $r['bucket'],
                'average' => $r['average'] !== null ? (float) $r['average'] : null,
                'samples' => (int) $r['samples'],
            ], $rows),
            rowCount: $count,
            durationMs: $durationMs,
        );
    }
}
