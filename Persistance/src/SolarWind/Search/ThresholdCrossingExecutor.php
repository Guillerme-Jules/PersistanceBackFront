<?php

namespace App\SolarWind\Search;

use App\Enum\SearchType;

final class ThresholdCrossingExecutor extends AbstractSearchExecutor
{
    public function type(): SearchType
    {
        return SearchType::ThresholdCrossing;
    }

    public function execute(SearchCriteria $criteria): SearchResult
    {
        $metric = $this->require($criteria->metric, 'metric');
        $operator = $criteria->operator ?? '<';
        if (!\in_array($operator, ['<', '<=', '>', '>=', '='], true)) {
            throw new \InvalidArgumentException('Opérateur invalide.');
        }
        if ($criteria->threshold === null) {
            throw new \InvalidArgumentException('Le paramètre "threshold" est requis.');
        }

        $column = $metric->column();
        $threshold = $criteria->threshold;
        $range = $this->rangeClause($criteria->from, $criteria->to);

        $islands = \sprintf(
            "SELECT min(ts) AS start, max(ts) AS end, count() AS seconds,
                    round(min(%1\$s), 3) AS min, round(max(%1\$s), 3) AS max
             FROM (
                 SELECT ts, %1\$s,
                        toUInt32(ts) - row_number() OVER (ORDER BY ts) AS grp
                 FROM %2\$s
                 WHERE (%4\$s) AND %1\$s %3\$s %5\$F
             )
             GROUP BY grp",
            $column,
            self::TABLE,
            $operator,
            $range,
            $threshold,
        );

        [$result, $durationMs] = $this->timed(function () use ($islands) {
            $count = (int) $this->clickhouse->fetchScalar(\sprintf('SELECT count() FROM (%s)', $islands));
            $rows = $this->clickhouse->fetchAll(\sprintf(
                '%s ORDER BY start LIMIT %d',
                $islands,
                SearchResult::PREVIEW_SIZE,
            ));

            return [$count, $rows];
        });

        [$count, $rows] = $result;
        $totalSeconds = array_sum(array_map(static fn ($r) => (int) $r['seconds'], $rows));

        return new SearchResult(
            summary: [
                'metric' => $metric->value,
                'operator' => $operator,
                'threshold' => $threshold,
                'intervalCount' => $count,
                'previewSeconds' => $totalSeconds,
            ],
            columns: ['start', 'end', 'seconds', 'min', 'max'],
            rows: array_map(static fn (array $r) => [
                'start' => $r['start'],
                'end' => $r['end'],
                'seconds' => (int) $r['seconds'],
                'min' => (float) $r['min'],
                'max' => (float) $r['max'],
            ], $rows),
            rowCount: $count,
            durationMs: $durationMs,
        );
    }
}
