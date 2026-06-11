<?php

namespace App\SolarWind\Search;

use App\Enum\SearchType;

final class ThresholdCrossingExecutor extends AbstractSearchExecutor implements PaginableExecutorInterface
{
    private const COLUMNS = ['start', 'end', 'seconds', 'min', 'max'];
    private const ORDER = 'start';

    public function type(): SearchType
    {
        return SearchType::ThresholdCrossing;
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
        $mapped = array_map($this->mapRow(...), $rows);
        $totalSeconds = array_sum(array_map(static fn ($r) => (int) $r['seconds'], $mapped));

        return new SearchResult(
            summary: [
                'metric' => $criteria->metric?->value,
                'operator' => $criteria->operator ?? '<',
                'threshold' => $criteria->threshold,
                'intervalCount' => $count,
                'previewSeconds' => $totalSeconds,
            ],
            columns: self::COLUMNS,
            rows: $mapped,
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

    public function materializePayloadSql(SearchCriteria $criteria): string
    {
        return \sprintf(
            'SELECT toJSONString(tuple(`start`, `end`, `seconds`, `min`, `max`)) AS payload '
            . 'FROM (%s) ORDER BY `start`',
            $this->baseSql($criteria),
        );
    }

    private function baseSql(SearchCriteria $criteria): string
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
        $range = $this->rangeClause($criteria->from, $criteria->to);

        return \sprintf(
            "SELECT min(ts) AS start, max(ts) AS end, count() AS seconds,
                    round(min(%1\$s), 3) AS min, round(max(%1\$s), 3) AS max
             FROM (
                 SELECT ts, %1\$s, toUInt32(ts) - rowNumberInAllBlocks() AS grp
                 FROM (
                     SELECT ts, %1\$s
                     FROM %2\$s
                     WHERE (%4\$s) AND %1\$s %3\$s %5\$F
                     ORDER BY ts
                 )
             )
             GROUP BY grp",
            $column,
            self::TABLE,
            $operator,
            $range,
            $criteria->threshold,
        );
    }

    private function mapRow(array $r): array
    {
        return [
            'start' => $r['start'],
            'end' => $r['end'],
            'seconds' => (int) $r['seconds'],
            'min' => (float) $r['min'],
            'max' => (float) $r['max'],
        ];
    }
}
