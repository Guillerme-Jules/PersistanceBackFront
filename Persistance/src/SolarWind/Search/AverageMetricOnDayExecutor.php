<?php

namespace App\SolarWind\Search;

use App\Enum\SearchType;

final class AverageMetricOnDayExecutor extends AbstractSearchExecutor
{
    public function type(): SearchType
    {
        return SearchType::AverageMetricOnDay;
    }

    public function execute(SearchCriteria $criteria): SearchResult
    {
        $metric = $this->require($criteria->metric, 'metric');
        $from = $this->require($criteria->from, 'from');

        $dayStart = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $column = $metric->column();

        [$row, $durationMs] = $this->timed(fn () => $this->clickhouse->fetchRow(\sprintf(
            'SELECT round(avg(%1$s), 3) AS average, count(%1$s) AS samples,
                    round(min(%1$s), 3) AS min, round(max(%1$s), 3) AS max
             FROM %2$s
             WHERE ts >= %3$s AND ts < %4$s',
            $column,
            self::TABLE,
            \App\SolarWind\ClickHouseClient::dateTime($dayStart),
            \App\SolarWind\ClickHouseClient::dateTime($dayEnd),
        )));

        $samples = (int) ($row['samples'] ?? 0);

        return new SearchResult(
            summary: [
                'metric' => $metric->value,
                'day' => $dayStart->format('Y-m-d'),
                'average' => $samples > 0 ? (float) $row['average'] : null,
                'min' => $samples > 0 ? (float) $row['min'] : null,
                'max' => $samples > 0 ? (float) $row['max'] : null,
                'samples' => $samples,
            ],
            columns: [],
            rows: [],
            rowCount: 0,
            durationMs: $durationMs,
        );
    }
}
