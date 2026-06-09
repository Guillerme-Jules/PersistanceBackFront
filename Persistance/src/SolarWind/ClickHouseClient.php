<?php

namespace App\SolarWind;

use ClickHouseDB\Client;

final class ClickHouseClient
{
    private ?Client $client = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $client = new Client([
                'host' => $this->host,
                'port' => $this->port,
                'username' => $this->username,
                'password' => $this->password,
            ]);
            $client->database($this->database);
            $client->setTimeout(60);
            $this->client = $client;
        }

        return $this->client;
    }

    public function fetchAll(string $sql): array
    {
        return $this->client()->select($sql)->rows();
    }

    public function fetchScalar(string $sql): mixed
    {

        $row = $this->client()->select($sql)->fetchOne();
        if (!\is_array($row)) {
            return $row;
        }

        return $row === [] ? null : reset($row);
    }

    public function fetchRow(string $sql): ?array
    {
        $rows = $this->fetchAll($sql);

        return $rows[0] ?? null;
    }

    public function execute(string $sql): void
    {
        $this->client()->write($sql);
    }

    public function ping(): bool
    {
        try {
            return $this->client()->ping(true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function database(): string
    {
        return $this->database;
    }

    public static function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    public static function dateTime(\DateTimeInterface $dt): string
    {
        return self::quote($dt->format('Y-m-d H:i:s'));
    }
}
