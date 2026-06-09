<?php

namespace App\SolarWind\Search;

use App\Enum\SearchType;

final class RawRangeExecutor extends AbstractSearchExecutor
{
    public function type(): SearchType
    {
        return SearchType::RawRange;
    }

    public function execute(SearchCriteria $criteria): SearchResult
    {
        $from = $this->require($criteria->from, 'from');
        $to = $this->require($criteria->to, 'to');
        $range = $this->rangeClause($from, $to);

        [$result, $durationMs] = $this->timed(function () use ($range) {
            $count = (int) $this->clickhouse->fetchScalar(\sprintf(
                'SELECT count() FROM %s WHERE %s',
                self::TABLE,
                $range,
            ));
            $rows = $this->clickhouse->fetchAll(\sprintf(
                'SELECT ts, speed, density, bt, bz FROM %s WHERE %s ORDER BY ts LIMIT %d',
                self::TABLE,
                $range,
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
            columns: ['ts', 'speed', 'density', 'bt', 'bz'],
            rows: array_map(static fn (array $r) => [
                'ts' => $r['ts'],
                'speed' => $r['speed'] !== null ? (float) $r['speed'] : null,
                'density' => $r['density'] !== null ? (float) $r['density'] : null,
                'bt' => $r['bt'] !== null ? (float) $r['bt'] : null,
                'bz' => $r['bz'] !== null ? (float) $r['bz'] : null,
            ], $rows),
            rowCount: $count,
            durationMs: $durationMs,
        );
    }
}
