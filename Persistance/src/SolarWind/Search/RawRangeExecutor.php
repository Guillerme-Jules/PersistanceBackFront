<?php

namespace App\SolarWind\Search;

use App\Enum\SearchType;

final class RawRangeExecutor extends AbstractSearchExecutor implements PaginableExecutorInterface
{
    private const COLUMNS = ['ts', 'speed', 'density', 'bt', 'bz'];
    private const ORDER = 'ts';

    public function type(): SearchType
    {
        return SearchType::RawRange;
    }

    public function execute(SearchCriteria $criteria): SearchResult
    {
        $from = $this->require($criteria->from, 'from');
        $to = $this->require($criteria->to, 'to');
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
                'from' => $from->format(\DateTimeInterface::ATOM),
                'to' => $to->format(\DateTimeInterface::ATOM),
                'rowCount' => $count,
            ],
            columns: self::COLUMNS,
            rows: array_map($this->mapRow(...), $rows),
            rowCount: $count,
            durationMs: $durationMs,
        );
    }

    public function paginate(SearchCriteria $criteria, int $limit, int $offset): array
    {
        $base = $this->baseSql($criteria);

        $total = (int) $this->clickhouse->fetchScalar(\sprintf('SELECT count() FROM (%s)', $base));
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
        $from = $this->require($criteria->from, 'from');
        $to = $this->require($criteria->to, 'to');
        $range = $this->rangeClause($from, $to);

        return \sprintf(
            'SELECT ts, speed, density, bt, bz FROM %s WHERE %s',
            self::TABLE,
            $range,
        );
    }

    private function mapRow(array $r): array
    {
        return [
            'ts' => $r['ts'],
            'speed' => $r['speed'] !== null ? (float) $r['speed'] : null,
            'density' => $r['density'] !== null ? (float) $r['density'] : null,
            'bt' => $r['bt'] !== null ? (float) $r['bt'] : null,
            'bz' => $r['bz'] !== null ? (float) $r['bz'] : null,
        ];
    }
}
