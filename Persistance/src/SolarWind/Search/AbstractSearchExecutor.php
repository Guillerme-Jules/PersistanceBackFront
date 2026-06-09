<?php

namespace App\SolarWind\Search;

use App\SolarWind\ClickHouseClient;

abstract class AbstractSearchExecutor implements SearchExecutorInterface
{
    protected const TABLE = 'solar_wind';

    public function __construct(
        protected readonly ClickHouseClient $clickhouse,
    ) {
    }

    protected function rangeClause(?\DateTimeInterface $from, ?\DateTimeInterface $to): string
    {
        $conditions = [];
        if ($from !== null) {
            $conditions[] = 'ts >= ' . ClickHouseClient::dateTime($from);
        }
        if ($to !== null) {
            $conditions[] = 'ts <= ' . ClickHouseClient::dateTime($to);
        }

        return $conditions === [] ? '1' : implode(' AND ', $conditions);
    }

    protected function timed(callable $callback): array
    {
        $start = hrtime(true);
        $payload = $callback();
        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return [$payload, $durationMs];
    }

    protected function require(?object $value, string $name): object
    {
        return $value ?? throw new \InvalidArgumentException(\sprintf('Le paramètre "%s" est requis.', $name));
    }
}
